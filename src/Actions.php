<?php

namespace Sayamaapps\Fude;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Cms\User;
use Throwable;

/**
 * The PHP API for other entry points: what the `/fude/*` routes do, callable
 * without HTTP. The routes (Service) call it too, so every entry point
 * checks, locks and logs the same way.
 *
 *   Actions::schema($kirby, ['entries' => false], $context)
 *   Actions::entries($kirby, ['collection' => 'team', 'key' => 'Jane Doe'], $context)
 *   Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jane Doe', 'fields' => [...]], $context, $files)
 *   Actions::publish($kirby, ['id' => '…'], $context)
 *   Actions::discard($kirby, ['id' => '…'], $context)
 *   Actions::drafts($kirby, ['collection' => 'team'], $context)
 *
 * `$input` is what the route's JSON body carries. Uploads are passed in as
 * `$files` (form part name => one `$_FILES` entry: `image`, `image<n>`);
 * nothing is read from the request.
 *
 * `$context` says who acts and how:
 *
 *   user       Kirby User, required: the account the draft record and the
 *              log name, and whose role `publishRoles` checks
 *   actor      ?string, who spoke on the channel (log and record only)
 *   owner      ?string, an opaque tag stored with a new draft; publish() and
 *              discard() accept a draft without its token when the context
 *              has the same non-empty owner; drafts() lists by it
 *   ref        ?string, a second opaque tag stored with a new draft
 *   wait       float, seconds to wait for the write lock (default 30)
 *   canPublish ?callable(User $user, array $draft): bool, replaces the
 *              `publishRoles` check; $draft is the record without token
 *   log        array<string, scalar|null>, extra fields for the log line
 *   now        ?DateTimeImmutable, the time of the call (default: now)
 *
 * Every method writes one log line, runs as Kirby (impersonation ends with
 * the call), throws FudeError for what the caller can act on (message
 * translated, `status`, `data`) and anything else for an internal error,
 * which is logged too. A context that breaks these rules is a programming
 * error: InvalidArgumentException, before anything is done or logged.
 *
 * Fude must be active (`sayamaapps.fude.active`) — the API does not check
 * it, the caller does, as the routes do.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Actions
{
	/** The context keys. */
	public const CONTEXT = ['user', 'actor', 'owner', 'ref', 'wait', 'canPublish', 'log', 'now'];

	/** Longest owner and ref tag, in characters. */
	public const MAX_TAG_LENGTH = 200;

	/** Log fields the context may not set: Fude's own. */
	public const RESERVED_LOG_FIELDS = ['at', 'route', 'user', 'actor', 'collection', 'action', 'key', 'draft', 'kept', 'status', 'error'];

	/**
	 * Discards drafts that expired more than one lifetime ago (older than
	 * twice `draftLifetime`) by the rules of discard(): only what is still
	 * the draft's own goes, Panel work on top stays. Until then an expired
	 * draft can be discarded by its caller and is listed by drafts(). Runs
	 * inside the write lock; a draft that cannot be discarded keeps its
	 * record and is noted in the PHP error log, the call goes on.
	 *
	 * @return int how many drafts were discarded
	 */
	private static function cleanup(App $kirby, array $collections, DateTimeImmutable $now): int
	{
		$limit = 2 * Drafts::lifetime($kirby);
		$done = 0;
		foreach (Drafts::all($kirby) as $record) {
			if ($now->getTimestamp() - (int)($record['createdTs'] ?? 0) <= $limit) {
				continue;
			}
			try {
				$entry = ['route' => 'cleanup', 'user' => null, 'actor' => null];
				if (!isset($collections[$record['collection'] ?? ''])) {
					// The collection is no longer declared: nothing to discard by its rules, the record goes.
					Drafts::delete($kirby, (string)$record['id']);
					$done++;
					continue;
				}
				Service::discardRecord($kirby, $collections, $record, $entry);
				Log::write($kirby, $entry + ['status' => 200], $now);
				$done++;
			} catch (Throwable $e) {
				Service::logFailure($e);
			}
		}
		return $done;
	}

	/**
	 * The checked context with every key set.
	 *
	 * @return array{user: User, actor: string|null, owner: string|null, ref: string|null, wait: float, canPublish: callable|null, log: array<string, scalar|null>, now: DateTimeImmutable}
	 * @throws InvalidArgumentException
	 */
	private static function context(array $context): array
	{
		$unknown = array_diff(array_keys($context), self::CONTEXT);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Fude: unknown context key(s): ' . implode(', ', $unknown));
		}
		$user = $context['user'] ?? null;
		if (!$user instanceof User) {
			throw new InvalidArgumentException('Fude: the context needs "user", a Kirby user');
		}
		$wait = $context['wait'] ?? WriteLock::WAIT;
		if (!is_int($wait) && !is_float($wait) || $wait < 0) {
			throw new InvalidArgumentException('Fude: "wait" is a number of seconds, 0 or more');
		}
		$canPublish = $context['canPublish'] ?? null;
		if ($canPublish !== null && !is_callable($canPublish)) {
			throw new InvalidArgumentException('Fude: "canPublish" is a callable');
		}
		$now = $context['now'] ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
		if (!$now instanceof DateTimeImmutable) {
			throw new InvalidArgumentException('Fude: "now" is a DateTimeImmutable');
		}
		return [
			'user'       => $user,
			'actor'      => self::tag($context, 'actor', 120),
			'owner'      => self::tag($context, 'owner', self::MAX_TAG_LENGTH),
			'ref'        => self::tag($context, 'ref', self::MAX_TAG_LENGTH),
			'wait'       => (float)$wait,
			'canPublish' => $canPublish,
			'log'        => self::logFields($context['log'] ?? []),
			'now'        => $now,
		];
	}

	/**
	 * Discards a draft: with `token`, or as its owner. An expired draft can
	 * still be discarded. Returns `id` and `kept` (true when Panel work on
	 * top of the draft stayed).
	 *
	 * @param array{id?: mixed, token?: mixed} $input
	 * @throws FudeError
	 */
	public static function discard(App $kirby, array $input, array $context): array
	{
		$ctx = self::context($context);
		return self::run($kirby, 'discard', $ctx, function (array $collections, array &$entry) use ($kirby, $input, $ctx): array {
			return WriteLock::run($kirby, function () use ($kirby, $collections, $input, $ctx, &$entry): array {
				return Service::discard($kirby, $collections, $input, $ctx['owner'], $entry);
			}, $ctx['wait']);
		});
	}

	/**
	 * Proposes a change as a draft. Input as for `POST /fude/draft`; images
	 * in $files. Returns `draft` (the record without token), `token` and
	 * `preview`. Removes long-expired drafts first (cleanup()).
	 *
	 * @param array<string, mixed> $files form part name => one $_FILES entry
	 * @throws FudeError
	 */
	public static function draft(App $kirby, array $input, array $context, array $files = []): array
	{
		$ctx = self::context($context);
		$tags = array_filter(['owner' => $ctx['owner'], 'ref' => $ctx['ref']], fn ($tag) => $tag !== null);
		return self::run($kirby, 'draft', $ctx, function (array $collections, array &$entry) use ($kirby, $input, $files, $tags, $ctx): array {
			return WriteLock::run($kirby, function () use ($kirby, $collections, $input, $files, $tags, $ctx, &$entry): array {
				self::cleanup($kirby, $collections, $ctx['now']);
				return Service::draft($kirby, $collections, $input, $files, $tags, $ctx['now'], $entry);
			}, $ctx['wait']);
		});
	}

	/**
	 * The open drafts of the context's owner, newest first, optionally only
	 * those of one `collection` or one `ref`. Each is the record without
	 * token, plus `expiresAt` and `expired` (an expired draft can only be
	 * discarded).
	 *
	 * @param array{collection?: mixed, ref?: mixed} $input
	 * @return array{drafts: list<array<string, mixed>>}
	 * @throws InvalidArgumentException without an owner in the context
	 * @throws FudeError
	 */
	public static function drafts(App $kirby, array $input, array $context): array
	{
		$ctx = self::context($context);
		if ($ctx['owner'] === null) {
			throw new InvalidArgumentException('Fude: drafts() needs an owner in the context');
		}
		return self::run($kirby, 'drafts', $ctx, function (array $collections, array &$entry) use ($kirby, $input, $ctx): array {
			$name = $input['collection'] ?? null;
			if ($name !== null) {
				$entry['collection'] = Service::collectionNamed($collections, $name)['name'];
			}
			$ref = $input['ref'] ?? null;
			$list = [];
			foreach (Drafts::all($kirby) as $record) {
				if (!is_string($record['owner'] ?? null) || !hash_equals($record['owner'], (string)$ctx['owner'])) {
					continue;
				}
				if (($name !== null && $record['collection'] !== $name) || ($ref !== null && ($record['ref'] ?? null) !== $ref)) {
					continue;
				}
				$list[] = Drafts::public($record) + [
					'expiresAt' => Drafts::expiresAt($kirby, $record),
					'expired'   => Drafts::isExpired($kirby, $record, $ctx['now']),
				];
			}
			return ['drafts' => $list];
		});
	}

	/**
	 * The published entries of one collection (declared fields only, as in
	 * the schema), optionally only the one with `key`, and what holds its
	 * page: `openDraft` is the Fude draft (`id`, `owner`, `preview`),
	 * `['panel' => true]` for unsaved Panel changes, or null. A draft is
	 * refused (423) while it is not null. For a pages collection every
	 * entry is a page of its own: `openDraft` is null and `openDrafts` lists
	 * `key` and `openDraft` of each entry that has one.
	 *
	 * @param array{collection?: mixed, key?: mixed} $input
	 * @throws FudeError
	 */
	public static function entries(App $kirby, array $input, array $context): array
	{
		$ctx = self::context($context);
		return self::run($kirby, 'entries', $ctx, function (array $collections, array &$entry) use ($kirby, $input): array {
			$collection = Service::collectionNamed($collections, $input['collection'] ?? null);
			$entry['collection'] = $collection['name'];
			$rows = Content::rows($kirby, $collection);
			$entries = Config::entries($kirby, $collection);
			$key = $input['key'] ?? null;
			$indexes = array_keys($rows);
			if ($key !== null && $key !== '' && $collection['kind'] !== 'page') {
				$entry['key'] = $key = Service::filterKey($collection, $key);
				$indexes = [Content::find($collection, $rows, $key)];
			}
			$reply = [
				'collection' => $collection['name'],
				'entries'    => array_map(fn (int $index): array => $entries[$index], $indexes),
				'openDraft'  => null,
			];
			if ($collection['kind'] !== 'pages') {
				$reply['openDraft'] = Service::openDraft(Content::page($kirby, $collection));
				return $reply;
			}
			$reply['openDrafts'] = [];
			foreach ($indexes as $index) {
				$page = $kirby->page($rows[$index]['_id']);
				$open = $page === null ? null : Service::openDraft($page);
				if ($open !== null) {
					$reply['openDrafts'][] = ['key' => Content::keyOf($collection, $rows[$index]), 'openDraft' => $open];
				}
			}
			return $reply;
		});
	}

	/**
	 * @return array<string, scalar|null>
	 * @throws InvalidArgumentException
	 */
	private static function logFields(mixed $fields): array
	{
		if (!is_array($fields)) {
			throw new InvalidArgumentException('Fude: "log" is an array of field name => value');
		}
		foreach ($fields as $name => $value) {
			if (!is_string($name) || $name === '' || in_array($name, self::RESERVED_LOG_FIELDS, true)) {
				throw new InvalidArgumentException('Fude: log field "' . $name . '" is not allowed');
			}
			if ($value !== null && !is_scalar($value)) {
				throw new InvalidArgumentException('Fude: log field "' . $name . '" takes a scalar or null');
			}
		}
		return $fields;
	}

	/**
	 * Publishes a draft: with `token`, or as its owner. Who may publish
	 * decides `canPublish` in the context, or the collection's
	 * `publishRoles`. Every other check stays (age, Panel lock, unsaved
	 * Panel changes, published content unchanged). Returns `id`, `url` and
	 * `hash`.
	 *
	 * @param array{id?: mixed, token?: mixed} $input
	 * @throws FudeError
	 */
	public static function publish(App $kirby, array $input, array $context): array
	{
		$ctx = self::context($context);
		return self::run($kirby, 'publish', $ctx, function (array $collections, array &$entry) use ($kirby, $input, $ctx): array {
			return WriteLock::run($kirby, function () use ($kirby, $collections, $input, $ctx, &$entry): array {
				return Service::publish($kirby, $collections, $input, $ctx['user'], $ctx['canPublish'], $ctx['owner'], $ctx['now'], $entry);
			}, $ctx['wait']);
		});
	}

	/**
	 * Runs one call: loads the declaration, runs $work as Kirby and writes
	 * the log line — with the outcome, also when $work throws.
	 *
	 * @param Closure(array, array): array $work gets the collections and the log entry (by reference)
	 */
	private static function run(App $kirby, string $route, array $ctx, Closure $work): array
	{
		I18n::setLanguage(Options::language($kirby));
		$now = $ctx['now'];
		$entry = ['route' => $route, 'user' => $ctx['user']->email(), 'actor' => $ctx['actor']] + $ctx['log'];
		try {
			$collections = Config::load($kirby);
			Options::timezone($kirby); // a wrong zone is loud on every call, not only on a date
			Options::trustedProxies($kirby); // so is a wrong proxy list
			// Writes need permissions the caller's role does not have — and
			// must not have anywhere else. The call runs as Kirby, and only the call.
			$data = $kirby->impersonate('kirby', function () use ($work, $collections, &$entry): array {
				return $work($collections, $entry);
			});
			Log::write($kirby, $entry + ['status' => 200], $now);
			return $data;
		} catch (FudeError $e) {
			Log::write($kirby, $entry + ['status' => $e->status, 'error' => $e->getMessage()], $now);
			throw $e;
		} catch (Throwable $e) {
			Service::logFailure($e);
			Log::write($kirby, $entry + ['status' => 500, 'error' => $e::class . ': ' . $e->getMessage()], $now);
			throw $e;
		}
	}

	/**
	 * What this site allows: `site`, `language` and `collections` as
	 * `GET /fude/schema` answers, without the entries of each collection
	 * when `entries` is false.
	 *
	 * @param array{entries?: bool} $input
	 * @throws FudeError
	 */
	public static function schema(App $kirby, array $input, array $context): array
	{
		$ctx = self::context($context);
		$entries = ($input['entries'] ?? true) !== false;
		return self::run($kirby, 'schema', $ctx, function (array $collections) use ($kirby, $entries): array {
			return Service::schema($kirby, $collections, $entries);
		});
	}

	/**
	 * A short text tag from the context: null when missing or empty.
	 *
	 * @throws InvalidArgumentException
	 */
	private static function tag(array $context, string $name, int $max): string|null
	{
		$value = $context[$name] ?? null;
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
			throw new InvalidArgumentException('Fude: "' . $name . '" is a text of at most ' . $max . ' characters without control characters');
		}
		return $value;
	}
}
