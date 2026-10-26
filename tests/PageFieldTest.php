<?php

use Sayamaapps\Fude\Config;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

const PF_TERMIN = <<<'YML'
    title: Termin
    fields:
      startdate:
        label: Beginn
        type: date
        required: true
      vorort:
        label: Vor Ort
        type: toggle
        default: true
      ort:
        label: Ort
        type: pages
        multiple: false
        query: site.find('orte').childrenAndDrafts
        when:
          vorort: true
        required: true
      ortid:
        label: Ort (id)
        type: pages
        max: 1
        query: site.find('orte')
        store: id
      leiter:
        label: Leitung
        type: pages
        query: site.find('orte').children
      frei:
        label: Freie Wahl
        type: pages
        multiple: false
      leer:
        label: Leer
        type: pages
        multiple: false
        query: site.find('orte').children.filterBy('title', 'Niemand')
      kaputt:
        label: Kaputt
        type: pages
        multiple: false
        query: site.title
    YML;

const PF_ANFAHRT = <<<'YML'
    title: Anfahrt
    fields:
      treffpunkt:
        label: Treffpunkt
        type: pages
        multiple: false
        query: site.find('orte').children
        help: Wo sich die Gruppe trifft.
        fude: true
      stationen:
        label: Stationen
        type: structure
        fude: {key: name}
        fields:
          name:
            label: Name
            type: text
          ort:
            label: Ort
            type: pages
            multiple: false
            query: site.find('orte').children
            fude: true
          nebenort:
            label: Nebenort
            type: pages
    YML;

const PF_PFLICHT = <<<'YML'
    title: Pflichttermin
    fields:
      leer:
        label: Leer
        type: pages
        multiple: false
        query: site.find('orte').children.filterBy('title', 'Niemand')
        required: true
    YML;

