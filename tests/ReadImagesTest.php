<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Config;

const RI_SPEISEKARTE = <<<'YML'
    title: Speisekarte
    fields:
      gerichte:
        label: Tagesmenü
        type: structure
        fude:
          key: name
          readImages: true
        fields:
          name:
            label: Gericht
            type: text
            required: true
          preis:
            label: Preis
            type: number
            min: 0
    YML;

/**
 * The collection option `readImages`: a boolean declaration for the chat side
 * (the model may read an attached photo). Default false, allowed for every
 * kind, a marker option on structure fields, listed in the schema only when
 * true. Anything but a boolean is a 500.
 */
#[CoversClass(Config::class)]
#[RunTestsInSeparateProcesses]
final class ReadImagesTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fn (string $name, string $php) => fude_declare($tmp, $name, $php) + ['fude.blueprints' => false];
			$schemaOf = fn (string $name, string $php) => fude_call($app, 'schema', options: $options($name, $php));

			// Every kind, true and false, and the default.
			$all = <<<'PHP'
            ['collections' => [
                'menue' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'readImages' => true, 'fields' => ['name' => 'text']],
                'plain' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel', 'fields' => ['titel' => 'text']],
                'off' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel', 'readImages' => false, 'fields' => ['titel' => 'text']],
                'seite' => ['kind' => 'page', 'page' => 'standort', 'readImages' => true, 'fields' => ['kurzname' => 'text']],
                'news' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => 'title', 'readImages' => true, 'fields' => ['title' => 'text']],
            ]]
            PHP;
			$c = Config::load($app(options: $options('all', $all)));
			same([true, false, false, true, true], [$c['menue']['readImages'], $c['plain']['readImages'], $c['off']['readImages'], $c['seite']['readImages'], $c['news']['readImages']], 'Option read for every kind; default false');

			// The schema lists readImages: true only where it is set.
			[$code, $json] = $schemaOf('all', $all);
			same([200, true, false], [$code, $json['collections']['menue']['readImages'] ?? null, array_key_exists('readImages', $json['collections']['plain'])], 'Schema: readImages only when true');
			same([false, true, true], [array_key_exists('readImages', $json['collections']['off']), array_key_exists('readImages', $json['collections']['seite']) && $json['collections']['seite']['readImages'] === true, $json['collections']['news']['readImages'] ?? null], 'Schema: false is not listed, true is listed for pages collections and page kind');

			// Not a boolean: 500 with the collection named (null counts as not set).
			foreach (['yes', 1, 'true'] as $i => $value) {
				$php = "['collections' => ['menue' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'readImages' => " . var_export($value, true) . ", 'fields' => ['name' => 'text']]]]";
				[$code, $json] = $schemaOf('bad' . $i, $php);
				same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "menue": readImages must be true or false'], [$code, $json['error'] ?? null], 'Refused when not a boolean: ' . var_export($value, true));
			}
			$nullable = "['collections' => ['menue' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'readImages' => null, 'fields' => ['name' => 'text']]]]";
			same(false, Config::load($app(options: $options('null', $nullable)))['menue']['readImages'], 'null counts as not set');

			// Marker option on a structure field: derived, and listed in the schema.
			file_put_contents($tmp . '/site/blueprints/pages/speisekarte.yml', RI_SPEISEKARTE);
			mkdir($tmp . '/content/speisekarte', 0700, true);
			file_put_contents($tmp . '/content/speisekarte/speisekarte.txt', "Title: Speisekarte\n\n----\n\nGerichte:\n\n- name: Kürbissuppe\n  preis: 6.5\n");
			$marked = fude_declare($tmp, 'marked', "['collections' => []]") + ['fude.blueprints' => true];
			$c = Config::load($app(options: $marked));
			same([true, 'structure', 'name'], [$c['speisekarte-gerichte']['readImages'] ?? null, $c['speisekarte-gerichte']['kind'], $c['speisekarte-gerichte']['key']], 'Marker readImages: true on a structure');
			[$code, $json] = fude_call($app, 'schema', options: $marked);
			same([200, true], [$code, $json['collections']['speisekarte-gerichte']['readImages'] ?? null], 'Marker readImages in the schema', $json['error'] ?? null);

			// A marker value that is not a boolean is refused like the declaration. Kirby caches
			// blueprints by name per process, so the bad blueprint has a page name of its own.
			file_put_contents($tmp . '/site/blueprints/pages/speisekarte-bad.yml', str_replace('readImages: true', 'readImages: "yes"', RI_SPEISEKARTE));
			mkdir($tmp . '/content/speisekarte-bad', 0700, true);
			file_put_contents($tmp . '/content/speisekarte-bad/speisekarte-bad.txt', "Title: Speisekarte\n");
			[$code, $json] = fude_call($app, 'schema', options: fude_declare($tmp, 'marker-bad', "['collections' => []]") + ['fude.blueprints' => true]);
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "speisekarte-bad-gerichte": readImages must be true or false'], [$code, $json['error'] ?? null], 'Marker readImages must be a boolean');
		} finally {
			cleanup($tmp);
		}
	}
}
