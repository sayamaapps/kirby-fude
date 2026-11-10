<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Config;
use Sayamaapps\Fude\Service;

/**
 * A key of several fields: entries that are only unique by the combination
 * (the same name in two departments). Uniqueness, lookup by an object of key
 * parts, the checks inside one list and at publish, and the config errors.
 * A key of one field behaves as before.
 */
#[CoversClass(Service::class)]
#[CoversClass(Config::class)]
#[RunTestsInSeparateProcesses]
final class CompositeKeyTest extends TestCase
{
	private const DECLARATION = <<<'PHP'
        ['collections' => [
            'crew' => [
                'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => ['name', 'abteilung'],
                'actions' => ['create', 'update', 'delete', 'replace'],
                'fields' => [
                    'name' => ['type' => 'text'],
                    'abteilung' => ['type' => 'select'],
                    'rolle' => ['type' => 'text'],
                ],
            ],
            'single' => [
                'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => ['name'],
                'fields' => ['name' => ['type' => 'text'], 'rolle' => ['type' => 'text']],
            ],
            'notes' => [
                'kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => ['title', 'kategorie'],
                'actions' => ['create', 'update'],
                'fields' => [
                    'title' => ['type' => 'text'],
                    'kategorie' => ['type' => 'select', 'options' => ['praxis', 'urlaub']],
                    'text' => ['type' => 'textarea'],
                ],
            ],
        ]]
        PHP;

	/** @return list<array<string, mixed>> the published rows of the team field */
	private static function team(callable $app, string $version = 'latest'): array
	{
		$content = $app()->page('standort')->version($version)->read() ?? [];
		return Data::decode($content['team'] ?? '', 'yaml') ?: [];
	}

	public function testCompositeKey(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'composite', self::DECLARATION);
			$draft = fn (array $body) => fude_call($app, 'draft', $body, options: $options);
			$publish = fn (array $json) => fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			$pairs = fn () => array_map(fn (array $row) => ($row['name'] ?? '') . '/' . ($row['abteilung'] ?? ''), self::team($app));

			// The schema names every part, a single field stays a text (also written as a list of one).
			$collections = fude_call($app, 'schema', options: $options)[1]['collections'];
			same([['name', 'abteilung'], 'name', ['title', 'kategorie']], [$collections['crew']['key'], $collections['single']['key'], $collections['notes']['key']], 'Schema: the key');
			same([true, true, false], [$collections['crew']['fields']['name']['required'], $collections['crew']['fields']['abteilung']['required'], $collections['crew']['fields']['rolle']['required']], 'Every part is required, nothing else');

			// The same name in another department is another entry.
			[$code, $json] = $draft(['collection' => 'crew', 'fields' => ['name' => 'Gasthaus Muster', 'abteilung' => 'aerzte', 'rolle' => 'Küche']]);
			same(200, $code, 'First entry', $json);
			same(['name' => 'Gasthaus Muster', 'abteilung' => 'aerzte'], $json['draft']['key'], 'The record names the entry by every part');
			same(200, $publish($json)[0], 'First entry published');
			[$code, $json] = $draft(['collection' => 'crew', 'fields' => ['name' => 'Gasthaus Muster', 'abteilung' => 'labor', 'rolle' => 'Service']]);
			same(200, $code, 'Same name, other department: no collision', $json);
			same(200, $publish($json)[0], 'Second entry published');
			same(['Jörg Muster/aerzte', 'Veronika Beispiel/empfang', 'Gasthaus Muster/aerzte', 'Gasthaus Muster/labor'], $pairs(), 'Both are stored, neither is lost');

			// A true duplicate (whatever the case) is still refused.
			[$code, $j] = $draft(['collection' => 'crew', 'fields' => ['name' => 'gasthaus muster ', 'abteilung' => 'labor']]);
			same([409, 'There is already an entry with Name / Abteilung "gasthaus muster / labor". Use action "update".'], [$code, $j['error']], 'Same combination');
			same(false, $app()->page('standort')->version('changes')->exists(), 'A refused draft stores nothing');

