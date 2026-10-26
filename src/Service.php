<?php

namespace Sayamaapps\Fude;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Content\Version;
use Kirby\Form\Form;
use Kirby\Http\Request\Auth\BasicAuth;
use Kirby\Http\Response;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuids;
use Throwable;

/**
 * The four routes under /fude/*:
 *
 *   GET  /fude/schema   what this site allows (declaration + current entries)
 *   POST /fude/draft    {collection, action, fields, key?, actor?} + upload "image"
 *                       → a Kirby `changes` version (or a draft page), a
 *                         draft id, a confirmation token and a preview URL
 *   POST /fude/publish  {id, token, actor?}  → the changes go live
 *   POST /fude/discard  {id, token, actor?}  → the changes are removed
 *
 * Off by default: without `fude.active` the routes answer with Kirby's real
 * 404. Login via Basic Auth against a Kirby account with the role `fude`
 * (or a role from `fude.roles`), HTTPS only — `fude.withoutHttps` is for
 * local tests. Every call is logged (src/Log.php).
 *
 * Nothing here writes the published (`latest`) version directly. A draft
 * is a `changes` version; only /fude/publish, with the token from the
 * draft and an unchanged published state (hash), copies it over — through
 * Kirby's own Version::publish(), which validates against the blueprint
 * once more.
 */
final class Service
{
	public const FORMAT = 1;

	/** A replace's JSON string, as sent by a multipart form: size and nesting limit. */
	private const MAX_ENTRIES_JSON_BYTES = 65536;
	private const MAX_ENTRIES_JSON_DEPTH = 4;

	/** Most rows one replace may carry, with or without maxEntries. */
	public const MAX_REPLACE_ENTRIES = 100;
	public const ROLE = 'fude';
	public const ROUTES = ['schema', 'draft', 'publish', 'discard'];

	/** Who spoke on the channel: a short tag like "telegram:123" — for the log only. */
	private static function actor(array $input): string|null
	{
		$actor = $input['actor'] ?? null;
		if ($actor === null || $actor === '') {
			return null;
		}
		if (!is_string($actor) || mb_strlen($actor) > 120 || preg_match('/[\x00-\x1f]/', $actor)) {
			throw FudeError::t('request.actor', [], 400);
		}
		return $actor;
	}

	/** Whether the request carries `X-Forwarded-Proto: https` (any trust decision aside). */
	public static function announcesHttps(App $kirby): bool
	{
		$proto = $kirby->environment()->get('HTTP_X_FORWARDED_PROTO');
		return is_string($proto) && strtolower(trim($proto)) === 'https';
	}

	/**
	 * Basic Auth against a Kirby account with an allowed role (default: the
	 * bundled role `fude`). HTTPS only (see connectionIsSecure());
	 * `fude.withoutHttps` is for local tests. Kirby's own password check, with
	 * its lockout after failed attempts, without the global option api.basicAuth.
	 *
	 * @throws FudeError
	 */
	public static function authenticate(App $kirby): User
	{
		$request = $kirby->request();
		if (!self::connectionIsSecure($kirby) && Options::get($kirby, 'withoutHttps', false) !== true) {
			// Says why, so an operator behind a proxy knows which option to set.
			throw FudeError::t(
				self::announcesHttps($kirby) ? 'auth.httpsProxy' : 'auth.https',
				[],
				403
			);
		}
		$auth = $request->auth();
		if (!$auth instanceof BasicAuth) {
			throw FudeError::t('auth.required', [], 401);
		}
		try {
			$user = $kirby->auth()->validatePassword((string)$auth->username(), (string)$auth->password());
		} catch (Throwable) {
			throw FudeError::t('auth.failed', [], 401);
		}
		$roles = array_map('strval', (array)Options::get($kirby, 'roles', [self::ROLE]));
		if (!in_array($user->role()->name(), $roles, true)) {
			throw FudeError::t('auth.role', [], 403);
		}
		return $user;
	}