/**
 * Page fields (`type: page`, backed by a blueprint `pages` field that holds
 * one page and names its pages with a query): the options are the page
 * titles, the bot sends a title, the page stores the reference as the Panel
 * does (`- page://<uuid>`, or the id with `store: id`). Covers a pages
 * collection (create, update, publish, entries; the venue of an event,
 * required while a toggle is on), a page collection (clear), structure rows
 * (create, replace), the configuration errors, a query that yields nothing,
 * and the blueprint markers. The pages "orte", "kalender" and "anfahrt"
 * live in this file only.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class PageFieldTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			foreach (['orte/1_atelier', 'orte/2_saal', 'orte/3_saal-2', 'orte/_drafts/scheune', 'kalender', 'anfahrt'] as $dir) {
				mkdir($tmp . '/content/' . $dir, 0700, true);
			}
			file_put_contents($tmp . '/content/orte/orte.txt', "Title: Orte\n");
			file_put_contents($tmp . '/content/orte/1_atelier/default.txt', "Title: Atelier Musterstadt\n\n----\n\nUuid: atelieruuid00001\n");
			file_put_contents($tmp . '/content/orte/2_saal/default.txt', "Title: Saal\n\n----\n\nUuid: saaluuid000000001\n");
			file_put_contents($tmp . '/content/orte/3_saal-2/default.txt', "Title: saal\n\n----\n\nUuid: saaluuid000000002\n");
			file_put_contents($tmp . '/content/orte/_drafts/scheune/default.txt', "Title: Scheune\n\n----\n\nUuid: scheuneuuid000001\n");
			file_put_contents($tmp . '/content/kalender/kalender.txt', "Title: Kalender\n");
			file_put_contents($tmp . '/site/blueprints/pages/termin.yml', PF_TERMIN);
			file_put_contents($tmp . '/site/blueprints/pages/pflichttermin.yml', PF_PFLICHT);
			file_put_contents($tmp . '/site/templates/termin.php', '<?php echo "TERMIN:", $page->title(), "|", $page->ort()->toPage()?->title();');
			file_put_contents($tmp . '/content/anfahrt/anfahrt.txt', "Title: Anfahrt\n\n----\n\nTreffpunkt:\n\n- page://saaluuid000000001\n\n----\n\nStationen: \n");
			file_put_contents($tmp . '/site/blueprints/pages/anfahrt.yml', PF_ANFAHRT);

			$options = fn (string $name, string $php) => fude_declare($tmp, $name, $php) + ['fude.blueprints' => false];
			$withError = fn (string $message) => [500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: ' . $message];
			$termine = <<<'PHP'
            ['collections' => [
                'termine' => ['kind' => 'pages', 'parent' => 'kalender', 'template' => 'termin', 'key' => 'title',
                    'actions' => ['create', 'update'], 'fields' => [
                    'title' => 'text', 'startdate' => 'date', 'ort' => 'page', 'ortid' => 'page', 'leer' => 'page',
                ]],
                'anfahrt' => ['kind' => 'page', 'page' => 'anfahrt', 'fields' => ['treffpunkt' => 'page']],
                'stationen' => ['kind' => 'structure', 'page' => 'anfahrt', 'field' => 'stationen', 'key' => 'name',
                    'actions' => ['create', 'replace'], 'fields' => ['name' => 'text', 'ort' => 'page']],
            ]]
            PHP;
			$opt = $options('termine', $termine);

			// (a) Schema: the titles are the options, two equal titles carry the page id.
			// Drafts count where the query names them (childrenAndDrafts). A query over
			// a page stands for its children (ortid). An empty result leaves the field out,
			// even a required one (leer).
			[$code, $json] = fude_call($app, 'schema', options: $opt);
			same(200, $code, 'Configuration with page fields is valid', $json['error'] ?? null);
			$ort = $json['collections']['termine']['fields']['ort'] ?? null;
			same(['type' => 'page', 'label' => 'Ort', 'required' => true, 'description' => '',
				'options' => ['Atelier Musterstadt', 'Saal (orte/saal)', 'saal (orte/saal-2)', 'Scheune']], $ort, 'Page field in the schema');
			same(['Atelier Musterstadt', 'Saal (orte/saal)', 'saal (orte/saal-2)'], $json['collections']['termine']['fields']['ortid']['options'] ?? null, 'A query that returns a page: its children');
			ok(!isset($json['collections']['termine']['fields']['leer']), 'Empty query result: left out, even when required', $json['collections']['termine']['fields']);
			same([['treffpunkt' => 'Saal (orte/saal)']], $json['collections']['anfahrt']['entries'] ?? null, 'Entries show the stored reference as its option value');
			$c = Config::load($app(options: $opt));
			same(['uuid', 'id'], [$c['termine']['fields']['ort']['store'], $c['termine']['fields']['ortid']['store']], 'store from the blueprint');
			ok(!array_key_exists('pages', $json['collections']['termine']['fields']['ort']), 'The page ids stay internal');

			// (b) Create: the title in any case; the page stores the reference as the Panel does.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Druckwerkstatt', 'startdate' => '2026-11-14', 'ort' => 'atelier  musterstadt', 'ortid' => 'Saal (orte/saal)']], options: $opt);
			same(200, $code, 'Create with a page field');
			$draft = $json['draft'];
			same(['Atelier Musterstadt', 'Saal (orte/saal)'], [$draft['fields']['ort'], $draft['fields']['ortid']], 'The draft record keeps the titles');
			$stored = $app()->page($draft['page'])->version('latest')->read();
			same('- page://atelieruuid00001', $stored['ort'] ?? null, 'Stored as a list with the page UUID, as the Panel stores it');
			same('- orte/saal', $stored['ortid'] ?? null, 'store: id stores the page id');
			[$code, $j] = fude_call($app, 'publish', ['id' => $draft['id'], 'token' => $json['token']], options: $opt);
			same(200, $code, 'Published', $j);
			same('TERMIN:Druckwerkstatt|Atelier Musterstadt', $app()->page($draft['page'])->render(), 'The template finds the page');
			$entries = fude_call($app, 'schema', options: $opt)[1]['collections']['termine']['entries'];
			same([['title' => 'Druckwerkstatt', 'startdate' => '2026-11-14', 'ort' => 'Atelier Musterstadt', 'ortid' => 'Saal (orte/saal)']], $entries, 'Entries carry the titles, ready for the next create');

			// (c) Refused: an unknown title, a missing required page, a UUID instead of a title.
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'X', 'startdate' => '2026-11-15', 'ort' => 'Turnhalle']], options: $opt);
			same([422, 'Field "ort" must be one of: Atelier Musterstadt, Saal (orte/saal), saal (orte/saal-2), Scheune.'], [$code, $j['error'] ?? null], 'Unknown title refused');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'X', 'startdate' => '2026-11-15', 'ort' => 'page://atelieruuid00001']], options: $opt);
			same(422, $code, 'A reference is not an option value');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'X', 'startdate' => '2026-11-15']], options: $opt);
			same([422, 'Field "ort" is required.'], [$code, $j['error'] ?? null], 'Required page field missing on create');
			same(0, $app()->page('kalender')->drafts()->count(), 'Refused creates leave no page');

			// (d) Update: another page, a draft page from the query.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'action' => 'update', 'key' => 'Druckwerkstatt', 'fields' => ['ort' => 'Scheune']], options: $opt);
			same(200, $code, 'Update to a draft page of the query');
			same(['page://scheuneuuid000001'], Kirby\Data\Data::decode($app()->page($draft['page'])->version('changes')->read()['ort'], 'yaml'), 'Changes version holds the new reference');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Discarded');

			// (e) A page collection: change, then clear an optional page field.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'anfahrt', 'action' => 'update', 'fields' => ['treffpunkt' => 'Atelier Musterstadt']], options: $opt);
			same(200, $code, 'Page collection: page field set');
			same(['page://atelieruuid00001'], Kirby\Data\Data::decode($app()->page('anfahrt')->version('changes')->read()['treffpunkt'], 'yaml'), 'Page collection stores the reference');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Discarded');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'anfahrt', 'action' => 'update', 'fields' => ['treffpunkt' => '']], options: $opt);
			same(200, $code, 'Optional page field cleared', $json);
			same('', $app()->page('anfahrt')->version('changes')->read()['treffpunkt'] ?? null, 'Cleared field is empty');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Discarded');

			// (f) A structure row: the reference is a list inside the row.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'stationen', 'fields' => ['name' => 'Start', 'ort' => 'saal (orte/saal-2)']], options: $opt);
			same(200, $code, 'Structure row with a page field', $json);
			same([['name' => 'Start', 'ort' => ['page://saaluuid000000002']]], Kirby\Data\Data::decode($app()->page('anfahrt')->version('changes')->read()['stationen'], 'yaml'), 'Row stores the reference list');
			same(200, fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Row published');
			same([['name' => 'Start', 'ort' => 'saal (orte/saal-2)']], fude_call($app, 'schema', options: $opt)[1]['collections']['stationen']['entries'], 'Row entry shows the title');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'stationen', 'action' => 'replace', 'entries' => [['name' => 'Start', 'ort' => 'Atelier Musterstadt'], ['name' => 'Ziel']]], options: $opt);
			same(200, $code, 'Replace with page fields');
			same([['name' => 'Start', 'ort' => ['page://atelieruuid00001'], 'nebenort' => []], ['name' => 'Ziel', 'ort' => '']], Kirby\Data\Data::decode($app()->page('anfahrt')->version('changes')->read()['stationen'], 'yaml'), 'Replace stores references, keeps the undeclared column, an omitted page field is empty');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Discarded');

			// (g) A reference outside the options shows the page title; one to nothing is ''.
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('anfahrt')->update(['treffpunkt' => ['page://scheuneuuid000001']]);
			same([['treffpunkt' => 'Scheune']], fude_call($app, 'schema', options: $opt)[1]['collections']['anfahrt']['entries'], 'Outside the options: the title');
			$kirby->page('anfahrt')->update(['treffpunkt' => ['page://gibtesnicht0001']]);
			same([['treffpunkt' => '']], fude_call($app, 'schema', options: $opt)[1]['collections']['anfahrt']['entries'], 'Reference to nothing: empty');

			$pflicht = $options('pflicht', "['collections' => ['p' => ['kind' => 'pages', 'parent' => 'kalender', 'template' => 'pflichttermin', 'key' => 'title', 'fields' => ['title' => 'text', 'leer' => 'page']]]]");
			[$code, $j] = fude_call($app, 'schema', options: $pflicht);
			same([200, ['title']], [$code, array_keys($j['collections']['p']['fields'] ?? [])], 'Required page field without pages: left out, no 500');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'p', 'fields' => ['title' => 'Ohne Ort']], options: $pflicht);
			same([422, 'The page blueprint rejects the entry: Leer: Please enter something'], [$code, $j['error'] ?? null], 'Kirby still refuses the create');

			// (h) Configuration errors: several pages, no query, a query that returns no pages,
			// a field that is no pages field, a page field as the key.
			$one = fn (string $field) => "['collections' => ['t' => ['kind' => 'pages', 'parent' => 'kalender', 'template' => 'termin', 'key' => 'title', 'fields' => ['title' => 'text', '$field' => 'page']]]]";
			[$code, $j] = fude_call($app, 'schema', options: $options('several', $one('leiter')));
			same($withError('collection "t": field "leiter" holds several pages; Fude sets one (multiple: false or max: 1 in the blueprint)'), [$code, $j['error'] ?? null], 'Several pages: 500');
			[$code, $j] = fude_call($app, 'schema', options: $options('noquery', $one('frei')));
			same($withError('collection "t": field "frei" needs a query in the blueprint that names the pages to choose from'), [$code, $j['error'] ?? null], 'No query: 500');
			[$code, $j] = fude_call($app, 'schema', options: $options('broken', $one('kaputt')));
			same($withError('collection "t": field "kaputt" query must return pages, not Kirby\Content\Field'), [$code, $j['error'] ?? null], 'Query without pages: 500');
			[$code, $j] = fude_call($app, 'schema', options: $options('wrongtype', $one('startdate')));
			same($withError('collection "t": field "startdate" is declared as page but the blueprint says date'), [$code, $j['error'] ?? null], 'Not a pages field: 500');
			[$code, $j] = fude_call($app, 'schema', options: $options('pagekey', "['collections' => ['t' => ['kind' => 'pages', 'parent' => 'kalender', 'template' => 'termin', 'key' => 'ort', 'fields' => ['title' => 'text', 'ort' => 'page']]]]"));
			same($withError('collection "t": "key" must name a declared text, select or date field'), [$code, $j['error'] ?? null], 'A page field is no key');

			// (i) Markers: a marked pages field on a page, a marked pages sub-field; an unmarked
			// pages sub-field stays out.
			$markers = fude_declare($tmp, 'markers', "['collections' => ['team' => false, 'aktuelles' => false, 'ohnebild' => false, 'news' => false]]");
			[$code, $j] = fude_call($app, 'schema', options: $markers);
			same(200, $code, 'Markers with page fields are valid', $j['error'] ?? null);
			same(
				['type' => 'page', 'label' => 'Treffpunkt', 'required' => false, 'description' => 'Wo sich die Gruppe trifft.', 'options' => ['Atelier Musterstadt', 'Saal (orte/saal)', 'saal (orte/saal-2)']],
				$j['collections']['anfahrt']['fields']['treffpunkt'] ?? null,
				'Marked page field'
			);
			same(['name', 'ort'], array_keys($j['collections']['anfahrt-stationen']['fields'] ?? []), 'Marked pages sub-field in, unmarked one out');
		} finally {
			cleanup($tmp);
		}
	}
}
