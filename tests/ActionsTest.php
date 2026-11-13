<?php

use Kirby\Cms\App;
use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Actions;
use Sayamaapps\Fude\Drafts;
use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\Service;

/**
 * The PHP API for other entry points (Actions): the same results as the
 * routes, the owner and ref tags of a draft, publish and discard by owner
 * without the token, the drafts list, the cleanup of long-expired drafts,
 * extra log fields, the blocking draft named in a 423, the lock wait, and
 * the publish permission callback.
 */
#[CoversClass(Actions::class)]
#[CoversClass(Service::class)]
#[CoversClass(Drafts::class)]
#[RunTestsInSeparateProcesses]
final class ActionsTest extends TestCase
{
	private const NOW = '2026-10-09 09:00';

	/** The context of a call as the bot account, at the fixture's time (plus $hours). */
	private static function as(App $kirby, array $context = [], float $hours = 0, string $who = FUDE_BOT): array
	{
		$now = new DateTimeImmutable(self::NOW, new DateTimeZone('Europe/Berlin'));
		return ['user' => $kirby->user($who), 'now' => $now->modify('+' . (int)round($hours * 3600) . ' seconds')] + $context;
	}

	/** Calls $f and returns the FudeError it throws. */
	private static function refused(callable $f, string $what): FudeError
	{
		try {
			$f();
		} catch (FudeError $e) {
			return $e;
		}
		self::fail($what . ': no FudeError');
	}

	/** The published or changed rows of a structure field. */
	private static function rows(App $kirby, string $page, string $field, string $version = 'latest'): array
	{
		return Data::decode($kirby->page($page)->version($version)->read()[$field] ?? '', 'yaml');
	}

	/** F2: a call of Actions changes the content exactly as the route does with the same input. */
	public function testActionsGiveTheSameContentAsTheRoute(): void
	{
		$input = ['collection' => 'team', 'action' => 'create', 'fields' => ['name' => 'Ada Beispiel', 'rolle' => 'Empfang', 'abteilung' => 'empfang']];
		$results = [];
		// One site, both ways: the content as the fixture made it is put back before the second.
		[$tmp, $app] = fude_fixture();
		Kirby\Filesystem\Dir::copy($tmp . '/content', $tmp . '/content-fixture');
		try {
			foreach (['route', 'actions'] as $way) {
				Kirby\Filesystem\Dir::remove($tmp . '/content');
				Kirby\Filesystem\Dir::remove($tmp . '/storage/fude');
				Kirby\Filesystem\Dir::copy($tmp . '/content-fixture', $tmp . '/content');
				$files = ['image' => fude_upload($tmp, 1200, 800)];
				if ($way === 'route') {
					[$code, $draft] = fude_call($app, 'draft', $input + ['actor' => 'chat:1'], files: $files);
					same(200, $code, 'Route draft', $draft);
					[$code, $published] = fude_call($app, 'publish', ['id' => $draft['draft']['id'], 'token' => $draft['token'], 'actor' => 'chat:1']);
					same(200, $code, 'Route publish', $published);
				} else {
					$kirby = $app();
					$draft = Actions::draft($kirby, $input, self::as($kirby, ['actor' => 'chat:1']), $files);
					$published = Actions::publish($app(), ['id' => $draft['draft']['id'], 'token' => $draft['token']], self::as($kirby, ['actor' => 'chat:1']));
				}
				$kirby = $app();
				$rows = self::rows($kirby, 'standort', 'team');
				$images = $kirby->page('standort')->images();
				same(1, $images->count(), $way . ': one image stored');
				$results[$way] = [
					// The image reference is a fresh UUID each time; that it is the stored image is enough.
					'rows'    => array_map(fn (array $row) => array_diff_key($row, ['foto' => true]), $rows),
					'foto'    => $images->first()->uuid()->toString() === ($rows[2]['foto'][0] ?? null),
					'other'   => array_diff_key($kirby->page('standort')->version('latest')->read(), ['team' => true, 'uuid' => true]),
					'changes' => $kirby->page('standort')->version('changes')->exists(),
					'record'  => array_diff_key($draft['draft'], array_flip(['id', 'createdAt', 'createdTs', 'file', 'fileHash', 'hash', 'changesHash'])),
					'reply'   => array_values(array_diff(array_keys($draft), ['ok', 'format'])),
					'publish' => array_values(array_diff(array_keys($published), ['ok', 'format'])),
					'log'     => array_map(fn (array $line) => array_diff_key($line, ['at' => true, 'draft' => true]), fude_log($tmp)),
				];
			}
		} finally {
			cleanup($tmp);
		}
		same($results['route'], $results['actions'], 'Same rows, same image, same record, same log lines');
		same(true, $results['actions']['foto'], 'The new row points at the stored image');
		same('Ada Beispiel', $results['actions']['rows'][2]['name'], 'The new row is there');
	}