	/**
	 * Fingerprint of the changes version as the draft left it: the WHOLE
	 * content, for structure and pages alike. A Panel edit of any other field
	 * in between (the same page, another field) changes it, and publish would
	 * otherwise publish that edit unconfirmed.
	 */
	private static function changesHash(Version $version): string
	{
		$content = $version->read() ?? [];
		unset($content['lock']);
		return hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Runs the finished entry through the page's own form, so the blueprint
	 * (required, maxlength, options, nested structure fields) has the last
	 * word — the same checks the Panel applies.
	 *
	 * @throws FudeError 422 with the field labels and messages
	 */
	private static function checkBlueprint(Page $page, array $input): void
	{
		$form = Form::for($page);
		$form->submit($input);
		$errors = $form->errors();
		if ($errors === []) {
			return;
		}
		$parts = [];
		foreach ($errors as $name => $error) {
			$messages = is_array($error['message'] ?? null) ? implode(' ', array_map('strval', $error['message'])) : (string)($error['message'] ?? '');
			$parts[] = ($error['label'] ?? $name) . ': ' . $messages;
		}
		throw FudeError::t('fields.blueprint', ['errors' => implode(' · ', $parts)]);
	}

	/**
	 * A draft needs a page nobody else is working on: no active Panel lock,
	 * and no unsaved Panel changes (they would be published together with
	 * ours — the editor would never have confirmed that).
	 */
	private static function checkEditable(Page $page): void
	{
		self::checkLock($page);
		$changes = $page->version('changes');
		if ($changes->exists() && !$changes->isIdentical('latest')) {
			throw FudeError::t('draft.pending', [], 423);
		}
	}

	private static function checkHash(App $kirby, array $collection, array $record, Page|null $target): void
	{
		$current = Content::hash($kirby, $collection, $target);
		if (!hash_equals((string)$record['hash'], $current)) {
			throw FudeError::t('draft.conflict', [], 409, ['hash' => $current]);
		}
	}

	/**
	 * No entry but $except has the key value yet. An update must not give an
	 * entry the key of another one, nor may publish make two of them: neither
	 * could be named by its key afterwards.
	 *
	 * @throws FudeError 409
	 */
	private static function checkKeyFree(array $collection, array $rows, string $value, int|null $except): void
	{
		$keyField = $collection['key'];
		foreach ($rows as $index => $row) {
			if ($index !== $except && Content::sameKey($row[$keyField] ?? '', $value)) {
				throw FudeError::t('collection.keyTaken', ['field' => $collection['fields'][$keyField]['label'], 'key' => trim($value)], 409);
			}
		}
	}

	private static function checkLock(Page $page): void
	{
		if ($page->lock()->isLocked()) {
			throw FudeError::t('draft.locked', [], 423);
		}
	}

	/**
	 * A value for a field the blueprint hides on this entry (`when`) would
	 * change nothing anyone sees: the Panel hides the field, and the site
	 * goes by the condition (a price on a free event). Refused, naming the
	 * condition. The entry is its stored content with the draft's values on
	 * top; a field it does not store has its blueprint default. An emptied
	 * field passes.
	 *
	 * @param array<string, mixed> $stored the entry as stored, [] for a new one
	 * @param array<string, string> $values the draft's validated values
	 * @throws FudeError 422
	 */
	private static function checkShown(array $collection, array $stored, array $values): void
	{
		foreach ($values as $name => $value) {
			if ($value === '') {
				continue;
			}
			foreach ($collection['fields'][$name]['shownWhen'] ?? [] as $other => $condition) {
				$actual = $values[$other] ?? $stored[$other] ?? null;
				$actual = $actual === null ? $condition['default'] : (is_bool($actual) ? ($actual ? 'true' : 'false') : (is_scalar($actual) ? (string)$actual : ''));
				if ($actual === '' && $condition['toggle']) {
					$actual = 'false';
				}
				if ($actual === $condition['value']) {
					continue;
				}
				$params = ['field' => $collection['fields'][$name]['label'], 'other' => $condition['label'], 'value' => $condition['value']];
				throw FudeError::t(isset($collection['fields'][$other]) ? 'fields.hidden' : 'fields.hiddenFixed', $params);
			}
		}
	}

	// --- Helpers -------------------------------------------------------------

	/** @throws FudeError */
	private static function collection(array $collections, mixed $name): array
	{
		if (!is_string($name) || !isset($collections[$name])) {
			throw FudeError::t('collection.unknown', ['name' => is_scalar($name) ? (string)$name : '?'], 404);
		}
		return $collections[$name];
	}

	/**
	 * Whether this request arrived over TLS. Decided by the connection, not by
	 * Kirby's `url` option: with a fixed `url`, Kirby takes the scheme from it,
	 * so a plain request to an https site would look secure.
	 *
	 * Direct: the server's own HTTPS flag, or port 443. Behind a reverse proxy
	 * that terminates TLS, only when the connection comes from an address in
	 * `fude.trustedProxies` and carries `X-Forwarded-Proto: https`. Any other
	 * sender's header is ignored.
	 */
	public static function connectionIsSecure(App $kirby): bool
	{
		$env = $kirby->environment();
		$https = $env->get('HTTPS');
		if ($https !== null && !in_array(strtolower((string)$https), ['', 'off', '0', 'false', '-1'], true)) {
			return true;
		}
		if ((int)$env->get('SERVER_PORT') === 443) {
			return true;
		}
		return self::fromTrustedProxy($kirby) && self::announcesHttps($kirby);
	}

	/**
	 * What a model stores in the given versions, per version and language
	 * (every language of a multi-language site); a missing version is [].
	 *
	 * @param list<string> $versions
	 * @return list<array<string, mixed>>
	 */
	private static function contents(ModelWithContent $model, array $versions): array
	{
		$kirby = $model->kirby();
		$languages = $kirby->multilang() ? $kirby->languages()->values() : ['default'];
		$contents = [];
		foreach ($versions as $version) {
			foreach ($languages as $language) {
				$contents[] = $model->version($version)->read($language) ?? [];
			}
		}
		return $contents;
	}

	/** @SuppressWarnings(PHPMD.UnusedFormalParameter) the time stays in the signature shared with draft() and publish(). */
	private static function discard(App $kirby, array $collections, array $input, DateTimeImmutable $now, array &$entry): array
	{
		$record = Drafts::read($kirby, (string)($input['id'] ?? ''));
		$entry += ['draft' => $record['id'], 'collection' => $record['collection'], 'action' => $record['action'], 'key' => $record['key']];
		Drafts::checkToken($record, $input['token'] ?? null);
		$collection = self::collection($collections, $record['collection']);
		$kept = false;   // a Panel editor's work on top of the draft stays, so does a draft the Panel used
		$removed = false; // our draft's changes version went: its file may go with it

		if ($record['kind'] === 'structure') {
			$page = Content::page($kirby, $collection);
			$changes = $page->version('changes');
			if ($changes->exists()) {
				// Only our own draft goes; a Panel editor's work on top stays.
				if (hash_equals($record['changesHash'], self::changesHash($changes))) {
					$changes->delete();
					$removed = true;
				} else {
					$kept = true;
				}
			}
			// No changes version: the draft was published in the Panel, and
			// its image is live now. It stays.
			$kept = self::removeFile($page, $record, $removed) || $kept;
		} elseif ($record['kind'] === 'page') {
			$page = Content::page($kirby, $collection);
			$changes = $page->version('changes');
			if ($changes->exists()) {
				// Only our own draft goes; a Panel editor's work on top stays.
				if (hash_equals($record['changesHash'], self::changesHash($changes))) {
					$changes->delete();
				} else {
					$kept = true;
				}
			}
		} else {
			$child = $kirby->page($record['page']);
			if ($child instanceof Page) {
				if ($record['action'] === 'create') {
					// Draft status alone is no licence to delete: a Panel editor
					// may have worked on the page since. Then it stays.
					if ($child->isDraft() && self::isUntouched($child, $record)) {
						$child->delete(true);
					} else {
						$kept = true;
					}
				} elseif ($record['action'] === 'update') {
					$changes = $child->version('changes');
					if ($changes->exists()) {
						if (hash_equals($record['changesHash'], self::changesHash($changes))) {
							$changes->delete();
							$removed = true;
						} else {
							$kept = true;
						}
					}
					$kept = self::removeFile($child, $record, $removed) || $kept;
				}
			}
		}
		Drafts::delete($kirby, $record['id']);
		return ['id' => $record['id'], 'kept' => $kept];
	}

	private static function draft(App $kirby, array $collections, array $input, DateTimeImmutable $now, array &$entry): array
	{
		$collection = self::collection($collections, $input['collection'] ?? null);
		$entry['collection'] = $collection['name'];
		$action = $input['action'] ?? 'create';
		if (!is_string($action) || !in_array($action, $collection['actions'], true)) {
			// A non-text action is a 422 too, not an "Array to string" 500.
			throw FudeError::t('collection.action', ['name' => $collection['name'], 'action' => is_scalar($action) ? (string)$action : get_debug_type($action)]);
		}
		$entry['action'] = $action;
		$fields = $input['fields'] ?? [];
		if (is_string($fields)) {
			$fields = json_decode($fields, true);
			if (!is_array($fields)) {
				throw FudeError::t('request.fieldsJson');
			}
		}
		if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
			throw FudeError::t('fields.object');
		}
		// The date a touch sets: today, or the day the draft names (editable touch only).
		$touch = self::touch($kirby, $collection, $input['touch'] ?? null, $now);
		$upload = self::upload($kirby);
		$entries = null;
		if ($action === 'delete') {
			// The key says which entry; anything else would be a second, unasked change.
			if ($fields !== [] || $upload !== null) {
				throw FudeError::t('collection.deleteFields');
			}
			$clean = [];
		} elseif ($action === 'replace') {
			// The list is the whole content: no fields, no image, every row checked like a create.
			if ($fields !== []) {
				throw FudeError::t('collection.replaceFields');
			}
			if ($upload !== null) {
				throw FudeError::t('collection.replaceImage');
			}
			$clean = [];
			$entries = self::entries($collection, $input['entries'] ?? null, self::today($kirby, $now));
		} else {
			$clean = Validator::check($collection, $fields, $action, self::today($kirby, $now));
		}

		$imageField = null;
		foreach ($collection['fields'] as $name => $rule) {
			if ($rule['type'] === 'image') {
				$imageField = $name;
				break;
			}
		}
		if ($upload !== null && $imageField === null) {
			throw FudeError::t('image.unexpected');
		}
		$format = $upload !== null ? Images::check($kirby, $upload, $collection['fields'][$imageField]) : null;
		if (in_array($action, ['create', 'update'], true) && $clean === [] && $upload === null) {
			throw FudeError::t('fields.empty');
		}

		$page = Content::page($kirby, $collection);
		$rows = Content::rows($kirby, $collection);
		$keyField = $collection['key'];
		$keyLabel = $keyField === null ? '' : $collection['fields'][$keyField]['label'];
		$index = null;
		if ($collection['kind'] === 'page') {
			$entry['key'] = null; // the page itself: no entry to name
		} elseif ($action === 'replace') {
			$entry['key'] = null; // the whole list, no single entry
		} elseif ($action === 'create') {
			foreach ($rows as $row) {
				if (Content::sameKey($row[$keyField] ?? '', $clean[$keyField])) {
					throw FudeError::t('collection.exists', ['field' => $keyLabel, 'key' => $clean[$keyField]], 409);
				}
			}
			$entry['key'] = $clean[$keyField];
		} else {
			// update and delete both name an existing entry.
			$key = $input['key'] ?? null;
			if (!is_string($key) || trim($key) === '') {
				throw FudeError::t($action === 'delete' ? 'collection.deleteKey' : 'collection.key', ['field' => $keyLabel]);
			}
			$index = Content::find($collection, $rows, $key);
			$entry['key'] = trim($key);
			if ($action === 'update' && isset($clean[$keyField])) {
				self::checkKeyFree($collection, $rows, $clean[$keyField], $index);
			}
		}

		if ($action === 'replace') {
			foreach ($entries as $row) {
				$published = array_values(array_filter($rows, fn ($old) => Content::sameKey($old[$keyField] ?? '', $row[$keyField])));
				self::checkShown($collection, $published[0] ?? [], $row);
			}
		} elseif ($action !== 'delete') {
			self::checkShown($collection, self::storedEntry($kirby, $collection, $rows, $index), $clean);
		}

		if ($collection['kind'] === 'page') {
			return self::draftSinglePage($kirby, $collection, $clean, $touch, $now, $entry);
		}
		return $collection['kind'] === 'structure'
			? self::draftStructure($kirby, $collection, $page, $rows, $index, $clean, $upload, $format, $imageField, $action, $entries, $touch, $now, $entry)
			: self::draftPage($kirby, $collection, $page, $rows, $index, $clean, $upload, $format, $imageField, $action, $now, $entry);
	}

