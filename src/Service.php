<?php

namespace Sayamaapps\Fude;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Content\Field;
use Kirby\Content\Version;
use Kirby\Form\Form;
use Kirby\Http\Request\Auth\BasicAuth;
use Kirby\Http\Response;
use Kirby\Http\Route;
use Kirby\Http\Router;
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
 * Off by default: without `sayamaapps.fude.active` the routes answer with Kirby's real
 * 404. Login via Basic Auth against a Kirby account with the role `fude`
 * (or a role from `sayamaapps.fude.roles`), HTTPS only — `sayamaapps.fude.withoutHttps` is for
 * local tests. Every call is logged (src/Log.php).
 *
 * Nothing here writes the published (`latest`) version directly. A draft
 * is a `changes` version; only /fude/publish, with the token from the
 * draft and an unchanged published state (hash), copies it over — through
 * Kirby's own Version::publish(), which validates against the blueprint
 * once more.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Service
{
	public const FORMAT = 1;

	/** A replace's JSON string, as sent by a multipart form: size and nesting limit. */
	private const MAX_ENTRIES_JSON_BYTES = 65536;
	private const MAX_ENTRIES_JSON_DEPTH = 4;

	/** Most rows one replace may carry, with or without maxEntries. */
	public const MAX_REPLACE_ENTRIES = 100;
	public const ROLE = 'fude';

	/** The prefix of the names of Fude's own routes (config/routes.php). */
	public const ROUTE_NAME = 'sayamaapps.fude.';
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
	 * `sayamaapps.fude.withoutHttps` is for local tests. Kirby's own password check, with
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
				['key' => Options::setKey($kirby, 'trustedProxies')],
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
		return hash('sha256', (string)json_encode($content, JSON_UNESCAPED_UNICODE));
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

	/** What the draft shows the public, checked against the declaration: every replaced or added row, or the one entry. */
	private static function checkDraftShown(App $kirby, array $collection, array $rows, int|null $index, string $action, array|null $entries, array $clean): void
	{
		if ($action === 'replace') {
			foreach ($entries ?? [] as $row) {
				$published = array_values(array_filter($rows, fn ($old) => Content::sameEntry($collection, $old, $row)));
				self::checkShown($collection, $published[0] ?? [], $row);
			}
		} elseif ($action === 'create' && $entries !== null) {
			foreach ($entries as $row) {
				self::checkShown($collection, [], $row);
			}
		} elseif ($action !== 'delete') {
			self::checkShown($collection, self::storedEntry($kirby, $collection, $rows, $index), $clean);
		}
	}

	/**
	 * A draft needs a page nobody else is working on: no active Panel lock,
	 * and no unsaved Panel changes (they would be published together with
	 * ours — the editor would never have confirmed that). Publishing a new
	 * page asks the same again.
	 */
	private static function checkEditable(Page $page): void
	{
		self::checkLock($page);
		$open = self::openDraft($page);
		if ($open !== null) {
			// Names the Fude draft that holds the page, so the caller can finish or discard it.
			throw FudeError::t('draft.pending', [], 423, isset($open['id']) ? ['draft' => $open] : []);
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
	 * No entry but $except has the key yet. An update must not give an
	 * entry the key of another one, nor may publish make two of them: neither
	 * could be named by its key afterwards. The key of a composite key is the
	 * combination of its parts.
	 *
	 * @param array<string, mixed> $values the entry's values (at least its key fields)
	 * @throws FudeError 409
	 */
	private static function checkKeyFree(array $collection, array $rows, array $values, int|null $except): void
	{
		foreach ($rows as $index => $row) {
			if ($index !== $except && Content::sameEntry($collection, $row, $values)) {
				throw FudeError::t('collection.keyTaken', ['field' => Content::keyLabel($collection), 'key' => Content::keyText($collection, $values)], 409);
			}
		}
	}

	private static function checkLock(Page $page): void
	{
		if ($page->lock()->isLocked()) {
			throw FudeError::t('draft.locked', [], 423);
		}
	}

	/** A list holds at most `maxEntries` rows: after a replace, or after a create adds its rows. */
	private static function checkMaxEntries(array $collection, string $action, array $rows, array|null $entries): void
	{
		if (!isset($collection['maxEntries']) || !in_array($action, ['create', 'replace'], true)) {
			return;
		}
		$total = $action === 'replace' ? count($entries ?? []) : count($rows) + ($entries === null ? 1 : count($entries));
		if ($total > $collection['maxEntries']) {
			throw FudeError::t('collection.maxEntries', ['max' => $collection['maxEntries']]);
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

	/**
	 * The image field and the checked format of every upload: the single
	 * `image`, and `image<n>` of several new entries.
	 *
	 * @param array<int, array> $uploads
	 * @return array{0: string|null, 1: string|null, 2: array<int, string>}
	 * @throws FudeError
	 */
	private static function checkUploads(App $kirby, array $collection, array|null $upload, array $uploads): array
	{
		$imageField = self::imageField($collection);
		if (($upload !== null || $uploads !== []) && $imageField === null) {
			throw FudeError::t('image.unexpected');
		}
		$format = $upload !== null ? Images::check($kirby, $upload, $collection['fields'][$imageField]) : null;
		$formats = [];
		foreach ($uploads as $position => $one) {
			assert($imageField !== null);
			$formats[$position] = Images::check($kirby, $one, $collection['fields'][$imageField]);
		}
		return [$imageField, $format, $formats];
	}

	// --- Helpers -------------------------------------------------------------

	/** @throws FudeError */
	private static function collection(array $collections, mixed $name): array
	{
		return self::collectionNamed($collections, $name);
	}

	/**
	 * The declared collection of that name.
	 *
	 * @internal
	 * @throws FudeError 404
	 */
	public static function collectionNamed(array $collections, mixed $name): array
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
	 * `sayamaapps.fude.trustedProxies` and carries `X-Forwarded-Proto: https`. Any other
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

	/**
	 * The rows of a create with `entries`: several new entries in one draft
	 * (a structure collection only), every row checked like a create, no
	 * `fields` and no single `image` next to them.
	 *
	 * @return list<array<string, string>>
	 * @throws FudeError
	 */
	private static function createEntries(array $collection, mixed $entries, array $fields, array|null $upload, string $today): array
	{
		if ($collection['kind'] !== 'structure') {
			throw FudeError::t('collection.createEntries', ['name' => $collection['name']]);
		}
		if ($fields !== []) {
			throw FudeError::t('collection.createFields');
		}
		if ($upload !== null) {
			throw FudeError::t('collection.createImage');
		}
		$rows = self::entries($collection, $entries, $today, 'create');
		if ($rows === []) {
			throw FudeError::t('collection.createEmpty');
		}
		return $rows;
	}

	/**
	 * The list after a create with several entries: the new rows, as stored
	 * and each with its image (`image<n>`) as a Kirby file, first (`insert:
	 * top`) or last. Every file is added to $files as soon as it is stored, so
	 * that a failure leaves the files stored so far to the caller's rollback().
	 *
	 * @param list<File> $files the files stored, in entry order
	 * @return array{0: list<array<string, mixed>>, 1: int|null} the rows, the position of the first new row (null = appended)
	 */
	private static function createRows(DraftContext $ctx, array &$files): array
	{
		$new = [];
		foreach ($ctx->entries ?? [] as $position => $values) {
			$row = self::stored($ctx->kirby, $ctx->collection, $values);
			if (isset($ctx->uploads[$position])) {
				assert($ctx->imageField !== null);
				$image = self::storeImage($ctx->kirby, $ctx->page, $ctx->collection, $ctx->uploads[$position], $ctx->formats[$position], $ctx->imageField, $row);
				$files[] = $image;
				$row[$ctx->imageField] = [self::fileReference($image)];
			}
			$new[] = $row;
		}
		if (($ctx->collection['insert'] ?? 'bottom') === 'top') {
			return [[...$new, ...$ctx->rows], 0];
		}
		return [[...$ctx->rows, ...$new], null];
	}

	/**
	 * Discards a draft, expired or not: with its token, or as its owner
	 * (Drafts::checkAccess()). Called by Actions::discard() inside the write
	 * lock, as Kirby.
	 *
	 * @internal
	 */
	public static function discard(App $kirby, array $collections, array $input, string|null $owner, array &$entry): array
	{
		$record = Drafts::read($kirby, (string)($input['id'] ?? ''));
		$entry += ['draft' => $record['id'], 'collection' => $record['collection'], 'action' => $record['action'], 'key' => $record['key']];
		Drafts::checkAccess($record, $input['token'] ?? null, $owner);
		return self::discardRecord($kirby, $collections, $record, $entry);
	}

	/**
	 * Removes what the draft left on its page, as far as it is still the
	 * draft's own (a Panel editor's work on top stays: `kept`), and then the
	 * record. Also what the cleanup of long-expired drafts does.
	 *
	 * @internal
	 */
	public static function discardRecord(App $kirby, array $collections, array $record, array &$entry): array
	{
		$entry += ['draft' => $record['id'], 'collection' => $record['collection'], 'action' => $record['action'], 'key' => $record['key']];
		$collection = self::collection($collections, $record['collection']);
		$kept = false;   // a Panel editor's work on top of the draft stays, so does a draft the Panel used
		$removed = false; // our draft's changes version went: its files may go with it

		if ($record['kind'] === 'structure') {
			$page = Content::page($kirby, $collection);
			$changes = $page->version('changes');
			$gone = !$changes->exists();
			if (!$gone) {
				// Only our own draft goes; a Panel editor's work on top stays.
				if (hash_equals($record['changesHash'], self::changesHash($changes))) {
					$changes->delete();
					$removed = true;
				} else {
					$kept = true;
				}
			}
			// No changes version: the draft was dropped in the Panel, or published
			// there. Its files go with it unless something uses them (an image the
			// published page references is live now and stays: removeFile()).
			$kept = self::removeFiles($page, $record, $removed || $gone) || $kept;
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
					$gone = !$changes->exists(); // dropped or published in the Panel: see structure
					if (!$gone) {
						if (hash_equals($record['changesHash'], self::changesHash($changes))) {
							$changes->delete();
							$removed = true;
						} else {
							$kept = true;
						}
					}
					$kept = self::removeFiles($child, $record, $removed || $gone) || $kept;
				}
			}
		}
		Drafts::delete($kirby, $record['id']);
		$entry['kept'] = $kept;
		return ['id' => $record['id'], 'kept' => $kept];
	}

	/**
	 * A draft from the checked input. Called by Actions::draft() inside the
	 * write lock, as Kirby.
	 *
	 * @internal
	 * @param array<string, mixed> $files the form parts with uploads (`image`, `image<n>`), as Kirby's request has them
	 * @param array<string, string> $tags `owner` and `ref` for the record, only those that are set
	 */
	public static function draft(App $kirby, array $collections, array $input, array $files, array $tags, DateTimeImmutable $now, array &$entry): array
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
		$upload = self::upload($files);
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
		} elseif ($action === 'create' && ($input['entries'] ?? null) !== null) {
			$clean = [];
			$entries = self::createEntries($collection, $input['entries'], $fields, $upload, self::today($kirby, $now));
		} else {
			$clean = Validator::check($collection, $fields, $action, self::today($kirby, $now));
		}
		// The images of several new entries, `image<n>` for entry n; refused for every other draft.
		$uploads = self::uploads($files, $action === 'create' ? count($entries ?? []) : 0);
		[$imageField, $format, $formats] = self::checkUploads($kirby, $collection, $upload, $uploads);
		if (in_array($action, ['create', 'update'], true) && $clean === [] && $upload === null && $entries === null) {
			throw FudeError::t('fields.empty');
		}

		$page = Content::page($kirby, $collection);
		$rows = Content::rows($kirby, $collection);
		[$entry['key'], $index] = self::draftKey($collection, $rows, $action, $clean, $entries, $input);

		self::checkDraftShown($kirby, $collection, $rows, $index, $action, $entries, $clean);

		// The record is written last. A folder that cannot take it is found
		// now, before anything is stored, so that "nothing changed" is true.
		Drafts::prepare($kirby);

		if ($collection['kind'] === 'page') {
			return self::draftSinglePage($kirby, $collection, $clean, $touch, $tags, $now, $entry);
		}
		$context = new DraftContext($kirby, $collection, $page, $rows, $index, $clean, $action, $now, $entry);
		$context->upload = $upload;
		$context->format = $format;
		$context->uploads = $uploads;
		$context->formats = $formats;
		$context->imageField = $imageField;
		$context->entries = $entries;
		$context->touched = $touch;
		$context->tags = $tags;
		return $collection['kind'] === 'structure' ? self::draftStructure($context) : self::draftPage($context);
	}

	/**
	 * The entry a draft names and where it is: a new key (or several) must
	 * be free, an update or delete names an existing entry by `key`: the
	 * value of a single-field key, an object with every part (field name =>
	 * value) for a composite key.
	 *
	 * @param list<array<string, mixed>> $rows the published rows
	 * @return array{0: string|array<string, string>|null, 1: int|null} the key for the record and the log, and the row index (update, delete)
	 * @throws FudeError
	 */
	private static function draftKey(array $collection, array $rows, string $action, array $clean, array|null $entries, array $input): array
	{
		if ($collection['kind'] === 'page' || $action === 'replace') {
			return [null, null]; // the page itself, or the whole list: no single entry to name
		}
		$label = Content::keyLabel($collection);
		if ($action === 'create') {
			// One new entry, or several: every new key must be free.
			foreach ($entries ?? [$clean] as $new) {
				foreach ($rows as $row) {
					if (Content::sameEntry($collection, $row, $new)) {
						throw FudeError::t('collection.exists', ['field' => $label, 'key' => Content::keyText($collection, $new)], 409);
					}
				}
			}
			return [$entries === null ? Content::keyOf($collection, $clean) : null, null]; // several entries: the record lists their keys
		}
		// update and delete both name an existing entry.
		$key = self::requestKey($collection, $action, $input['key'] ?? null);
		$index = Content::find($collection, $rows, $key);
		if ($action === 'update' && array_intersect_key($clean, array_flip(Content::keyFields($collection))) !== []) {
			// The key the entry has afterwards: its own parts, the draft's on top.
			self::checkKeyFree($collection, $rows, [...$rows[$index], ...$clean], $index);
		}
		return [is_array($key) ? array_map('trim', $key) : trim($key), $index];
	}

	/** A create: a draft page under the parent, with its image, and the blueprint checked before it is saved. */
	private static function draftNewPage(DraftContext $ctx): array
	{
		$kirby = $ctx->kirby;
		$collection = $ctx->collection;
		$parent = $ctx->page;
		$clean = $ctx->clean;
		$upload = $ctx->upload;
		$format = $ctx->format;
		$imageField = $ctx->imageField;
		$now = $ctx->now;
		$file = null;
		$hash = Content::hash($kirby, $collection);
		$content = self::stored($kirby, $collection, $clean);
		// The slug is ours before Kirby is called: a draft stored under it can be
		// found and undone, though Page::create() threw before it returned it.
		// A draft that was there before is not ours to delete (Kirby refuses a
		// duplicate slug, a published page of that slug included, without storing).
		$slug = Str::slug($clean['title']) . '-' . bin2hex(random_bytes(2));
		$taken = self::draftOnDisk($parent, $collection, $slug) !== null;
		$child = null;
		try {
			$child = Page::create([
				'parent'   => $parent,
				'slug'     => $slug,
				'template' => $collection['template'],
				'content'  => $content,
				'isDraft'  => true,
			]);
			if ($upload !== null) {
				assert($format !== null && $imageField !== null);
				$file = self::storeImage($kirby, $child, $collection, $upload, $format, $imageField, $content);
				$content[$imageField] = [self::fileReference($file)];
				$child = $child->update([$imageField => $content[$imageField]]);
			}
			self::checkBlueprint($child, $content);
			return self::recordDraft($ctx, [
				'collection'  => $collection['name'],
				'kind'        => 'pages',
				'action'      => 'create',
				'page'        => $child->id(),
				'key'         => $ctx->entry['key'],
				'index'       => null,
				'fields'      => $clean,
				'file'        => $file?->filename(),
				'filesHash'   => self::filesHash($child->files()),
				'hash'        => $hash,
				'changesHash' => self::changesHash($child->version('latest')),
				'preview'     => $child->version('latest')->url(),
			], $now);
		} catch (Throwable $e) {
			// The page goes with its files. It is looked up by its slug, fresh:
			// Kirby runs page.create:after once the page is stored, before create()
			// returns it ($child is null then), and a page.update:after that throws
			// leaves $child as the old object, whose storage is immutable by then.
			self::rollback($e, [], created: $taken ? null : (self::draftOnDisk($parent, $collection, $slug) ?? $child));
		}
	}

	/**
	 * The draft page of that slug under the parent as the disk has it now, or
	 * null. Not looked up in the parent's children and drafts: Kirby caches
	 * those collections and does not know a page stored since.
	 */
	private static function draftOnDisk(Page $parent, array $collection, string $slug): Page|null
	{
		$draft = Page::factory(['slug' => $slug, 'parent' => $parent, 'isDraft' => true, 'template' => $collection['template']]);
		return $draft->exists() ? $draft : null;
	}

	private static function draftPage(DraftContext $ctx): array
	{
		if ($ctx->action === 'create') {
			return self::draftNewPage($ctx);
		}
		$kirby = $ctx->kirby;
		$collection = $ctx->collection;
		$rows = $ctx->rows;
		$index = $ctx->index;
		$clean = $ctx->clean;
		$upload = $ctx->upload;
		$format = $ctx->format;
		$imageField = $ctx->imageField;
		$action = $ctx->action;
		$now = $ctx->now;
		$file = null;
		assert($index !== null);
		$child = $kirby->page($rows[$index]['_id']);
		if (!$child instanceof Page) {
			throw FudeError::t('collection.page', ['name' => $collection['name']], 500);
		}
		self::checkEditable($child);
		$hash = Content::hash($kirby, $collection, $child);
		if ($action === 'delete') {
			// Nothing is touched before publish: the page stays until then,
			// and the draft only names it.
			return self::recordDraft($ctx, [
				'collection'  => $collection['name'],
				'kind'        => 'pages',
				'action'      => 'delete',
				'page'        => $child->id(),
				'uuid'        => self::pageUuid($child),
				'key'         => $ctx->entry['key'],
				'index'       => $index,
				'fields'      => [],
				'file'        => null,
				'filesHash'   => self::filesHash($child->files()),
				'hash'        => $hash,
				'changesHash' => null,
				'preview'     => $child->version('latest')->url(),
			], $now);
		}
		$content = self::stored($kirby, $collection, $clean);
		$changes = $child->version('changes');
		$prior = self::priorChanges($changes);
		$written = false; // whether this attempt began to write the changes version
		try {
			if ($upload !== null) {
				assert($format !== null && $imageField !== null);
				$file = self::storeImage($kirby, $child, $collection, $upload, $format, $imageField, $rows[$index] + $clean);
				$content[$imageField] = [self::fileReference($file)];
			}
			$base = $changes->exists() ? [] : ($child->version('latest')->read() ?? []);
			self::checkBlueprint($child, [...$base, ...$content]);
			$written = true;
			$changes->save([...$base, ...$content]);
			return self::recordDraft($ctx, [
				'collection'  => $collection['name'],
				'kind'        => 'pages',
				'action'      => 'update',
				'page'        => $child->id(),
				'uuid'        => self::pageUuid($child),
				'key'         => $ctx->entry['key'],
				'index'       => $index,
				'fields'      => $clean,
				'file'        => $file?->filename(),
				'fileHash'    => $file === null ? null : self::filesHash([$file]),
				'hash'        => $hash,
				'changesHash' => self::changesHash($child->version('changes')),
				'preview'     => $child->version('changes')->url(),
			], $now);
		} catch (Throwable $e) {
			self::rollback($e, [$file], $written ? $changes : null, $prior);
		}
	}

	/**
	 * kind page: the declared fields (and the touch date, if declared) go
	 * into the page's changes version, which carries the WHOLE page — like a
	 * structure draft, with no key and no rows.
	 */
	private static function draftSinglePage(App $kirby, array $collection, array $clean, array $touched, array $tags, DateTimeImmutable $now, array &$entry): array
	{
		$page = Content::page($kirby, $collection);
		self::checkEditable($page);
		$hash = Content::hash($kirby, $collection, $page);

		$changes = $page->version('changes');
		$prior = self::priorChanges($changes);
		$written = false; // whether this attempt began to write the changes version
		try {
			$base = $changes->exists() ? [] : ($page->version('latest')->read() ?? []);
			$full = [...$base, ...self::stored($kirby, $collection, $clean), ...$touched];
			self::checkBlueprint($page, $full);
			$written = true;
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
				...$tags,
			], $now);
			$entry['draft'] = $record['id'];
			return ['draft' => Drafts::public($record), 'token' => $record['token'], 'preview' => $record['preview']];
		} catch (Throwable $e) {
			self::rollback($e, [], $written ? $changes : null, $prior);
		}
	}

	/** @param list<array<string, mixed>> $rows the published rows */
	private static function draftStructure(DraftContext $ctx): array
	{
		$kirby = $ctx->kirby;
		$collection = $ctx->collection;
		$page = $ctx->page;
		$rows = $ctx->rows;
		$index = $ctx->index;
		$clean = $ctx->clean;
		$upload = $ctx->upload;
		$format = $ctx->format;
		$imageField = $ctx->imageField;
		$action = $ctx->action;
		$entries = $ctx->entries;
		$touched = $ctx->touched;
		$now = $ctx->now;
		$field = $collection['field'];
		$many = $action === 'create' && $entries !== null; // several new entries in one draft
		self::checkEditable($page);
		$hash = Content::hash($kirby, $collection);

		// Checked before anything is stored: a refused draft leaves no file behind.
		self::checkMaxEntries($collection, $action, $rows, $entries);

		$changes = $page->version('changes');
		$prior = self::priorChanges($changes);
		$written = false;   // whether this attempt began to write the changes version
		$file = null;
		$files = [];        // the images of several new entries, in entry order
		$position = $index; // where the row sits after the draft; null = appended
		$replace = null;    // what a replace changes, for the reply and the record
		try {
			if ($action === 'delete') {
				// The row leaves the list. Its image stays on the page, as with an
				// update: removing files is the editor's decision in the Panel.
				assert($index !== null);
				unset($rows[$index]);
				$rows = array_values($rows);
			} elseif ($action === 'replace') {
				$stored = array_values(array_map(fn (array $row) => self::stored($kirby, $collection, $row), $entries ?? []));
				$merged = self::replaced($collection, $rows, $stored, $imageField);
				$rows = $merged['rows'];
				$replace = $merged['summary'];
			} elseif ($many) {
				[$rows, $position] = self::createRows($ctx, $files);
			} else {
				$row = $index === null ? [] : $rows[$index];
				foreach (self::stored($kirby, $collection, $clean) as $name => $value) {
					$row[$name] = $value;
				}
				if ($upload !== null) {
					assert($format !== null && $imageField !== null);
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

			self::checkBlueprint($page, [$field => $rows, ...$touched]);
			// A changes version must carry the WHOLE content: publish() sets
			// every field missing from it to null. The Panel does the same.
			$base = $changes->exists() ? [] : ($page->version('latest')->read() ?? []);
			$written = true;
			$changes->save([...$base, $field => $rows, ...$touched]);

			return self::recordDraft($ctx, [
				'collection'  => $collection['name'],
				'kind'        => 'structure',
				'action'      => $action,
				'page'        => $page->id(),
				'key'         => $ctx->entry['key'],
				'index'       => $action === 'replace' || $many ? null : ($position ?? count($rows) - 1),
				'fields'      => $clean,
				'replace'     => $replace,
				'touch'       => self::touchRecord($touched),
				'file'        => $file?->filename(),
				'fileHash'    => $file === null ? null : self::filesHash([$file]),
				// Several new entries: their keys, the checked rows and every uploaded file (discard removes them).
				...($many ? [
					'keys'    => array_values(array_map(fn (array $row): string => Content::keyText($collection, $row), $entries)),
					'entries' => $entries,
					'files'   => array_map(fn (File $image): array => ['name' => $image->filename(), 'hash' => self::filesHash([$image])], $files),
				] : []),
				'hash'        => $hash,
				'changesHash' => self::changesHash($page->version('changes')),
				'preview'     => $page->version('changes')->url(),
			], $now);
		} catch (Throwable $e) {
			self::rollback($e, [$file, ...$files], $written ? $changes : null, $prior);
		}
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
	private static function entries(array $collection, mixed $entries, string $today, string $action = 'replace'): array
	{
		if (is_string($entries)) {
			// Size and depth first: decoding a huge string is the expensive part.
			if (strlen($entries) > self::MAX_ENTRIES_JSON_BYTES) {
				throw FudeError::t('collection.entries', ['action' => $action]);
			}
			$entries = json_decode($entries, true, self::MAX_ENTRIES_JSON_DEPTH);
		}
		if (!is_array($entries) || !array_is_list($entries)) {
			throw FudeError::t('collection.entries', ['action' => $action]);
		}
		// The count is refused before any row is validated.
		if (count($entries) > self::MAX_REPLACE_ENTRIES) {
			throw FudeError::t('collection.maxEntries', ['max' => self::MAX_REPLACE_ENTRIES]);
		}
		$rows = [];
		foreach ($entries as $entry) {
			if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
				throw FudeError::t('collection.entries', ['action' => $action]);
			}
			$row = Validator::check($collection, $entry, 'create', $today);
			foreach ($rows as $other) {
				if (Content::sameEntry($collection, $other, $row)) {
					throw FudeError::t('collection.duplicate', ['field' => Content::keyLabel($collection), 'key' => Content::keyText($collection, $row)]);
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
			$root = (string)$file->root();
			$state[$file->filename()] = [is_file($root) ? hash_file('xxh128', $root) : null, $meta];
		}
		ksort($state);
		return hash('sha256', (string)json_encode($state, JSON_UNESCAPED_UNICODE));
	}

	/** The file's `file://` UUID as content stores it, or null when it has none. */
	private static function fileUuid(File $file): string|null
	{
		$field = $file->content()->get('uuid');
		$uuid = $field instanceof Field ? $field->value() : null;
		return is_string($uuid) && $uuid !== '' ? 'file://' . $uuid : null;
	}

	/**
	 * The `key` that filters entries(): a text for a single-field key, an
	 * object with every key field for a composite key.
	 *
	 * @internal
	 * @return string|array<string, string>
	 * @throws FudeError 422
	 */
	public static function filterKey(array $collection, mixed $key): string|array
	{
		if (count(Content::keyFields($collection)) === 1 && (!is_string($key) || trim($key) === '')) {
			throw FudeError::t('collection.filterKey', ['field' => Content::keyLabel($collection)]);
		}
		$key = self::requestKey($collection, 'entries', $key);
		return is_array($key) ? array_map('trim', $key) : trim($key);
	}

	/** Whether the connection comes from a listed proxy (`sayamaapps.fude.trustedProxies`, by REMOTE_ADDR). */
	public static function fromTrustedProxy(App $kirby): bool
	{
		return Options::isTrustedProxy($kirby, $kirby->environment()->get('REMOTE_ADDR'));
	}

	/** The name of the collection's image field, or null. */
	private static function imageField(array $collection): string|null
	{
		foreach ($collection['fields'] as $name => $rule) {
			if ($rule['type'] === 'image') {
				return $name;
			}
		}
		return null;
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

	/**
	 * The reply text of an unexpected failure: "nothing changed" only where
	 * it is certain. A draft undoes itself, a publish or discard can fail
	 * half-way.
	 */
	private static function internalError(string $route): string
	{
		return in_array($route, ['schema', 'draft'], true) ? 'draft.internal' : 'request.internal';
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

	/** A JSON reply with the headers every Fude route sends. */
	public static function json(array $data, int $status): Response
	{
		$headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'];
		if ($status === 401) {
			$headers['WWW-Authenticate'] = 'Basic realm="Fude", charset="UTF-8"';
		}
		return Response::json($data, $status, true, $headers);
	}

	/**
	 * A failure for the PHP error log, with the place it came from.
	 *
	 * @internal
	 */
	public static function logFailure(Throwable $e): void
	{
		error_log('Fude: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
	}

	/** Kirby's real 404: no format of its own that would reveal the module. */
	public static function notFound(): Response
	{
		// Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
		$page = App::instance()->site()->errorPage();

		return new Response($page?->render() ?? '', 'text/html', 404);
	}

	/**
	 * The answer for a path under /fude/ that no Fude route serves: Kirby's
	 * real 404, as notFound() — unless a route of another plugin or of the
	 * site config matches the path and the request method. Then the router
	 * goes on to that route (Route::next()). Without this, Fude's catch-all
	 * would hide such a route whenever Kirby loads Fude first.
	 *
	 * @internal
	 */
	public static function notFoundOrNext(string $path): Response
	{
		$kirby = App::instance();
		$others = array_values(array_filter(
			$kirby->extensions('routes'),
			fn (mixed $route): bool => is_array($route) && !str_starts_with((string)($route['name'] ?? ''), self::ROUTE_NAME)
		));
		try {
			(new Router($others))->find($path, $kirby->request()->method());
			$found = true;
		} catch (Throwable) {
			$found = false;
		}
		if ($found) {
			Route::next();
		}
		return self::notFound();
	}

	/** One entry of $_FILES as Kirby hands it over (a list means the first), or null for no upload. */
	private static function oneUpload(mixed $upload): array|null
	{
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
	 * What holds the page's changes version, if anything does: the Fude
	 * draft on it (`id`, `owner`, `preview`; never the token), or
	 * `['panel' => true]` for unsaved Panel changes without a Fude draft;
	 * null when the page has no unsaved changes. Of several drafts on the
	 * page, the one whose content is there unchanged, else the newest.
	 *
	 * @internal
	 * @return array{id?: string, owner?: string|null, preview?: string|null, panel?: true}|null
	 */
	public static function openDraft(Page $page): array|null
	{
		$changes = $page->version('changes');
		if (!$changes->exists() || $changes->isIdentical('latest')) {
			return null;
		}
		$hash = self::changesHash($changes);
		$found = null;
		foreach (Drafts::all($page->kirby()) as $record) {
			if (($record['page'] ?? null) !== $page->id() || ($record['changesHash'] ?? null) === null || $record['action'] === 'delete') {
				continue;
			}
			if (hash_equals((string)$record['changesHash'], $hash)) {
				$found = $record;
				break;
			}
			$found ??= $record;
		}
		if ($found === null) {
			return ['panel' => true];
		}
		return ['id' => (string)$found['id'], 'owner' => $found['owner'] ?? null, 'preview' => $found['preview'] ?? null];
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

	/**
	 * What a page's `changes` version holds before a draft touches it: its
	 * fields, or null when there is none. rollback() puts exactly this back.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function priorChanges(Version $changes): array|null
	{
		return $changes->exists() ? ($changes->read() ?? []) : null;
	}

	/**
	 * Publishes a draft: with its token, or as its owner
	 * (Drafts::checkAccess()). Who may publish decides $canPublish, or
	 * without it the collection's `publishRoles`. Called by
	 * Actions::publish() inside the write lock, as Kirby.
	 *
	 * @internal
	 * @param (callable(User, array): bool)|null $canPublish
	 */
	public static function publish(App $kirby, array $collections, array $input, User $user, callable|null $canPublish, string|null $owner, DateTimeImmutable $now, array &$entry): array
	{
		$record = Drafts::read($kirby, (string)($input['id'] ?? ''));
		$entry += ['draft' => $record['id'], 'collection' => $record['collection'], 'action' => $record['action'], 'key' => $record['key']];
		Drafts::checkAccess($record, $input['token'] ?? null, $owner);
		Drafts::checkAge($kirby, $record, $now);
		$collection = self::collection($collections, $record['collection']);
		$allowed = $canPublish === null
			? in_array($user->role()->name(), $collection['publishRoles'], true)
			: $canPublish($user, Drafts::public($record)) === true;
		if (!$allowed) {
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
				// Somebody may have opened the new page in the Panel since the draft.
				self::checkEditable($child);
				self::checkKeyFree($collection, Content::rows($kirby, $collection), $record['fields'], null);
				if (!hash_equals($record['changesHash'], self::changesHash($child->version('latest')))) {
					throw FudeError::t('draft.changed', [], 409);
				}
				$child = $child->changeStatus($collection['status']);
			} elseif ($record['action'] === 'delete') {
				return self::publishDelete($kirby, $collection, $record, $child);
			} else {
				self::checkLock($child);
				self::checkHash($kirby, $collection, $record, $child);
				// The hash covers this page only: another may have taken the new key since.
				if (array_intersect_key($record['fields'], array_flip(Content::keyFields($collection))) !== []) {
					$rows = Content::rows($kirby, $collection);
					$self = array_search($child->id(), array_column($rows, '_id'), true);
					// The key the page has afterwards: its own parts, the draft's on top.
					$own = $self === false ? [] : (array)$rows[$self];
					self::checkKeyFree($collection, $rows, [...$own, ...(array)$record['fields']], $self === false ? null : $self);
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

	/** Publishes a delete draft: the page and its files go, if they are still those the draft saw. */
	private static function publishDelete(App $kirby, array $collection, array $record, Page $child): array
	{
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
		$url = $child->parent()?->url();
		$child->delete();
		$hash = Content::hash($kirby, $collection, null);
		Drafts::delete($kirby, $record['id']);
		return ['id' => $record['id'], 'url' => $url, 'hash' => $hash];
	}

	/**
	 * Creates the draft record with the caller's user, actor and tags, sets the entry's
	 * draft id and returns the reply.
	 *
	 * @param array<string, mixed> $record the record without user, actor and tags
	 */
	private static function recordDraft(DraftContext $ctx, array $record, DateTimeImmutable $now): array
	{
		$saved = Drafts::create($ctx->kirby, [...$record, 'user' => $ctx->entry['user'], 'actor' => $ctx->entry['actor'], ...$ctx->tags], $now);
		$ctx->entry['draft'] = $saved['id'];
		return ['draft' => Drafts::public($saved), 'token' => $saved['token'], 'preview' => $saved['preview']];
	}

	/**
	 * Removes the file a discarded draft uploaded — but only when the draft
	 * owned it alone: its changes version just went or was gone already
	 * ($removed; an editor's changes version on top keeps it false), the file
	 * is as the draft left it (no replaced binary, no edited metadata), and
	 * nothing else uses it: neither the published content of its page nor any
	 * other page or the site. A file in use means the draft was consumed
	 * (published in the Panel, or the reference written there otherwise): it
	 * stays.
	 *
	 * @param $hash the file's hash when the draft stored it
	 * @return bool true when the file stays because it is used or was edited
	 */
	private static function removeFile(Page $page, string $name, string $hash, bool $removed): bool
	{
		$file = $page->file($name);
		if (!$file instanceof File) {
			return false;
		}
		if (self::isReferenced($page, $file)) {
			return true;
		}
		if (!hash_equals($hash, self::filesHash([$file]))) {
			return true;
		}
		if ($removed) {
			$file->delete();
		}
		return false;
	}

	/**
	 * Removes every file a discarded draft uploaded (removeFile): the one of
	 * a single entry (`file`), those of several new entries (`files`).
	 *
	 * @return bool true when at least one file stays
	 */
	private static function removeFiles(Page $page, array $record, bool $removed): bool
	{
		$kept = false;
		$files = empty($record['file']) ? [] : [['name' => (string)$record['file'], 'hash' => (string)($record['fileHash'] ?? '')]];
		foreach ((array)($record['files'] ?? []) as $one) {
			if (is_array($one) && !empty($one['name'])) {
				$files[] = ['name' => (string)$one['name'], 'hash' => (string)($one['hash'] ?? '')];
			}
		}
		foreach ($files as $one) {
			$kept = self::removeFile($page, $one['name'], $one['hash'], $removed) || $kept;
		}
		return $kept;
	}

	/**
	 * replace: the new list decides every declared text field — a field left
	 * out is empty afterwards, so the draft is what the bot showed. A new row
	 * whose key matches a published row (Content::sameKey) keeps that row's
	 * undeclared columns and its image reference. A new key starts empty. A published key that is not in the new list leaves the
	 * list; its files stay on the page, as with delete.
	 *
	 * @param list<array<string, mixed>> $current the published rows
	 * @param list<array<string, mixed>> $entries the validated new rows
	 * @return array{rows: list<array<string, mixed>>, summary: array{kept: list<string>, added: list<string>, removed: list<string>, withoutImage: list<string>}}
	 */
	private static function replaced(array $collection, array $current, array $entries, string|null $imageField): array
	{
		$declared = $collection['fields'];
		$rows = [];
		$kept = [];
		$added = [];
		$withoutImage = [];
		foreach ($entries as $entry) {
			$key = Content::keyText($collection, $entry);
			$base = [];
			foreach ($current as $old) {
				if (Content::sameEntry($collection, $old, $entry)) {
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
			$oldKey = Content::keyText($collection, $old);
			$listed = false;
			foreach ($entries as $entry) {
				if (Content::sameEntry($collection, $entry, $old)) {
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

	/**
	 * The `key` of an update or delete: a text for a single-field key, an
	 * object with every key field for a composite key (also as a JSON string,
	 * like `fields`). No other fields, no blank part.
	 *
	 * @return string|array<string, string> the text, or field name => value
	 * @throws FudeError 422
	 */
	private static function requestKey(array $collection, string $action, mixed $key): string|array
	{
		$fields = Content::keyFields($collection);
		if (count($fields) === 1) {
			if (!is_string($key) || trim($key) === '') {
				throw FudeError::t($action === 'delete' ? 'collection.deleteKey' : 'collection.key', ['field' => Content::keyLabel($collection)]);
			}
			return $key;
		}
		if (is_string($key)) {
			$key = json_decode($key, true);
		}
		$parts = [];
		if (is_array($key) && !array_is_list($key) && array_diff(array_keys($key), $fields) === []) {
			foreach ($fields as $field) {
				$value = $key[$field] ?? null;
				if (is_scalar($value) && trim((string)$value) !== '') {
					$parts[$field] = (string)$value;
				}
			}
		}
		if (count($parts) !== count($fields)) {
			$example = [];
			foreach ($fields as $field) {
				$example[] = '"' . $field . '": "…"';
			}
			throw FudeError::t('collection.keyParts', ['action' => $action, 'fields' => implode(', ', $fields), 'example' => '{' . implode(', ', $example) . '}']);
		}
		return $parts;
	}

	/**
	 * Serves one `/fude/*` route: authenticates the bot, reads the request
	 * and answers with JSON. The work, the write lock and the log line are
	 * Actions'; a request refused before it gets there is logged here.
	 */
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
		} catch (FudeError $e) {
			Log::write($kirby, $entry + ['status' => $e->status, 'error' => $e->getMessage()], $now);
			return self::json(['ok' => false, 'error' => $e->getMessage()] + $e->data, $e->status);
		} catch (Throwable $e) {
			self::logFailure($e);
			Log::write($kirby, $entry + ['status' => 500, 'error' => $e::class . ': ' . $e->getMessage()], $now);
			return self::json(['ok' => false, 'error' => I18n::t(self::internalError($route))], 500);
		}
		// The bot never sets owner or ref: its drafts are confirmed by token only.
		$context = ['user' => $user, 'actor' => $entry['actor'], 'now' => $now];
		try {
			$data = match ($route) {
				'schema'  => Actions::schema($kirby, [], $context),
				'draft'   => Actions::draft($kirby, $input, $context, $kirby->request()->files()->data()),
				'publish' => Actions::publish($kirby, $input, $context),
				'discard' => Actions::discard($kirby, $input, $context),
			};
			return self::json(['ok' => true, 'format' => self::FORMAT] + $data, 200);
		} catch (FudeError $e) {
			return self::json(['ok' => false, 'error' => $e->getMessage()] + $e->data, $e->status);
		} catch (Throwable) {
			return self::json(['ok' => false, 'error' => I18n::t(self::internalError($route))], 500);
		} finally {
			// Actions runs as Kirby for the call only. The route leaves the
			// rest of the request as Kirby, as it always did.
			$kirby->impersonate('kirby');
		}
	}

	/** The route entry point: one request, with the current time in Europe/Berlin. */
	public static function respondNow(string $route): Response
	{
		return self::respond(App::instance(), new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')), $route);
	}

	/**
	 * Puts a changes version back as priorChanges() found it: deleted when it
	 * did not exist, otherwise its previous fields written again (replace(),
	 * not save(), which would merge the draft's fields back in). A write that
	 * changed nothing is not repeated.
	 *
	 * @param array<string, mixed>|null $prior
	 */
	private static function restoreChanges(Version $changes, array|null $prior): void
	{
		if ($prior === null) {
			if ($changes->exists()) {
				$changes->delete();
			}
		} elseif ($changes->read() !== $prior) {
			$changes->replace($prior);
		}
	}

	/**
	 * Undoes what a failed draft stored, then throws the failure again: the
	 * page's `changes` version goes back to what it held (gone, when there was
	 * none), the uploaded files and a draft page created for it go. Only what
	 * belongs to this attempt: a changes version is touched only if the
	 * attempt began to write it, never one an editor created in the meantime
	 * (a refused draft is rolled back too, and image processing takes
	 * seconds). Every step runs even if another fails. If a step fails, the
	 * page is in a state nobody knows: both errors go to the PHP error log,
	 * and the reply is a 500 that does not say "nothing changed".
	 *
	 * Only the default language is touched, the one a draft writes.
	 *
	 * A $failure that is a StoredFileError says Kirby stored an image and
	 * then threw (an `after` hook), so that the caller never got the file. The
	 * file is removed with the others, and the reply is about the original failure.
	 *
	 * @param list<File|null> $files the files this attempt uploaded
	 * @param $changes the page's changes version, only if this attempt began to write it; null otherwise and for a created page
	 * @param array<string, mixed>|null $prior its fields before the attempt (priorChanges()), null = there were none
	 * @param $created the draft page this attempt created, which takes its files with it
	 * @throws FudeError 500 when the undo itself fails
	 */
	private static function rollback(Throwable $failure, array $files, Version|null $changes = null, array|null $prior = null, Page|null $created = null): never
	{
		if ($failure instanceof StoredFileError) {
			if ($created === null) {
				$files[] = $failure->stored;
			}
			$failure = $failure->failure;
		}
		$errors = [];
		$undo = static function (callable $step) use (&$errors): void {
			try {
				$step();
			} catch (Throwable $e) {
				$errors[] = $e;
			}
		};
		if ($changes !== null) {
			$undo(fn () => self::restoreChanges($changes, $prior));
		}
		if ($created !== null) {
			$undo(fn () => $created->delete(true));
		}
		foreach ($files as $file) {
			if ($file !== null) {
				$undo(fn () => $file->delete());
			}
		}
		if ($errors === []) {
			throw $failure;
		}
		self::logFailure($failure);
		array_map(self::logFailure(...), $errors);
		throw FudeError::t('draft.rollback', [], 500);
	}

	// --- Routes --------------------------------------------------------------

	/**
	 * What the site allows: the declaration, with the current entries unless
	 * $entries is false. Called by Actions::schema().
	 *
	 * @internal
	 */
	public static function schema(App $kirby, array $collections, bool $entries = true): array
	{
		return [
			'site'        => Options::siteId($kirby),
			'language'    => I18n::language(),
			'collections' => (object)Config::schema($kirby, $collections, $entries),
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
		$keyFields = Content::keyFields($collection);
		$altField = $rule['alt'] ?? $keyFields[0];
		$alt = trim((string)($row[$altField] ?? $row[$keyFields[0]] ?? ''));
		// A composite key names the file by every part.
		$base = count($keyFields) === 1 ? trim((string)($row[$keyFields[0]] ?? $alt)) : Content::keyText($collection, $row);
		return Images::store($kirby, $page, $upload, $rule, $format, $base !== '' ? $base : $imageField, $alt);
	}

	/** The draft's day as Y-m-d, in the time zone of `sayamaapps.fude.timezone`. */
	private static function today(App $kirby, DateTimeImmutable $now): string
	{
		return $now->setTimezone(Options::timezone($kirby))->format('Y-m-d');
	}

	/**
	 * The page field a collection's `touch` sets on every draft: today's
	 * date in the time zone of `sayamaapps.fude.timezone`, as Y-m-d. With `editable`
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

	/**
	 * The upload named "image", or null when none was sent.
	 *
	 * @param array<string, mixed> $files the form parts with uploads
	 */
	private static function upload(array $files): array|null
	{
		return self::oneUpload($files['image'] ?? null);
	}

	/**
	 * The images of a create with several entries: form part `image<n>` is
	 * the image of entry n, counted from 0. A part that names no entry of
	 * this draft (a number too high, or any such part in another draft) is
	 * refused.
	 *
	 * @param array<string, mixed> $files the form parts with uploads
	 * @param $count how many entries the draft adds
	 * @return array<int, array> the uploads by entry index, in order
	 */
	private static function uploads(array $files, int $count): array
	{
		$out = [];
		foreach ($files as $name => $upload) {
			if (!preg_match('/^image(0|[1-9][0-9]*)$/D', (string)$name, $match)) {
				continue;
			}
			$one = self::oneUpload($upload);
			if ($one === null) {
				continue;
			}
			if ((int)$match[1] >= $count) {
				throw FudeError::t('image.index', ['name' => (string)$name]);
			}
			$out[(int)$match[1]] = $one;
		}
		ksort($out);
		return $out;
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
