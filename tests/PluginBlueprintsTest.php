<?php

use Sayamaapps\Fude\Config;
use Kirby\Form\Form;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Markers in blueprints that a plugin brings along: a module ships a tab and
 * its fields with `fude:` markers, a site pulls the tab in with `extends`,
 * and the collection is derived without a line in site/config/fude.php. Also
 * the marker option `touch`, which a module needs to stamp its date field.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class PluginBlueprintsTest extends TestCase
{
	public function testBehaviour(): void
	{
		Kirby::plugin('muster/menu', [
			'blueprints' => [
				// A camel-case name, as a module's field may have: content keys are lower case.
				'menu/fields/gilt-am' => ['label' => 'Gilt am', 'type' => 'date'],
				'menu/fields/gerichte' => [
					'label' => 'Gerichte',
					'type' => 'structure',
					'fude' => [
						'description' => 'Each change replaces the whole menu.',
						'actions' => ['replace', 'update', 'delete'],
						'maxEntries' => 3,
						'touch' => ['field' => 'giltAm', 'value' => 'today'],
					],
					'fields' => [
						'gericht' => ['label' => 'Gericht', 'type' => 'text', 'required' => true],
						'preis' => ['label' => 'Preis', 'type' => 'number', 'min' => 0],
					],
				],
				'menu/tabs/karte' => [
					'label' => 'Tageskarte',
					'fields' => [
						'giltAm' => ['extends' => 'menu/fields/gilt-am'],
						'gerichte' => ['extends' => 'menu/fields/gerichte'],
					],
				],
			],
		]);

		[$tmp, $app] = fude_fixture();
		try {
			file_put_contents($tmp . '/site/blueprints/pages/speisekarte.yml', "title: Speisekarte\ntabs:\n  karte:\n    extends: menu/tabs/karte\n");
			mkdir($tmp . '/content/speisekarte', 0700, true);
			file_put_contents($tmp . '/content/speisekarte/speisekarte.txt', "Title: Speisekarte\n\n----\n\nGiltam: 2020-01-01\n\n----\n\nGerichte:\n\n- gericht: Linsensuppe\n  preis: 5.5\n");
			$options = fude_declare($tmp, 'none', "['collections' => []]");

			$c = Config::load($app(options: $options))['speisekarte-gerichte'] ?? null;
			ok($c !== null, 'Collection derived from the plugin blueprint');
			same(
				['structure', 'speisekarte', 'gerichte', 'gericht', ['replace', 'update', 'delete'], 3, ['field' => 'giltAm', 'value' => 'today'], 'Each change replaces the whole menu.'],
				[$c['kind'], $c['page'], $c['field'], $c['key'], $c['actions'], $c['maxEntries'], $c['touch'], $c['description']],
				'Marker options from the plugin blueprint, touch included'
			);
			same(['gericht', 'preis'], array_keys($c['fields']), 'Sub-fields from the plugin blueprint');
			ok(Form::for($app()->page('speisekarte'))->fields()->has('gerichte'), 'Panel form loads the extended tab');

			[$code, $schema] = fude_call($app, 'schema', options: $options);
			same([200, 'giltAm'], [$code, $schema['collections']['speisekarte-gerichte']['touch'] ?? null], 'Schema names the touched field', $schema);

			// A replace stamps the date in the changes version; publish carries it.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'speisekarte-gerichte', 'action' => 'replace',
				'entries' => [['gericht' => 'Kürbissuppe', 'preis' => '6,50'], ['gericht' => 'Gulasch', 'preis' => '9,90']]], options: $options);
			same(200, $code, 'Replace drafted', $json);
			same('2026-10-09', ($app()->page('speisekarte')->version('changes')->read() ?? [])['giltam'] ?? null, 'Draft sets the date to today');
			[$code, $json] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same(200, $code, 'Published', $json);
			$page = $app()->page('speisekarte');
			same(['2026-10-09', ['Kürbissuppe', 'Gulasch']], [$page->giltAm()->value(), array_column($page->gerichte()->toStructure()->toArray(), 'gericht')], 'Published list and date');
			same(1, preg_match_all('/^Giltam:/mi', (string)file_get_contents($tmp . '/content/speisekarte/speisekarte.txt')), 'The date is stored once, not twice under two spellings');

			// The list limit of the marker holds.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'speisekarte-gerichte', 'action' => 'replace',
				'entries' => [['gericht' => 'A'], ['gericht' => 'B'], ['gericht' => 'C'], ['gericht' => 'D']]], options: $options);
			same(422, $code, 'maxEntries from the marker refuses a fourth dish', $json);
		} finally {
			cleanup($tmp);
		}
		global $n;
	}
}
