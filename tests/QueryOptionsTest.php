<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Config;

const QO_FILIALE = <<<'YML'
    title: Filiale
    fields:
      bereiche:
        label: Bereiche
        type: structure
        fields:
          schluessel:
            label: Schlüssel
            type: text
          name:
            label: Name
            type: text
      kanaele:
        label: Kanäle
        type: structure
        fields:
          schluessel:
            label: Schlüssel
            type: text
          name:
            label: Name
            type: text
      standardbereich:
        label: Standardbereich
        type: select
        options:
          type: query
          query: page.bereiche.toStructure
          text: "{{ structureItem.name }}"
          value: "{{ structureItem.schluessel }}"
      team:
        label: Team
        type: structure
        fields:
          name:
            label: Name
            type: text
            required: true
          bereich:
            label: Bereich
            type: select
            options:
              type: query
              query: page.bereiche.toStructure
              text: "{{ structureItem.name }}"
              value: "{{ structureItem.schluessel }}"
          kanal:
            label: Kanal
            type: select
            options:
              type: query
              query: page.kanaele.toStructure
              text: "{{ structureItem.name }}"
              value: "{{ structureItem.schluessel }}"
    YML;

const QO_WERTE = <<<'YML'
    title: Werte
    fields:
      kennzahl:
        label: Kennzahl
        type: select
        options:
          type: query
          query: page.title
      werte:
        label: Werte
        type: select
        options:
          type: api
          url: https://example.invalid/werte
          text: "{{ item.name }}"
          value: "{{ item.id }}"
    YML;

const QO_NOTE = <<<'YML'
    title: Meldung
    fields:
      text:
        label: Text
        type: textarea
      ort:
        label: Ort
        type: select
        options:
          type: query
          query: site.find('filiale').bereiche.toStructure
          text: "{{ structureItem.name }}"
          value: "{{ structureItem.schluessel }}"
      intern:
        label: Intern
        type: select
        options:
          type: query
          query: page.bereiche.toStructure
          value: "{{ structureItem.schluessel }}"
    YML;

