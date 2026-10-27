<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

/**
 * POST /fude/draft on a structure collection: the changes version carries
 * the whole content plus the new row, the published version is untouched,
 * the preview URL renders the draft with its token, the record exists,
 * updates change only the named fields, Panel locks and pending Panel
 * changes block a draft.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class DraftTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$published = file_get_contents($tmp . '/content/standort/standort.txt');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'create', 'actor' => 'telegram:42',
				'fields' => ['name' => '  Anna   Neu ', 'rolle' => 'Medizinische Fachangestellte', 'abteilung' => 'empfang']]);
			same(200, $code, 'Draft created', $json);
			$draft = $json['draft'];
			same(
				['team', 'structure', 'create', 'standort', 'Anna Neu', 2, null, FUDE_BOT, 'telegram:42'],
				[$draft['collection'], $draft['kind'], $draft['action'], $draft['page'], $draft['key'], $draft['index'], $draft['file'], $draft['user'], $draft['actor']],
				'Record'
			);
			same(['name' => 'Anna Neu', 'rolle' => 'Medizinische Fachangestellte', 'abteilung' => 'empfang'], $draft['fields'], 'Whitespace collapsed');
			same(true, preg_match('/^[0-9a-f]{16}$/', $draft['id']) === 1 && preg_match('/^[0-9a-f]{32}$/', $json['token']) === 1, 'Id and token');
			same(false, isset($draft['token']), 'Token not inside the record copy');
			same(64, strlen($draft['hash']), 'Hash of the published state');

			// Published version untouched, changes version complete.
			$now = (string)preg_replace('/\n----\n\nUuid: [a-z0-9]+\n?$/', "\n", file_get_contents($tmp . '/content/standort/standort.txt'));
			same(rtrim($published), rtrim($now), 'Published file byte-identical (apart from the UUID Kirby adds on first access)');
			$kirby = $app();
			$page = $kirby->page('standort');
			$changes = $page->version('changes')->read();
			same('Standort', $changes['kurzname'], 'Changes version carries the other fields too (publish would null them otherwise)');
			same('Unser Haus in Musterstadt.', $changes['einleitung'], 'All fields');
			$rows = Kirby\Data\Data::decode($changes['team'], 'yaml');
			same(3, count($rows), 'Three rows in the draft');
			same(['name' => 'Anna Neu', 'rolle' => 'Medizinische Fachangestellte', 'abteilung' => 'empfang'], $rows[2], 'New row');
			same(2, $page->team()->toStructure()->count(), 'Public page still shows two');

			// Preview: the draft renders with the token, without it the published state.
			same(true, str_contains($json['preview'], '_version=changes') && str_contains($json['preview'], '_token='), 'Preview URL with token', $json['preview']);
			parse_str((string)parse_url($json['preview'], PHP_URL_QUERY), $query);
			$render = fn (array $q) => $app(['method' => 'GET', 'url' => 'http://example.invalid/standort', 'query' => $q])->page('standort')->render();
			same(true, str_contains($render($query), 'Anna Neu|Medizinische Fachangestellte|empfang|;'), 'Preview renders the draft');
			same(false, str_contains($render([]), 'Anna Neu'), 'Without token: published state');
			same(false, str_contains($render(['_version' => 'changes', '_token' => 'wrong']), 'Anna Neu'), 'Wrong token: published state');

			// Second draft on the same page while the first is pending: refused
			// (it would be published together with the first).
			[$code, $json2] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Noch Eine']]);
			same([423, 'The page has unsaved Panel changes. Save or discard them in the Panel first.'], [$code, $json2['error']], 'Pending draft blocks a second one');
			fude_call($app, 'discard', ['id' => $draft['id'], 'token' => $json['token']]);
			same(false, $app()->page('standort')->version('changes')->exists(), 'Discarded');

			// Update: only the named fields change, the rest of the row stays.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'update', 'key' => 'veronika beispiel', 'fields' => ['rolle' => 'Praxismanagerin']]);
			same(200, $code, 'Update draft', $json);
			same([1, 'veronika beispiel'], [$json['draft']['index'], $json['draft']['key']], 'Row index and key');
			$rows = Kirby\Data\Data::decode($app()->page('standort')->version('changes')->read()['team'], 'yaml');
			same(['name' => 'Veronika Beispiel', 'abteilung' => 'empfang', 'rolle' => 'Praxismanagerin'], $rows[1], 'Only rolle changed, name kept as published');
			same(['name' => 'Jörg Muster', 'rolle' => 'Facharzt für Allgemeinmedizin', 'abteilung' => 'aerzte'], $rows[0], 'Other row untouched');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);

			// Panel lock: another user's active changes version.
			$kirby = $app();
			$kirby->impersonate(FUDE_ADMIN);
			$kirby->page('standort')->version('changes')->save([...$kirby->page('standort')->version('latest')->read(), 'einleitung' => 'Admin tippt gerade']);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Neu']]);
			same([423, 'The page is open in the Panel right now. Save or discard there, then try again.'], [$code, $json['error']], 'Active Panel lock');
			// The admin's lock is active for ten minutes; locking off to clean up.
			$app(options: ['content.locking' => false])->page('standort')->version('changes')->delete();

			// Second collection on another page is independent.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Grippeimpfung', 'text' => "Ab Oktober.\nBitte anmelden."]]);
			same(200, $code, 'Draft on the home page', $json);
			same("Ab Oktober.\nBitte anmelden.", Kirby\Data\Data::decode($app()->page('home')->version('changes')->read()['meldungen'], 'yaml')[0]['text'], 'Textarea keeps line breaks');
			same('Willkommen', $app()->page('home')->version('changes')->read()['leitsatz'], 'Home: other fields carried');
			same(true, str_starts_with($json['preview'], 'http://example.invalid') && !str_contains($json['preview'], '/home') && str_contains($json['preview'], '_token=') && str_contains($json['preview'], '_version=changes'), 'Home page preview URL', $json['preview']);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same(1, count(glob($tmp . '/storage/fude/drafts/*.json') ?: []) === 0 ? 1 : 0, 'No records left');
		} finally {
			cleanup($tmp);
		}
	}
}