	private static function draftPage(App $kirby, array $collection, Page $parent, array $rows, int|null $index, array $clean, array|null $upload, string|null $format, string|null $imageField, string $action, DateTimeImmutable $now, array &$entry): array
	{
		$file = null;
		if ($action === 'create') {
			$hash = Content::hash($kirby, $collection);
			$content = self::stored($kirby, $collection, $clean);
			$child = null;
			try {
				$child = Page::create([
					'parent'   => $parent,
					'slug'     => Str::slug($clean['title']) . '-' . bin2hex(random_bytes(2)),
					'template' => $collection['template'],
					'content'  => $content,
					'isDraft'  => true,
				]);
				if ($upload !== null) {
					$file = self::storeImage($kirby, $child, $collection, $upload, $format, $imageField, $content);
					$content[$imageField] = [self::fileReference($file)];
					$child = $child->update([$imageField => $content[$imageField]]);
				}
				self::checkBlueprint($child, $content);
			} catch (Throwable $e) {
				$child?->delete(true);
				throw $e;
			}
			$record = Drafts::create($kirby, [
				'collection'  => $collection['name'],
				'kind'        => 'pages',
				'action'      => 'create',
				'page'        => $child->id(),
				'key'         => $entry['key'],
				'index'       => null,
				'fields'      => $clean,
				'file'        => $file?->filename(),
				'filesHash'   => self::filesHash($child->files()),
				'hash'        => $hash,
				'changesHash' => self::changesHash($child->version('latest')),
				'preview'     => $child->version('latest')->url(),
				'user'        => $entry['user'],
				'actor'       => $entry['actor'],
			], $now);
			$entry['draft'] = $record['id'];
			return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
		}

		$child = $kirby->page($rows[$index]['_id']);
		if (!$child instanceof Page) {
			throw FudeError::t('collection.page', ['name' => $collection['name']], 500);
		}
		self::checkEditable($child);
		$hash = Content::hash($kirby, $collection, $child);
		if ($action === 'delete') {
			// Nothing is touched before publish: the page stays until then,
			// and the draft only names it.
			$record = Drafts::create($kirby, [
				'collection'  => $collection['name'],
				'kind'        => 'pages',
				'action'      => 'delete',
				'page'        => $child->id(),
				'uuid'        => self::pageUuid($child),
				'key'         => $entry['key'],
				'index'       => $index,
				'fields'      => [],
				'file'        => null,
				'filesHash'   => self::filesHash($child->files()),
				'hash'        => $hash,
				'changesHash' => null,
				'preview'     => $child->version('latest')->url(),
				'user'        => $entry['user'],
				'actor'       => $entry['actor'],
			], $now);
			$entry['draft'] = $record['id'];
			return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
		}
		$content = self::stored($kirby, $collection, $clean);
		if ($upload !== null) {
			$file = self::storeImage($kirby, $child, $collection, $upload, $format, $imageField, $rows[$index] + $clean);
			$content[$imageField] = [self::fileReference($file)];
		}
		try {
			$changes = $child->version('changes');
			$base = $changes->exists() ? [] : ($child->version('latest')->read() ?? []);
			self::checkBlueprint($child, [...$base, ...$content]);
			$changes->save([...$base, ...$content]);
		} catch (Throwable $e) {
			$file?->delete();
			throw $e;
		}
		$record = Drafts::create($kirby, [
			'collection'  => $collection['name'],
			'kind'        => 'pages',
			'action'      => 'update',
			'page'        => $child->id(),
			'uuid'        => self::pageUuid($child),
			'key'         => $entry['key'],
			'index'       => $index,
			'fields'      => $clean,
			'file'        => $file?->filename(),
			'fileHash'    => $file === null ? null : self::filesHash([$file]),
			'hash'        => $hash,
			'changesHash' => self::changesHash($child->version('changes')),
			'preview'     => $child->version('changes')->url(),
			'user'        => $entry['user'],
			'actor'       => $entry['actor'],
		], $now);
		$entry['draft'] = $record['id'];
		return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
	}