			// An update names the entry by every part, and touches only that one.
			[$code, $json] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => ['name' => 'gasthaus muster', 'abteilung' => 'labor'], 'fields' => ['rolle' => 'Bar']]);
			same(200, $code, 'Update by both parts', $json);
			same([3, ['name' => 'gasthaus muster', 'abteilung' => 'labor']], [$json['draft']['index'], $json['draft']['key']], 'The second entry is the one');
			$changes = self::team($app, 'changes');
			same(['Küche', 'Bar'], [$changes[2]['rolle'], $changes[3]['rolle']], 'Only the named entry changed');
			same(200, $publish($json)[0], 'Update published');
			same(['Küche', 'Bar'], [self::team($app)[2]['rolle'], self::team($app)[3]['rolle']], 'The first entry is untouched');

			// The key as a JSON string (a form post), like the fields.
			[$code, $json] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => '{"name":"Gasthaus Muster","abteilung":"aerzte"}', 'fields' => ['rolle' => 'Kasse']]);
			same([200, 2], [$code, $json['draft']['index']], 'Key as JSON string', $json);
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], 'Discard');

			// A key that does not name every part, names more, or is a text: 422. Unknown entry: 404.
			$refused = ['Gasthaus Muster', ['name' => 'Gasthaus Muster'], ['name' => 'Gasthaus Muster', 'abteilung' => ''], ['name' => 'Gasthaus Muster', 'abteilung' => 'labor', 'rolle' => 'Bar'], ['Gasthaus Muster', 'labor'], '{"name"'];
			foreach ($refused as $key) {
				[$code, $j] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => $key, 'fields' => ['rolle' => 'X']]);
				same([422, 'Action "update" needs "key": an object with every key field (name, abteilung) of the entry, e.g. {"name": "…", "abteilung": "…"}.'], [$code, $j['error'] ?? null], 'Incomplete key: ' . json_encode($key));
			}
			[$code, $j] = $draft(['collection' => 'crew', 'action' => 'delete']);
			same([422, 'Action "delete" needs "key": an object with every key field (name, abteilung) of the entry, e.g. {"name": "…", "abteilung": "…"}.'], [$code, $j['error']], 'Delete without a key');
			[$code, $j] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => ['name' => 'Gasthaus Muster', 'abteilung' => 'empfang'], 'fields' => ['rolle' => 'X']]);
			same([404, 'No entry with Name / Abteilung "Gasthaus Muster / empfang".'], [$code, $j['error']], 'Another combination is another entry');

			// An update may not give an entry the combination of another one: at draft time and at publish.
			[$code, $j] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => ['name' => 'Gasthaus Muster', 'abteilung' => 'labor'], 'fields' => ['abteilung' => 'aerzte']]);
			same([409, 'Another entry already has Name / Abteilung "Gasthaus Muster / aerzte". Two entries could no longer be told apart.'], [$code, $j['error']], 'Changing one part into a taken combination');
			[$code, $json] = $draft(['collection' => 'crew', 'action' => 'update', 'key' => ['name' => 'Gasthaus Muster', 'abteilung' => 'labor'], 'fields' => ['abteilung' => 'empfang']]);
			same(200, $code, 'Changing one part into a free combination', $json);
			same(200, $publish($json)[0], 'Published');
			same(['Jörg Muster/aerzte', 'Veronika Beispiel/empfang', 'Gasthaus Muster/aerzte', 'Gasthaus Muster/empfang'], $pairs(), 'The part changed on that entry only');

			// Delete by both parts.
			[$code, $json] = $draft(['collection' => 'crew', 'action' => 'delete', 'key' => ['name' => 'Gasthaus Muster', 'abteilung' => 'empfang']]);
			same(200, $code, 'Delete by both parts', $json);
			same(200, $publish($json)[0], 'Delete published');
			same(['Jörg Muster/aerzte', 'Veronika Beispiel/empfang', 'Gasthaus Muster/aerzte'], $pairs(), 'Only that entry went');

			// Several entries in one create, or a replace: the same combination twice is refused, the same name alone is fine.
			[$code, $j] = $draft(['collection' => 'crew', 'entries' => [['name' => 'Praxis Beispiel', 'abteilung' => 'labor'], ['name' => 'praxis beispiel', 'abteilung' => 'labor']]]);
			same([422, 'Two entries have the same Name / Abteilung "praxis beispiel / labor".'], [$code, $j['error']], 'Duplicate combination in one create');
			[$code, $j] = $draft(['collection' => 'crew', 'entries' => [['name' => 'Praxis Beispiel', 'abteilung' => 'labor'], ['name' => 'Praxis Beispiel', 'abteilung' => 'aerzte']]]);
			same(200, $code, 'Same name, different part, in one create', $j);
			same(['Praxis Beispiel / labor', 'Praxis Beispiel / aerzte'], $j['draft']['keys'], 'The record lists the combined keys');
			same(200, fude_call($app, 'discard', ['id' => $j['draft']['id'], 'token' => $j['token']], options: $options)[0], 'Discard');
			[$code, $j] = $draft(['collection' => 'crew', 'entries' => [['name' => 'Praxis Beispiel', 'abteilung' => 'labor'], ['name' => 'Gasthaus Muster', 'abteilung' => 'aerzte']]]);
			same(409, $code, 'A published combination in a create with entries');
			[$code, $j] = $draft(['collection' => 'crew', 'action' => 'replace', 'entries' => [['name' => 'A', 'abteilung' => 'labor'], ['name' => 'a', 'abteilung' => 'labor']]]);
			same([422, 'Two entries have the same Name / Abteilung "a / labor".'], [$code, $j['error']], 'Duplicate combination in a replace');
			[$code, $json] = $draft(['collection' => 'crew', 'action' => 'replace', 'entries' => [['name' => 'Gasthaus Muster', 'abteilung' => 'aerzte', 'rolle' => 'neu'], ['name' => 'Gasthaus Muster', 'abteilung' => 'labor', 'rolle' => 'dazu']]]);
			same(200, $code, 'Replace with the same name twice', $json);
			same([['Gasthaus Muster / aerzte'], ['Gasthaus Muster / labor']], [$json['draft']['replace']['kept'], $json['draft']['replace']['added']], 'Kept and added, by combined key');
			same(['Jörg Muster / aerzte', 'Veronika Beispiel / empfang'], $json['draft']['replace']['removed'], 'Removed, by combined key');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], 'Discard');

			// A single field in a list is the plain key: a text addresses the entry, a duplicate is a 409.
			[$code, $j] = $draft(['collection' => 'single', 'fields' => ['name' => 'gasthaus muster']]);
			same([409, 'There is already an entry with Name "gasthaus muster". Use action "update".'], [$code, $j['error']], 'One-field key as a list');
			[$code, $j] = $draft(['collection' => 'single', 'action' => 'update', 'key' => ['name' => 'Gasthaus Muster'], 'fields' => ['rolle' => 'X']]);
			same([422, 'An update needs "key": the Name of the entry to change.'], [$code, $j['error']], 'An object is no key of a one-field key');
		} finally {
			cleanup($tmp);
		}
	}

	public function testPagesCollection(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'composite', self::DECLARATION);
			$publish = fn (array $json) => fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			$create = fn (string $category, string $text) => fude_call($app, 'draft', ['collection' => 'notes', 'fields' => ['title' => 'Gasthaus Muster', 'kategorie' => $category, 'text' => $text]], options: $options);

			[$code, $json] = $create('praxis', 'eins');
			same(200, $code, 'First note', $json);
			same(['title' => 'Gasthaus Muster', 'kategorie' => 'praxis'], $json['draft']['key'], 'Record key');
			same(200, $publish($json)[0], 'First note published');
			[$code, $json] = $create('urlaub', 'zwei');
			same(200, $code, 'Same title, other category: no collision', $json);
			same(200, $publish($json)[0], 'Second note published');
			same(['praxis' => 'eins', 'urlaub' => 'zwei'], array_column(array_map(fn ($page) => ['k' => $page->kategorie()->value(), 't' => $page->text()->value()], $app()->page('aktuelles')->children()->values()), 't', 'k'), 'Both pages are stored');
			[$code, $j] = $create('urlaub', 'drei');
			same([409, 'There is already an entry with Title / Kategorie "Gasthaus Muster / urlaub". Use action "update".'], [$code, $j['error']], 'The same combination');

			[$code, $json] = fude_call($app, 'draft', ['collection' => 'notes', 'action' => 'update', 'key' => ['title' => 'Gasthaus Muster', 'kategorie' => 'urlaub'], 'fields' => ['text' => 'geändert']], options: $options);
			same(200, $code, 'Update by both parts', $json);
			same(200, $publish($json)[0], 'Update published');
			same(['praxis' => 'eins', 'urlaub' => 'geändert'], array_column(array_map(fn ($page) => ['k' => $page->kategorie()->value(), 't' => $page->text()->value()], $app()->page('aktuelles')->children()->values()), 't', 'k'), 'Only that page changed');

			// An update may not give a page the combination of another one.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'notes', 'action' => 'update', 'key' => ['title' => 'Gasthaus Muster', 'kategorie' => 'urlaub'], 'fields' => ['kategorie' => 'praxis']], options: $options);
			same([409, 'Another entry already has Title / Kategorie "Gasthaus Muster / praxis". Two entries could no longer be told apart.'], [$code, $json['error']], 'Taken at draft time');
		} finally {
			cleanup($tmp);
		}
	}

	public function testConfigErrors(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$configs = 0;
			$with = function (string $key, string $fields = "'name' => 'text', 'abteilung' => 'select', 'foto' => 'image'") use ($tmp, $app, &$configs) {
				$file = 'site/config/fude-key-' . (++$configs) . '.php';
				file_put_contents($tmp . '/' . $file, "<?php\nreturn ['collections' => ['team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => $key, 'fields' => [$fields]]]];\n");
				[$code, $json] = fude_call($app, 'schema', options: ['fude.config' => $file]);
				return [$code, $json['error'] ?? $json['collections']['team']['key']];
			};
			$invalid = 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": ';
			same([500, $invalid . '"key" must be a field name or a non-empty list of field names'], $with('[]'), 'Empty list');
			same([500, $invalid . '"key" must be a field name or a non-empty list of field names'], $with("['a' => 'name']"), 'Not a list');
			same([500, $invalid . '"key" lists "nope", which is not a declared text, select or date field'], $with("['name', 'nope']"), 'Unknown field');
			same([500, $invalid . '"key" lists "foto", which is not a declared text, select or date field'], $with("['name', 'foto']"), 'An image is no key part');
			same([500, $invalid . '"key" lists a value, which is not a declared text, select or date field'], $with("['name', 3]"), 'Not a field name');
			same([500, $invalid . '"key" lists field "name" twice'], $with("['name', 'name']"), 'A part twice');
			same([200, ['name', 'abteilung']], $with("['name', 'abteilung']"), 'A valid composite key');
			same([200, 'name'], $with("['name']"), 'A list of one is the plain key');
			same([200, 'name'], $with("'name'"), 'A text is the plain key');
		} finally {
			cleanup($tmp);
		}
	}

	/** @return array<string, array{0: string, 1: int, 2: mixed}> */
	public static function markers(): array
	{
		$invalid = 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: ';
		return [
			'a list is a composite key' => ['[name, abteilung]', 200, ['name', 'abteilung']],
			'a list of one is the plain key' => ['[abteilung]', 200, 'abteilung'],
			'a text is the plain key' => ['name', 200, 'name'],
			'empty list' => ['[]', 500, $invalid . 'collection "standort-team": "key" must be a field name or a non-empty list of field names'],
			'unknown field' => ['[name, nope]', 500, $invalid . 'collection "standort-team": "key" lists "nope", which is not a declared text, select or date field'],
			'part that is no text' => ['[name, 3]', 500, $invalid . 'page "standort", field "team": fude option "key" must be a text or a list of texts'],
		];
	}

	#[DataProvider('markers')]
	public function testBlueprintMarker(string $marker, int $status, mixed $expected): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$none = fude_declare($tmp, 'none', "['collections' => []]");
			file_put_contents($tmp . '/site/blueprints/pages/standort.yml', "title: Standort\nfields:\n  team:\n    label: Team\n    type: structure\n    fude:\n      key: $marker\n    fields:\n      name:\n        type: text\n      abteilung:\n        type: select\n        options: [aerzte, empfang]\n");
			[$code, $json] = fude_call($app, 'schema', options: $none);
			same([$status, $expected], [$code, $json['error'] ?? $json['collections']['standort-team']['key']], 'The marker key');
		} finally {
			cleanup($tmp);
		}
	}
}