/**
 * Select fields whose options come from a query (`options: {type: query}`):
 * resolved against the collection's page (or the probe page of a pages
 * collection) at config load. Covers the schema and the Validator, a query
 * that yields nothing (field left out, or 500 on the key or a required
 * field), declared options checked against the resolved set, and a query
 * that fails. The fixture page "filiale" lives in this file only.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class QueryOptionsTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			mkdir($tmp . '/content/filiale', 0700, true);
			file_put_contents($tmp . '/site/blueprints/pages/filiale.yml', QO_FILIALE);
			file_put_contents($tmp . '/site/templates/filiale.php', '<?php echo "Filiale ", $page->title();');
			file_put_contents($tmp . '/content/filiale/filiale.txt', <<<TXT
            Title: Filiale

            ----

            Bereiche:

            - schluessel: aerzte
              name: Ärzte
            - schluessel: empfang
              name: Empfang

            ----

            Team:

            - name: Anna Neu
              bereich: aerzte
            TXT);
			file_put_contents($tmp . '/site/blueprints/pages/note.yml', QO_NOTE);
			mkdir($tmp . '/content/werte', 0700, true);
			file_put_contents($tmp . '/site/blueprints/pages/werte.yml', QO_WERTE);
			file_put_contents($tmp . '/content/werte/werte.txt', "Title: Werte\n");
			$options = fn (string $name, string $php) => fude_declare($tmp, $name, $php) + ['fude.blueprints' => false];
			$schemaOf = fn (string $name, string $php) => fude_call($app, 'schema', options: $options($name, $php));
			$withError = fn (string $message) => [500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: ' . $message];

			// (a) Resolved options: the page's own structure, resolved against the page model.
			// The field "kanal" points at an empty structure: it is left out (b).
			$basis = <<<'PHP'
            ['collections' => [
                'team' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'name', 'fields' => [
                    'name' => 'text', 'bereich' => 'select', 'kanal' => 'select',
                ]],
                'filiale' => ['kind' => 'page', 'page' => 'filiale', 'fields' => ['standardbereich' => 'select']],
                'notiz' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => 'title', 'fields' => [
                    'title' => 'text', 'ort' => 'select', 'intern' => 'select',
                ]],
            ]]
            PHP;
			[$code, $json] = $schemaOf('basis', $basis);
			same(200, $code, 'Configuration with query options is valid', $json['error'] ?? null);
			same(['aerzte', 'empfang'], $json['collections']['team']['fields']['bereich']['options'] ?? null, 'Query options of a structure sub-field resolve against the page');
			same('select', $json['collections']['team']['fields']['bereich']['type'] ?? null, 'Resolved field keeps its type');
			same(['aerzte', 'empfang'], $json['collections']['filiale']['fields']['standardbereich']['options'] ?? null, 'Query options of a page field resolve against the page');
			same(['aerzte', 'empfang'], $json['collections']['notiz']['fields']['ort']['options'] ?? null, 'Pages collection: a query naming the page by id resolves');
			same(['bereich' => 'aerzte'], array_intersect_key($json['collections']['team']['entries'][0] ?? [], ['bereich' => 1]), 'Entries of the structure carry the select value');

			// (b) An empty query result: the field is left out of the schema, the others stay.
			same(['name', 'bereich'], array_keys($json['collections']['team']['fields']), 'Empty query result: the field is left out');
			ok(!isset($json['collections']['notiz']['fields']['intern']), 'Pages collection: a query over the probe page finds nothing, the field is left out', $json['collections']['notiz']['fields'] ?? null);

			// The Validator: a listed value is accepted, an unlisted one refused with 422 and nothing written.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Ben Beispiel', 'bereich' => 'labor']], options: $options('basis', $basis));
			same([422, 'Field "bereich" must be one of: aerzte, empfang.'], [$code, $json['error'] ?? null], 'Unlisted query value refused', $json);
			same([], glob($tmp . '/content/filiale/_changes/*') ?: [], 'Refused draft writes nothing');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Ben Beispiel', 'bereich' => 'empfang']], options: $options('basis', $basis));
			same(200, $code, 'Listed query value accepted', $json);
			same('empfang', $json['draft']['fields']['bereich'] ?? null, 'Accepted value drafted');

			// Config::load gives the same options to the code (not only the schema).
			$c = Config::load($app(options: $options('basis', $basis)));
			same(['aerzte', 'empfang'], $c['team']['fields']['bereich']['options'], 'Config carries the resolved options');
			ok(!isset($c['team']['fields']['kanal']), 'Config leaves the empty field out');

			// (c) An empty query result on the key, or on a required field: 500, as with an empty static list.
			[$code, $json] = $schemaOf('key', "['collections' => ['kanal-liste' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'kanal', 'fields' => ['kanal' => 'select', 'name' => 'text']]]]");
			same($withError('collection "kanal-liste": field "kanal" has no options'), [$code, $json['error'] ?? null], 'Empty query on the key: 500');
			[$code, $json] = $schemaOf('required', "['collections' => ['team' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text', 'kanal' => ['type' => 'select', 'required' => true]]]]]");
			same($withError('collection "team": field "kanal" has no options'), [$code, $json['error'] ?? null], 'Empty query on a required field: 500');

			// (d) Declared options: a subset of the resolved set is the allowed set; anything outside it is 500.
			[$code, $json] = $schemaOf('subset', "['collections' => ['team' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text', 'bereich' => ['type' => 'select', 'options' => ['empfang']]]]]]");
			same([200, ['empfang']], [$code, $json['collections']['team']['fields']['bereich']['options'] ?? null], 'Declared subset of the query options');
			[$code, $json] = $schemaOf('outside', "['collections' => ['team' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text', 'bereich' => ['type' => 'select', 'options' => ['aerzte', 'labor']]]]]]");
			same($withError('collection "team": field "bereich" declares options the blueprint does not have'), [$code, $json['error'] ?? null], 'Declared option outside the query result: 500');
			[$code, $json] = $schemaOf('emptyquery', "['collections' => ['team' => ['kind' => 'structure', 'page' => 'filiale', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text', 'kanal' => ['type' => 'select', 'options' => ['x']]]]]]");
			same($withError('collection "team": field "kanal" declares options the blueprint does not have'), [$code, $json['error'] ?? null], 'Declared options for a query that yields nothing: 500, not silently dropped');

			// A query that fails (here: it yields a string, not options): 500 naming the collection, field and Kirby's message.
			[$code, $json] = $schemaOf('failing', "['collections' => ['werte' => ['kind' => 'page', 'page' => 'werte', 'fields' => ['kennzahl' => 'select']]]]");
			same([500, true], [$code, str_starts_with($json['error'] ?? '', 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "werte": field "kennzahl" query failed: Invalid query result data')], 'Failing query: 500 with collection, field and message');

			// type api is not resolved (no request is made): the declared values are the allowed set, as before.
			[$code, $json] = $schemaOf('api', "['collections' => ['werte' => ['kind' => 'page', 'page' => 'werte', 'fields' => ['werte' => ['type' => 'select', 'options' => ['a', 'b']]]]]]");
			same([200, ['a', 'b']], [$code, $json['collections']['werte']['fields']['werte']['options'] ?? null], 'Api options: the declared values are the allowed set');
			[$code, $json] = $schemaOf('apinone', "['collections' => ['werte' => ['kind' => 'page', 'page' => 'werte', 'fields' => ['werte' => 'select']]]]");
			same($withError('collection "werte": field "werte" has no options'), [$code, $json['error'] ?? null], 'Api options without declared values: 500, as before');
		} finally {
			cleanup($tmp);
		}
	}
}