	/**
	 * kind page: the declared fields (and the touch date, if declared) go
	 * into the page's changes version, which carries the WHOLE page — like a
	 * structure draft, with no key and no rows.
	 */
	private static function draftSinglePage(App $kirby, array $collection, array $clean, array $touched, DateTimeImmutable $now, array &$entry): array
	{
		$page = Content::page($kirby, $collection);
		self::checkEditable($page);
		$hash = Content::hash($kirby, $collection, $page);

		$changes = $page->version('changes');
		$base = $changes->exists() ? [] : ($page->version('latest')->read() ?? []);
		$full = [...$base, ...self::stored($kirby, $collection, $clean), ...$touched];
		self::checkBlueprint($page, $full);
		$changes->save($full);

		$record = Drafts::create($kirby, [
			'collection'  => $collection['name'],
			'kind'        => 'page',
			'action'      => 'update',
			'page'        => $page->id(),
			'key'         => null,
			'index'       => null,
			'fields'      => $clean,
			'touch'       => self::touchRecord($touched),
			'file'        => null,
			'hash'        => $hash,
			'changesHash' => self::changesHash($changes),
			'preview'     => $changes->url(),
			'user'        => $entry['user'],
			'actor'       => $entry['actor'],
		], $now);
		$entry['draft'] = $record['id'];
		return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
	}

	private static function draftStructure(App $kirby, array $collection, Page $page, array $rows, int|null $index, array $clean, array|null $upload, string|null $format, string|null $imageField, string $action, array|null $entries, array $touched, DateTimeImmutable $now, array &$entry): array
	{
		$field = $collection['field'];
		self::checkEditable($page);
		$hash = Content::hash($kirby, $collection);

		// Checked before anything is stored: a refused draft leaves no file behind.
		if (isset($collection['maxEntries']) && in_array($action, ['create', 'replace'], true)) {
			$total = $action === 'replace' ? count($entries) : count($rows) + ($index === null ? 1 : 0);
			if ($total > $collection['maxEntries']) {
				throw FudeError::t('collection.maxEntries', ['max' => $collection['maxEntries']]);
			}
		}

		$file = null;
		$position = $index; // where the row sits after the draft; null = appended
		$replace = null;    // what a replace changes, for the reply and the record
		if ($action === 'delete') {
			// The row leaves the list. Its image stays on the page, as with an
			// update: removing files is the editor's decision in the Panel.
			unset($rows[$index]);
			$rows = array_values($rows);
		} elseif ($action === 'replace') {
			$stored = array_map(fn (array $row) => self::stored($kirby, $collection, $row), $entries);
			$merged = self::replaced($rows, $stored, $collection['key'], $imageField, $collection['fields']);
			$rows = $merged['rows'];
			$replace = $merged['summary'];
		} else {
			$row = $index === null ? [] : $rows[$index];
			foreach (self::stored($kirby, $collection, $clean) as $name => $value) {
				$row[$name] = $value;
			}
			if ($upload !== null) {
				$file = self::storeImage($kirby, $page, $collection, $upload, $format, $imageField, $row);
				$row[$imageField] = [self::fileReference($file)];
			}
			if ($index === null && ($collection['insert'] ?? 'bottom') === 'top') {
				array_unshift($rows, $row);
				$position = 0;
			} elseif ($index === null) {
				$rows[] = $row;
			} else {
				$rows[$index] = $row;
			}
		}

		try {
			self::checkBlueprint($page, [$field => $rows, ...$touched]);
			$changes = $page->version('changes');
			// A changes version must carry the WHOLE content: publish() sets
			// every field missing from it to null. The Panel does the same.
			$base = $changes->exists() ? [] : ($page->version('latest')->read() ?? []);
			$changes->save([...$base, $field => $rows, ...$touched]);
		} catch (Throwable $e) {
			// Nothing half-done: the uploaded file goes again.
			$file?->delete();
			throw $e;
		}

		$record = Drafts::create($kirby, [
			'collection'  => $collection['name'],
			'kind'        => 'structure',
			'action'      => $action,
			'page'        => $page->id(),
			'key'         => $entry['key'],
			'index'       => $action === 'replace' ? null : ($position ?? count($rows) - 1),
			'fields'      => $clean,
			'replace'     => $replace,
			'touch'       => self::touchRecord($touched),
			'file'        => $file?->filename(),
			'fileHash'    => $file === null ? null : self::filesHash([$file]),
			'hash'        => $hash,
			'changesHash' => self::changesHash($page->version('changes')),
			'preview'     => $page->version('changes')->url(),
			'user'        => $entry['user'],
			'actor'       => $entry['actor'],
		], $now);
		$entry['draft'] = $record['id'];
		return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
	}