	/** F2: the schema, with and without entries, and the entries of one collection. */
	public function testSchemaAndEntries(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			[, $json] = fude_call($app, 'schema');
			$schema = Actions::schema($kirby, [], self::as($kirby));
			same(null, $kirby->user(), 'The call ran as Kirby, and only the call');
			same(json_decode(json_encode($json['collections']), true), json_decode(json_encode($schema['collections']), true), 'Same collections as the route');
			same([$json['site'], $json['language']], [$schema['site'], $schema['language']], 'Same site and language');

			$bare = json_decode(json_encode(Actions::schema($kirby, ['entries' => false], self::as($kirby))['collections']), true);
			same(false, array_key_exists('entries', $bare['team']), 'Without entries');
			$withEntries = json_decode(json_encode($json['collections']), true);
			unset($withEntries['team']['entries'], $withEntries['aktuelles']['entries'], $withEntries['ohnebild']['entries'], $withEntries['news']['entries']);
			same($withEntries, $bare, 'Otherwise the same declaration');

			$team = Actions::entries($kirby, ['collection' => 'team'], self::as($kirby));
			same(['collection' => 'team', 'entries' => $json['collections']['team']['entries'], 'openDraft' => null], $team, 'Entries as in the schema, nothing open');
			$one = Actions::entries($kirby, ['collection' => 'team', 'key' => ' veronika beispiel '], self::as($kirby));
			same(['Veronika Beispiel'], array_column($one['entries'], 'name'), 'Filtered by key');
			same(404, self::refused(fn () => Actions::entries($kirby, ['collection' => 'team', 'key' => 'Nobody'], self::as($kirby)), 'Unknown key')->status, 'Unknown key: 404');
			same(422, self::refused(fn () => Actions::entries($kirby, ['collection' => 'team', 'key' => ['x']], self::as($kirby)), 'Bad key')->status, 'A key that is no text: 422');
			same(404, self::refused(fn () => Actions::entries($kirby, ['collection' => 'nope'], self::as($kirby)), 'Unknown collection')->status, 'Unknown collection: 404');
			same(['collection' => 'news', 'entries' => [], 'openDraft' => null, 'openDrafts' => []], Actions::entries($kirby, ['collection' => 'news'], self::as($kirby)), 'A pages collection lists open drafts per entry');

			$log = fude_log($tmp);
			same(['schema', 'schema', 'schema', 'entries', 'entries', 'entries', 'entries', 'entries', 'entries'], array_column($log, 'route'), 'Every call logged');
			same([200, 404], [$log[4]['status'], $log[5]['status']], 'with its outcome');
			same('veronika beispiel', $log[4]['key'], 'and the key filter, trimmed');
		} finally {
			cleanup($tmp);
		}
	}

	/** F3: owner and ref are stored with the draft and filter the drafts list; the route stores neither. */
	public function testOwnerAndRefAreStoredAndFiltered(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$a = Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['owner' => 'user-1', 'ref' => 'link-a']));
			$b = Actions::draft($app(), ['collection' => 'aktuelles', 'fields' => ['titel' => 'Sprechstunde', 'text' => 'Neu.']], self::as($kirby, ['owner' => 'user-1', 'ref' => 'link-b'], 1));
			[, $rest] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Urlaub', 'text' => 'Zu.']]);

			same(['user-1', 'link-a'], [$a['draft']['owner'], $a['draft']['ref']], 'The reply carries the tags');
			$record = Drafts::read($app(), $a['draft']['id']);
			same(['user-1', 'link-a'], [$record['owner'], $record['ref']], 'The record stores them');
			$restRecord = Drafts::read($app(), $rest['draft']['id']);
			same([false, false], [array_key_exists('owner', $restRecord), array_key_exists('ref', $restRecord)], 'A route draft has no tags');
			same(false, array_key_exists('owner', $rest['draft']), 'and the route reply is as before');

			$list = Actions::drafts($app(), [], self::as($kirby, ['owner' => 'user-1'], 2));
			same([$b['draft']['id'], $a['draft']['id']], array_column($list['drafts'], 'id'), 'The owner\'s drafts, newest first');
			same([false, false], [isset($list['drafts'][0]['token']), $list['drafts'][0]['expired']], 'without token, not expired');
			same(date(DATE_ATOM, $list['drafts'][0]['createdTs'] + Drafts::DEFAULT_LIFETIME), $list['drafts'][0]['expiresAt'], 'with the end of its lifetime');
			same([$a['draft']['id']], array_column(Actions::drafts($app(), ['ref' => 'link-a'], self::as($kirby, ['owner' => 'user-1']))['drafts'], 'id'), 'Filtered by ref');
			same([$b['draft']['id']], array_column(Actions::drafts($app(), ['collection' => 'aktuelles'], self::as($kirby, ['owner' => 'user-1']))['drafts'], 'id'), 'Filtered by collection');
			same([], Actions::drafts($app(), [], self::as($kirby, ['owner' => 'user-2']))['drafts'], 'Another owner sees none');
			throws(fn () => Actions::drafts($app(), [], self::as($kirby)), 'needs an owner', 'Listing needs an owner', InvalidArgumentException::class);
		} finally {
			cleanup($tmp);
		}
	}

	/** F3: publish and discard by owner match without token; another or an empty owner is refused; the token works as before. */
	public function testPublishAndDiscardByOwner(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$own = self::as($kirby, ['owner' => 'user-1']);
			$a = Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], $own);
			$id = $a['draft']['id'];

			$other = self::refused(fn () => Actions::publish($app(), ['id' => $id], self::as($kirby, ['owner' => 'user-2'])), 'Other owner');
			same([404, 'Unknown draft "' . $id . '".'], [$other->status, $other->getMessage()], 'Another owner: as an unknown draft');
			$none = self::refused(fn () => Actions::publish($app(), ['id' => $id], self::as($kirby, ['owner' => ''])), 'Empty owner');
			same([403, 'The confirmation token does not match this draft.'], [$none->status, $none->getMessage()], 'An empty owner needs the token');
			same(403, self::refused(fn () => Actions::discard($app(), ['id' => $id], self::as($kirby)), 'No owner')->status, 'No owner, no token: 403');
			same(403, self::refused(fn () => Actions::publish($app(), ['id' => $id, 'token' => 'wrong'], $own), 'Wrong token')->status, 'A given token is checked, owner or not');
			[$code, $json] = fude_call($app, 'publish', ['id' => $id]);
			same([403, false], [$code, $json['ok']], 'The route without token: 403 as before');

			$published = Actions::publish($app(), ['id' => $id], $own);
			same($id, $published['id'], 'The owner publishes without token');
			same('Chefarzt', self::rows($app(), 'standort', 'team')[0]['rolle'], 'and the change is live');

			$b = Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Oberarzt']], $own);
			same(['id' => $b['draft']['id'], 'kept' => false], Actions::discard($app(), ['id' => $b['draft']['id']], $own), 'The owner discards without token');
			same(false, $app()->page('standort')->version('changes')->exists(), 'and the draft is gone');

			// A tagged draft still takes its token on the route.
			$c = Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Hausarzt']], $own);
			[$code, $json] = fude_call($app, 'publish', ['id' => $c['draft']['id'], 'token' => $c['token']]);
			same([200, true], [$code, $json['ok']], 'Token on the route');
		} finally {
			cleanup($tmp);
		}
	}

	/** F3: an expired draft cannot be published but can be discarded, by token and by owner. */
	public function testAnExpiredDraftCanBeDiscarded(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$own = self::as($kirby, ['owner' => 'user-1'], 49);
			$a = Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['owner' => 'user-1']));
			same(410, self::refused(fn () => Actions::publish($app(), ['id' => $a['draft']['id']], $own), 'Expired')->status, 'Expired: no publish');
			same([true], array_column(Actions::drafts($app(), [], $own)['drafts'], 'expired'), 'Listed as expired');
			same(false, Actions::discard($app(), ['id' => $a['draft']['id']], $own)['kept'], 'Expired: discarded by its owner');
			same(false, $app()->page('standort')->version('changes')->exists(), 'The changes are gone');

			$b = Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Oberarzt']], self::as($kirby, [], 49));
			same(false, Actions::discard($app(), ['id' => $b['draft']['id'], 'token' => $b['token']], self::as($kirby, [], 100))['kept'], 'Expired: discarded with its token');
		} finally {
			cleanup($tmp);
		}
	}

	/**
	 * F3: a draft that expired more than one lifetime ago is discarded by the
	 * next draft: its own changes go, Panel work on top stays, the record goes.
	 */
	public function testCleanupDiscardsLongExpiredDrafts(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$own = ['owner' => 'user-1'];
			$a = Actions::draft($kirby, ['collection' => 'aktuelles', 'fields' => ['titel' => 'Sprechstunde', 'text' => 'Neu.']], self::as($kirby, $own));
			$b = Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, $own, 0.5));
			// A Panel editor works on top of draft A.
			$home = $app()->page('home')->version('changes');
			$home->save([...$home->read(), 'leitsatz' => 'Aus dem Panel']);

			// 60 hours on: both expired, still within the second lifetime. They stay.
			$c = Actions::draft($app(), ['collection' => 'news', 'fields' => ['title' => 'Urlaub', 'text' => 'Zu.']], self::as($kirby, $own, 60));
			same([$c['draft']['id'], $b['draft']['id'], $a['draft']['id']], array_column(Actions::drafts($app(), [], self::as($kirby, $own, 60))['drafts'], 'id'), 'Expired drafts stay for one more lifetime');

			// 97 hours on: A and B are older than two lifetimes.
			$d = Actions::draft($app(), ['collection' => 'news', 'fields' => ['title' => 'Ferien', 'text' => 'Zu.']], self::as($kirby, $own, 97));
			same([$d['draft']['id'], $c['draft']['id']], array_column(Actions::drafts($app(), [], self::as($kirby, $own, 97))['drafts'], 'id'), 'The records of A and B are gone, C and D stay');
			same(false, $app()->page('standort')->version('changes')->exists(), 'B\'s changes were discarded');
			same('Aus dem Panel', $app()->page('home')->version('changes')->read()['leitsatz'], 'A\'s page keeps the Panel work');
			$cleanups = array_values(array_filter(fude_log($tmp), fn (array $line) => $line['route'] === 'cleanup'));
			same([[$b['draft']['id'], false, 200], [$a['draft']['id'], true, 200]], array_map(fn (array $l) => [$l['draft'], $l['kept'], $l['status']], $cleanups), 'Each cleanup is logged, newest first, A kept');
		} finally {
			cleanup($tmp);
		}
	}

	/** F3: extra log fields go into the log line; Fude's own fields cannot be set. */
	public function testExtraLogFields(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$log = ['channel' => 'chat', 'ref' => 'link-a', 'tool' => 'propose'];
			$a = Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['actor' => 'chat:7', 'log' => $log]));
			self::refused(fn () => Actions::publish($app(), ['id' => $a['draft']['id']], self::as($kirby, ['log' => ['channel' => 'chat']])), 'No token');
			$lines = fude_log($tmp);
			same(
				['route' => 'draft', 'user' => FUDE_BOT, 'actor' => 'chat:7', 'channel' => 'chat', 'ref' => 'link-a', 'tool' => 'propose', 'collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'draft' => $a['draft']['id'], 'status' => 200],
				array_diff_key($lines[0], ['at' => true]),
				'The extra fields follow the actor'
			);
			same(['chat', 403], [$lines[1]['channel'], $lines[1]['status']], 'also on a refusal');
			foreach ([['status' => 'x'], ['route' => 'x'], ['n' => ['nested']], [0 => 'x']] as $bad) {
				throws(fn () => Actions::schema($app(), [], self::as($kirby, ['log' => $bad])), 'log field', 'Refused: ' . json_encode($bad), InvalidArgumentException::class);
			}
			throws(fn () => Actions::schema($app(), [], ['now' => new DateTimeImmutable()]), '"user"', 'A user is required', InvalidArgumentException::class);
			throws(fn () => Actions::schema($app(), [], self::as($kirby, ['owner' => "a\nb"])), '"owner"', 'No control characters', InvalidArgumentException::class);
			throws(fn () => Actions::schema($app(), [], self::as($kirby, ['token' => 'x'])), 'unknown context key', 'Unknown keys refused', InvalidArgumentException::class);
			same(2, count(fude_log($tmp)), 'A refused context writes no log line');
		} finally {
			cleanup($tmp);
		}
	}

	/** F4: the 423 for unsaved changes names the Fude draft behind them; entries() shows what holds a page. */
	public function testPendingNamesTheBlockingDraft(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$a = Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['owner' => 'user-1']));
			$open = ['id' => $a['draft']['id'], 'owner' => 'user-1', 'preview' => $a['preview']];

			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => ['rolle' => 'Leitung']]);
			same([423, 'The page has unsaved Panel changes. Save or discard them in the Panel first.', $open], [$code, $json['error'], $json['draft'] ?? null], 'The route names the draft, without its token');
			$e = self::refused(fn () => Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => ['rolle' => 'Leitung']], self::as($kirby)), 'Pending');
			same([423, ['draft' => $open]], [$e->status, $e->data], 'So does Actions');
			same($open, Actions::entries($app(), ['collection' => 'team'], self::as($kirby))['openDraft'], 'entries() shows it before a draft is tried');

			// A route draft has no owner.
			[, $rest] = fude_call($app, 'draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Sprechstunde', 'text' => 'Neu.']]);
			same(['id' => $rest['draft']['id'], 'owner' => null, 'preview' => $rest['preview']], Actions::entries($app(), ['collection' => 'aktuelles'], self::as($kirby))['openDraft'], 'A route draft: owner null');

			// Unsaved Panel changes without a Fude draft: as before, no draft named.
			Actions::discard($app(), ['id' => $a['draft']['id']], self::as($kirby, ['owner' => 'user-1']));
			$changes = $app()->page('standort')->version('changes');
			$changes->save([...$app()->page('standort')->version('latest')->read(), 'kurzname' => 'Panel']);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => ['rolle' => 'Leitung']]);
			same([423, false], [$code, array_key_exists('draft', $json)], 'Panel changes: 423 without a draft');
			same(['panel' => true], Actions::entries($app(), ['collection' => 'team'], self::as($kirby))['openDraft'], 'entries() says Panel');

			// A pages collection: each entry is a page of its own.
			[, $note] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Urlaub', 'text' => 'Zu.']]);
			fude_call($app, 'publish', ['id' => $note['draft']['id'], 'token' => $note['token']]);
			$u = Actions::draft($app(), ['collection' => 'news', 'action' => 'update', 'key' => 'Urlaub', 'fields' => ['text' => 'Offen.']], self::as($kirby, ['owner' => 'user-1']));
			same(
				['openDraft' => null, 'openDrafts' => [['key' => 'Urlaub', 'openDraft' => ['id' => $u['draft']['id'], 'owner' => 'user-1', 'preview' => $u['preview']]]]],
				array_intersect_key(Actions::entries($app(), ['collection' => 'news'], self::as($kirby)), ['openDraft' => true, 'openDrafts' => true]),
				'Per entry'
			);
		} finally {
			cleanup($tmp);
		}
	}

	/** F4: the lock wait of the context is honoured: 1 s answers 503 after about 1 s. */
	public function testLockWait(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			mkdir($tmp . '/storage/fude', 0775, true);
			$other = fopen($tmp . '/storage/fude/write.lock', 'c');
			same(true, flock($other, LOCK_EX), 'The test holds the lock');
			try {
				$start = microtime(true);
				$e = self::refused(fn () => Actions::draft($kirby, ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['wait' => 1])), 'Busy');
				$took = microtime(true) - $start;
				same([503, 'Another change is being saved right now. Try again in a moment.'], [$e->status, $e->getMessage()], 'Busy');
				ok($took >= 0.95 && $took < 3, 'after about one second', $took);
				same(503, fude_log($tmp)[0]['status'], 'Logged');
			} finally {
				flock($other, LOCK_UN);
				fclose($other);
			}
			same(false, $app()->page('standort')->version('changes')->exists(), 'Nothing stored');
		} finally {
			cleanup($tmp);
		}
	}

	/** F5: a publish permission callback replaces the publishRoles check. */
	public function testPublishPermissionCallback(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app();
			$draft = fn () => Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chefarzt']], self::as($kirby, ['owner' => 'user-1']));

			// The admin role is not in the default publishRoles (fude).
			$a = $draft();
			same(403, self::refused(fn () => Actions::publish($app(), ['id' => $a['draft']['id']], self::as($kirby, ['owner' => 'user-1'], who: FUDE_ADMIN)), 'Admin')->status, 'Without callback: publishRoles decides');
			$seen = null;
			$allow = function ($user, array $draft) use (&$seen): bool {
				$seen = [$user->email(), $draft];
				return true;
			};
			Actions::publish($app(), ['id' => $a['draft']['id']], self::as($kirby, ['owner' => 'user-1', 'canPublish' => $allow], who: FUDE_ADMIN));
			same([FUDE_ADMIN, 'team', 'user-1', false], [$seen[0], $seen[1]['collection'], $seen[1]['owner'], isset($seen[1]['token'])], 'The callback gets the user and the draft without token');
			same('Chefarzt', self::rows($app(), 'standort', 'team')[0]['rolle'], 'and its yes publishes');

			// The bot role is in publishRoles; a callback that says no wins.
			$b = Actions::draft($app(), ['collection' => 'team', 'action' => 'update', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Hausarzt']], self::as($kirby, ['owner' => 'user-1']));
			$e = self::refused(fn () => Actions::publish($app(), ['id' => $b['draft']['id']], self::as($kirby, ['owner' => 'user-1', 'canPublish' => fn () => false])), 'Callback no');
			same([403, 'This account may not publish this collection.'], [$e->status, $e->getMessage()], 'A no refuses');
			same(true, $app()->page('standort')->version('changes')->exists(), 'The draft stays');
		} finally {
			cleanup($tmp);
		}
	}
}