	/**
	 * What the site and every page except this one store (drafts included,
	 * published and unsaved Panel changes, every language), one text per
	 * model; null for one that could not be read.
	 *
	 * @return list<string|null>
	 */
	private static function elsewhere(Page $page): array
	{
		$site = $page->site();
		$texts = [self::storedText($site)];
		foreach ($site->index(true) as $other) {
			if ($other->id() !== $page->id()) {
				$texts[] = self::storedText($other);
			}
		}
		return $texts;
	}

	/**
	 * The rows of a replace: a JSON list (or its string form from a multipart
	 * form). Each row is checked like a create, and no two rows share a key.
	 *
	 * @return list<array<string, string>>
	 * @throws FudeError
	 */
	private static function entries(array $collection, mixed $entries, string $today): array
	{
		if (is_string($entries)) {
			// Size and depth first: decoding a huge string is the expensive part.
			if (strlen($entries) > self::MAX_ENTRIES_JSON_BYTES) {
				throw FudeError::t('collection.entries');
			}
			$entries = json_decode($entries, true, self::MAX_ENTRIES_JSON_DEPTH);
		}
		if (!is_array($entries) || !array_is_list($entries)) {
			throw FudeError::t('collection.entries');
		}
		// The count is refused before any row is validated.
		if (count($entries) > self::MAX_REPLACE_ENTRIES) {
			throw FudeError::t('collection.maxEntries', ['max' => self::MAX_REPLACE_ENTRIES]);
		}
		$keyField = $collection['key'];
		$rows = [];
		foreach ($entries as $entry) {
			if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
				throw FudeError::t('collection.entries');
			}
			$row = Validator::check($collection, $entry, 'create', $today);
			foreach ($rows as $other) {
				if (Content::sameKey($other[$keyField], $row[$keyField])) {
					throw FudeError::t('collection.duplicate', ['field' => $collection['fields'][$keyField]['label'], 'key' => $row[$keyField]]);
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** How a files field stores a reference: UUID when enabled, else the file id. */
	private static function fileReference(File $file): string
	{
		return Uuids::enabled() ? $file->uuid()->toString() : $file->id();
	}

	/**
	 * Fingerprint of files as they are now: file name, binary content and
	 * metadata (both versions, every language, without the UUID and lock
	 * Kirby writes on its own). A Panel upload, a replaced file or an edited
	 * alt text between draft and confirmation changes it.
	 *
	 * @param iterable<File> $files
	 */
	private static function filesHash(iterable $files): string
	{
		$state = [];
		foreach ($files as $file) {
			$meta = self::contents($file, ['latest', 'changes']);
			foreach (array_keys($meta) as $key) {
				unset($meta[$key]['uuid'], $meta[$key]['lock']);
			}
			$state[$file->filename()] = [is_file($file->root()) ? hash_file('xxh128', $file->root()) : null, $meta];
		}
		ksort($state);
		return hash('sha256', (string)json_encode($state, JSON_UNESCAPED_UNICODE));
	}

	/** The file's `file://` UUID as content stores it, or null when it has none. */
	private static function fileUuid(File $file): string|null
	{
		$uuid = $file->content()->get('uuid')->value();
		return is_string($uuid) && $uuid !== '' ? 'file://' . $uuid : null;
	}

	/** Whether the connection comes from a listed proxy (`fude.trustedProxies`, by REMOTE_ADDR). */
	public static function fromTrustedProxy(App $kirby): bool
	{
		return Options::isTrustedProxy($kirby, $kirby->environment()->get('REMOTE_ADDR'));
	}

	/** The request body as an array: JSON object or form fields. */
	private static function input(App $kirby): array
	{
		$request = $kirby->request();
		if ($request->method() === 'GET') {
			return $request->query()->toArray();
		}
		try {
			$data = $request->body()->toArray();
		} catch (Throwable) {
			throw FudeError::t('request.json', [], 400);
		}
		/** @psalm-suppress TypeDoesNotContainType the decoded body may be a scalar, the check is the contract */
		if (!is_array($data)) {
			throw FudeError::t('request.json', [], 400);
		}
		return $data;
	}

	private static function isBlank(mixed $value): bool
	{
		return $value === null || $value === '' || $value === [];
	}

	/**
	 * Whether anything refers to the file: the published (latest) content of
	 * its own page by UUID or file name (that also covers the file id and
	 * Kirbytext), or another page or the site (see usedIn()). Unreadable
	 * content counts as referenced: when in doubt, the file stays.
	 */
	private static function isReferenced(Page $page, File $file): bool
	{
		$own = self::storedText($page, ['latest']);
		if ($own === null) {
			return true;
		}
		$uuid = self::fileUuid($file);
		foreach ([$file->filename(), $uuid] as $needle) {
			if ($needle !== null && str_contains($own, $needle)) {
				return true;
			}
		}
		return self::usedIn(self::elsewhere($page), $file);
	}

	/**
	 * Whether a draft page Fude created is still exactly what the draft left:
	 * the content matches the fingerprint taken at draft time (as publish
	 * demands), nobody holds a Panel lock or has unsaved Panel changes on it,
	 * it has no subpages or subpage drafts, its files are the ones the draft
	 * left (none added, replaced or with edited metadata), and no other page
	 * uses one of them. Only then may a discard delete it, recursively.
	 */
	private static function isUntouched(Page $page, array $record): bool
	{
		if (!hash_equals((string)($record['changesHash'] ?? ''), self::changesHash($page->version('latest')))) {
			return false;
		}
		if ($page->lock()->isLocked() || $page->version('changes')->exists()) {
			return false;
		}
		if ($page->hasChildren() || $page->hasDrafts()) {
			return false;
		}
		if (!hash_equals((string)($record['filesHash'] ?? ''), self::filesHash($page->files()))) {
			return false;
		}
		$elsewhere = self::elsewhere($page);
		foreach ($page->files() as $file) {
			if (self::usedIn($elsewhere, $file)) {
				return false;
			}
		}
		return true;
	}

	public static function json(array $data, int $status): Response
	{
		$headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
		if ($status === 401) {
			$headers['WWW-Authenticate'] = 'Basic realm="Fude", charset="UTF-8"';
		}
		return Response::json($data, $status, true, $headers);
	}

	/** Kirby's real 404: no format of its own that would reveal the module. */
	public static function notFound(): Response
	{
		// Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
		$page = App::instance()->site()->errorPage();

		return new Response($page?->render() ?? '', 'text/html', 404);
	}

	/**
	 * The UUID stored in the page's published content, or null when it has
	 * none. Only read: $page->uuid() would create one and write it to the
	 * published version behind the draft's back.
	 */
	private static function pageUuid(Page $page): string|null
	{
		$uuid = ($page->version('latest')->read() ?? [])['uuid'] ?? null;
		return is_string($uuid) && $uuid !== '' ? $uuid : null;
	}

	private static function publish(App $kirby, array $collections, array $input, User $user, DateTimeImmutable $now, array &$entry): array
	{
		$record = Drafts::read($kirby, (string)($input['id'] ?? ''));
		$entry += ['draft' => $record['id'], 'collection' => $record['collection'], 'action' => $record['action'], 'key' => $record['key']];
		Drafts::checkToken($record, $input['token'] ?? null);
		Drafts::checkAge($kirby, $record, $now);
		$collection = self::collection($collections, $record['collection']);
		if (!in_array($user->role()->name(), $collection['publishRoles'], true)) {
			throw FudeError::t('auth.publish', [], 403);
		}

		if ($record['kind'] === 'structure') {
			$page = Content::page($kirby, $collection);
			self::checkLock($page);
			self::checkHash($kirby, $collection, $record, null);
			$changes = $page->version('changes');
			if (!$changes->exists()) {
				throw FudeError::t('draft.gone', [], 410);
			}
			if (!hash_equals($record['changesHash'], self::changesHash($changes))) {
				throw FudeError::t('draft.changed', [], 409);
			}
			$changes->publish();
			$url = $page->url();
			$hash = Content::hash($kirby, $collection);
		} elseif ($record['kind'] === 'page') {
			$page = Content::page($kirby, $collection);
			self::checkLock($page);
			self::checkHash($kirby, $collection, $record, $page);
			$changes = $page->version('changes');
			if (!$changes->exists()) {
				throw FudeError::t('draft.gone', [], 410);
			}
			if (!hash_equals($record['changesHash'], self::changesHash($changes))) {
				throw FudeError::t('draft.changed', [], 409);
			}
			$changes->publish();
			$url = $page->url();
			$hash = Content::hash($kirby, $collection, $page);
		} else {
			$child = $kirby->page($record['page']);
			if (!$child instanceof Page) {
				throw FudeError::t('draft.gone', [], 410);
			}
			// The path alone could name a new page in the old one's place
			// (deleted and created again with the same slug and content).
			if (($record['uuid'] ?? null) !== null && self::pageUuid($child) !== $record['uuid']) {
				throw FudeError::t('draft.gone', [], 410);
			}
			if ($record['action'] === 'create') {
				self::checkHash($kirby, $collection, $record, null);
				if (!$child->isDraft()) {
					throw FudeError::t('draft.gone', [], 410);
				}
				self::checkKeyFree($collection, Content::rows($kirby, $collection), (string)$record['key'], null);
				if (!hash_equals($record['changesHash'], self::changesHash($child->version('latest')))) {
					throw FudeError::t('draft.changed', [], 409);
				}
				$child = $child->changeStatus($collection['status']);
			} elseif ($record['action'] === 'delete') {
				self::checkLock($child);
				self::checkHash($kirby, $collection, $record, $child);
				if ($child->version('changes')->exists()) {
					// Someone started changing the page after the draft: not what was confirmed.
					throw FudeError::t('draft.changed', [], 409);
				}
				if ($child->hasChildren() || $child->hasDrafts()) {
					throw FudeError::t('collection.hasChildren', ['name' => $child->title()->value()], 409);
				}
				// Files go with the page: only those the draft saw, unchanged,
				// and none another page uses.
				if (!hash_equals((string)($record['filesHash'] ?? ''), self::filesHash($child->files()))) {
					throw FudeError::t('draft.files', [], 409);
				}
				$elsewhere = self::elsewhere($child);
				foreach ($child->files() as $file) {
					if (self::usedIn($elsewhere, $file)) {
						throw FudeError::t('collection.fileInUse', ['name' => $child->title()->value(), 'file' => $file->filename()], 409);
					}
				}
				$url = $child->parent()->url();
				$child->delete();
				$hash = Content::hash($kirby, $collection, null);
				Drafts::delete($kirby, $record['id']);
				return ['id' => $record['id'], 'url' => $url, 'hash' => $hash];
			} else {
				self::checkLock($child);
				self::checkHash($kirby, $collection, $record, $child);
				// The hash covers this page only: another may have taken the new key since.
				if (isset($record['fields'][$collection['key']])) {
					$rows = Content::rows($kirby, $collection);
					$self = array_search($child->id(), array_column($rows, '_id'), true);
					self::checkKeyFree($collection, $rows, $record['fields'][$collection['key']], $self === false ? null : $self);
				}
				$changes = $child->version('changes');
				if (!$changes->exists()) {
					throw FudeError::t('draft.gone', [], 410);
				}
				if (!hash_equals($record['changesHash'], self::changesHash($changes))) {
					throw FudeError::t('draft.changed', [], 409);
				}
				$changes->publish();
			}
			$url = $child->url();
			$hash = Content::hash($kirby, $collection, $record['action'] === 'update' ? $child : null);
		}
		Drafts::delete($kirby, $record['id']);
		return ['id' => $record['id'], 'url' => $url, 'hash' => $hash];
	}

	/**
	 * Removes the file a discarded draft uploaded — but only when the draft
	 * owned it alone: its changes version just went ($removed), the file is
	 * as the draft left it (no replaced binary, no edited metadata), and
	 * nothing else uses it: neither the published content of its page nor any
	 * other page or the site. A file in use means the draft was consumed
	 * (published in the Panel, or the reference written there otherwise): it
	 * stays. So it does without our changes version: the file may be live, or
	 * an editor dropped the draft.
	 *
	 * @return bool true when the file stays because it is used or was edited
	 */
	private static function removeFile(Page $page, array $record, bool $removed): bool
	{
		if (empty($record['file'])) {
			return false;
		}
		$file = $page->file($record['file']);
		if (!$file instanceof File) {
			return false;
		}
		if (self::isReferenced($page, $file)) {
			return true;
		}
		if (!hash_equals((string)($record['fileHash'] ?? ''), self::filesHash([$file]))) {
			return true;
		}
		if ($removed) {
			$file->delete();
		}
		return false;
	}

	/**
	 * replace: the new list decides every declared text field — a field left
	 * out is empty afterwards, so the draft is what the bot showed. A new row
	 * whose key matches a published row (Content::sameKey) keeps that row's
	 * undeclared columns and its image reference. A new key starts empty. A published key that is not in the new list leaves the
	 * list; its files stay on the page, as with delete.
	 *
	 * @param list<array<string, mixed>> $current the published rows
	 * @param list<array<string, string>> $entries the validated new rows
	 * @return array{rows: list<array<string, mixed>>, summary: array{kept: list<string>, added: list<string>, removed: list<string>, withoutImage: list<string>}}
	 */
	private static function replaced(array $current, array $entries, string $keyField, string|null $imageField, array $declared): array
	{
		$rows = [];
		$kept = [];
		$added = [];
		$withoutImage = [];
		foreach ($entries as $entry) {
			$key = $entry[$keyField];
			$base = [];
			foreach ($current as $old) {
				if (Content::sameKey($old[$keyField] ?? '', $key)) {
					$base = $old;
					break;
				}
			}
			$row = $base;
			foreach ($declared as $name => $rule) {
				if ($rule['type'] !== 'image') {
					$row[$name] = '';
				}
			}
			foreach ($entry as $name => $value) {
				$row[$name] = $value;
			}
			$rows[] = $row;
			if ($base === []) {
				$added[] = $key;
			} else {
				$kept[] = $key;
			}
			if ($imageField !== null && self::isBlank($row[$imageField] ?? null)) {
				$withoutImage[] = $key;
			}
		}
		$removed = [];
		foreach ($current as $old) {
			$oldKey = (string)($old[$keyField] ?? '');
			$listed = false;
			foreach ($entries as $entry) {
				if (Content::sameKey($entry[$keyField], $oldKey)) {
					$listed = true;
					break;
				}
			}
			if (!$listed) {
				$removed[] = $oldKey;
			}
		}
		return [
			'rows'    => $rows,
			'summary' => ['kept' => $kept, 'added' => $added, 'removed' => $removed, 'withoutImage' => $withoutImage],
		];
	}

	public static function respond(App $kirby, DateTimeImmutable $now, string $route): Response
	{
		if (Options::get($kirby, 'active', false) !== true || !in_array($route, self::ROUTES, true)) {
			return self::notFound();
		}
		I18n::setLanguage(Options::language($kirby));
		$entry = ['route' => $route, 'user' => null, 'actor' => null];
		try {
			$user = self::authenticate($kirby);
			$entry['user'] = $user->email();
			$input = self::input($kirby);
			$entry['actor'] = self::actor($input);
			$collections = Config::load($kirby);
			Options::timezone($kirby); // a wrong zone is loud on every route, not only on a date
			Options::trustedProxies($kirby); // so is a wrong proxy list
			// Writes need permissions the bot role does not have — and must
			// not have anywhere else. From here on everything runs as Kirby.
			$kirby->impersonate('kirby');
			$data = match ($route) {
				'schema'  => self::schema($kirby, $collections),
				'draft'   => self::draft($kirby, $collections, $input, $now, $entry),
				'publish' => self::publish($kirby, $collections, $input, $user, $now, $entry),
				'discard' => self::discard($kirby, $collections, $input, $now, $entry),
			};
			Log::write($kirby, $entry + ['status' => 200], $now);
			return self::json(['ok' => true, 'format' => self::FORMAT] + $data, 200);
		} catch (FudeError $e) {
			Log::write($kirby, $entry + ['status' => $e->status, 'error' => $e->getMessage()], $now);
			return self::json(['ok' => false, 'error' => $e->getMessage()] + $e->data, $e->status);
		} catch (Throwable $e) {
			error_log('Fude: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
			Log::write($kirby, $entry + ['status' => 500, 'error' => $e::class . ': ' . $e->getMessage()], $now);
			return self::json(['ok' => false, 'error' => I18n::t('draft.internal')], 500);
		}
	}

	/** The route entry point: one request, with the current time in Europe/Berlin. */
	public static function respondNow(string $route): Response
	{
		return self::respond(App::instance(), new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')), $route);
	}

	// --- Routes --------------------------------------------------------------

	private static function schema(App $kirby, array $collections): array
	{
		return [
			'site'        => Options::siteId($kirby),
			'language'    => I18n::language(),
			'collections' => (object)Config::schema($kirby, $collections),
		];
	}

	/**
	 * The values as the page stores them. A page field's option value (the
	 * title) becomes a one-item list with the page reference, as the Panel
	 * saves it: the UUID, or the page id with `store: id` in the blueprint or
	 * UUIDs switched off. An emptied page field stays ''. Everything else is
	 * unchanged; the draft record keeps the titles.
	 *
	 * @param array<string, string> $values validated values
	 * @return array<string, string|list<string>>
	 */
	private static function stored(App $kirby, array $collection, array $values): array
	{
		foreach ($values as $name => $value) {
			$rule = $collection['fields'][$name] ?? [];
			if (($rule['type'] ?? null) !== 'page' || $value === '') {
				continue;
			}
			$page = $kirby->page($rule['pages'][$value]);
			if (!$page instanceof Page) {
				throw FudeError::t('collection.page', ['name' => $collection['name']], 500);
			}
			$values[$name] = [$rule['store'] === 'id' || !Uuids::enabled() ? $page->id() : $page->uuid()->toString()];
		}
		return $values;
	}

	/**
	 * The entry a draft changes, as stored: the structure row, the child
	 * page's or the page's published content; [] for a new entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function storedEntry(App $kirby, array $collection, array $rows, int|null $index): array
	{
		if ($collection['kind'] === 'page') {
			return Content::page($kirby, $collection)->version('latest')->read() ?? [];
		}
		if ($index === null) {
			return [];
		}
		if ($collection['kind'] === 'structure') {
			return $rows[$index];
		}
		return $kirby->page($rows[$index]['_id'])?->version('latest')->read() ?? [];
	}

	/**
	 * Everything a page, file or the site stores in the given versions, in
	 * every language, as one JSON text; null when it cannot be encoded.
	 *
	 * @param list<string> $versions
	 */
	private static function storedText(ModelWithContent $model, array $versions = ['latest', 'changes']): string|null
	{
		$text = json_encode(self::contents($model, $versions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($text) ? $text : null;
	}

	private static function storeImage(App $kirby, Page $page, array $collection, array $upload, string $format, string $imageField, array $row): File
	{
		$rule = $collection['fields'][$imageField];
		$altField = $rule['alt'] ?? $collection['key'];
		$alt = trim((string)($row[$altField] ?? $row[$collection['key']] ?? ''));
		$base = trim((string)($row[$collection['key']] ?? $alt));
		return Images::store($kirby, $page, $upload, $rule, $format, $base !== '' ? $base : $imageField, $alt);
	}

	/** The draft's day as Y-m-d, in the time zone of `fude.timezone`. */
	private static function today(App $kirby, DateTimeImmutable $now): string
	{
		return $now->setTimezone(Options::timezone($kirby))->format('Y-m-d');
	}

	/**
	 * The page field a collection's `touch` sets on every draft: today's
	 * date in the time zone of `fude.timezone`, as Y-m-d. With `editable`
	 * the draft may name another day (`touch` in the request), not before
	 * today; without one it is the touch's `default`: today, or the next
	 * named weekday (today included).
	 *
	 * @return array<string, string> empty when the collection declares no touch
	 * @throws FudeError 422 for a date the collection does not let the draft choose
	 */
	private static function touch(App $kirby, array $collection, mixed $value, DateTimeImmutable $now): array
	{
		$given = $value !== null && $value !== '';
		if (!isset($collection['touch'])) {
			if ($given) {
				throw FudeError::t('touch.none', ['name' => $collection['name']]);
			}
			return [];
		}
		$today = self::today($kirby, $now);
		if (!$given) {
			return [$collection['touch']['field'] => self::touchDefault($kirby, $collection['touch'], $now)];
		}
		if (($collection['touch']['editable'] ?? false) !== true) {
			throw FudeError::t('touch.fixed', ['name' => $collection['name']]);
		}
		// preg_match() fills $parts; the placeholders are only read after a match.
		$parts = ['', '', '', ''];
		// A real calendar day in the form YYYY-MM-DD, as for a date field.
		$valid = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1 &&
			checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
		if (!$valid) {
			throw FudeError::t('touch.date');
		}
		// Zero-padded Y-m-d strings compare in date order.
		if ($value < $today) {
			throw FudeError::t('touch.dateMin');
		}
		return [$collection['touch']['field'] => $value];
	}

	/** The day a draft without a date gets: today, or the next `default` weekday (today included). */
	private static function touchDefault(App $kirby, array $touch, DateTimeImmutable $now): string
	{
		$weekday = array_search($touch['default'] ?? 'today', Config::WEEKDAYS, true);
		$day = $now->setTimezone(Options::timezone($kirby));
		if ($weekday === false) {
			return $day->format('Y-m-d');
		}
		// ISO weekday N: 1 = Monday, the index in Config::WEEKDAYS plus one.
		$ahead = ($weekday + 1 - (int)$day->format('N') + 7) % 7;
		return $day->modify("+$ahead days")->format('Y-m-d');
	}

	/** The touch of a draft for its record: field and date, or null without touch. */
	private static function touchRecord(array $touched): array|null
	{
		$field = array_key_first($touched);
		return $field === null ? null : ['field' => $field, 'value' => $touched[$field]];
	}

	/** The upload named "image", or null when none was sent. */
	private static function upload(App $kirby): array|null
	{
		$upload = $kirby->request()->files()->get('image');
		if (!is_array($upload) || ($upload === [])) {
			return null;
		}
		if (array_is_list($upload)) {
			$upload = $upload[0] ?? null;
		}
		if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
			return null;
		}
		return $upload;
	}

	/**
	 * Whether one of the texts from elsewhere() names the file: by UUID (a
	 * files field) or by file id (a files field without UUIDs, Kirbytext from
	 * another page). A bare file name there means a file of that other page.
	 *
	 * @param list<string|null> $texts
	 */
	private static function usedIn(array $texts, File $file): bool
	{
		$needles = array_filter([$file->id(), self::fileUuid($file)]);
		foreach ($texts as $text) {
			if ($text === null) {
				return true;
			}
			foreach ($needles as $needle) {
				if (str_contains($text, $needle)) {
					return true;
				}
			}
		}
		return false;
	}
}
