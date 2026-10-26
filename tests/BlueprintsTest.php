<?php

use Fude\Blueprints;
use Fude\Config;
use Kirby\Form\Form;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

const BP_STANDORT = <<<'YML'
    title: Standort
    fields:
      kurzname:
        label: Kurzname
        type: text
        required: true
        help: "<p>Kurzer   <em>Name</em></p>"
        fude: true
      einleitung:
        label: Einleitung
        type: textarea
        fude:
          description: Intro text
      zweiteZeile:
        label: Zweite Zeile
        type: text
        fude: true
      datum:
        label: Stand
        type: date
        fude: true
      kategorie:
        label: Kategorie
        extends: fields/marked-text
      interner:
        label: Intern
        type: text
      team:
        label: Wer hier arbeitet
        type: structure
        fude:
          key: name
          actions: [create, update]
        fields:
          name:
            label: Name
            type: text
            required: true
          rolle:
            label: Rolle
            type: text
          abteilung:
            label: Abteilung
            type: select
            options:
              aerzte: Ärzte
              empfang: Empfang
          foto:
            label: Portrait
            type: files
            multiple: false
            query: page.images
            uploads:
              template: bild
            fude:
              maxWidth: 800
              formats: [jpg, png]
              alt: name
          stand:
            label: Stand
            type: date
          uhr:
            label: Uhrzeit
            type: time
          aktiv:
            label: Aktiv
            type: toggle
          notiz:
            label: Notiz
            type: writer
          kontakt:
            label: Mail
            type: email
          seite:
            label: Seite
            type: pages
          intern:
            label: Interne Notiz
            type: text
            fude: false
      zeiten:
        label: Öffnungszeiten
        type: structure
        fude:
          name: oeffnung
          key: tag
          actions: [create, update, delete]
        fields:
          tag:
            label: Tag
            type: select
            options:
              mo: Montag
              di: Dienstag
          von:
            label: Von
            type: time
      urlaub:
        label: Urlaub
        type: structure
        fude:
          name: urlaub
          key: von
          actions: [create, update, delete]
        fields:
          von:
            label: Von
            type: date
          grund:
            label: Grund
            type: text
    YML;

const BP_MARKED_TEXT = "label: Kategorie\ntype: text\nfude: true\n";

function bp_write(string $tmp, array $files): array
{
	@unlink($tmp . '/site/blueprints/site.yml');
	@mkdir($tmp . '/site/blueprints/fields', 0775, true);
	file_put_contents($tmp . '/site/blueprints/pages/standort.yml', BP_STANDORT);
	file_put_contents($tmp . '/site/blueprints/fields/marked-text.yml', BP_MARKED_TEXT);
	foreach ($files as $path => $yaml) {
		file_put_contents($tmp . '/site/blueprints/' . $path, $yaml);
	}
	return fude_declare($tmp, 'none', "['collections' => []]");
}

function bp_error(string $tmp, callable $app, array $files, array $options = []): array
{
	$none = bp_write($tmp, $files);
	[$code, $json] = fude_call($app, 'schema', options: $options + $none);
	return [$code, $json['error'] ?? null];
}

/**
 * Blueprint markers: `fude: true` (or `fude: {…}`) on a field of a page
 * blueprint derives the collections, so site/config/fude.php is only needed
 * for overrides. Covers the derived shape, the options, the overrides by
 * name, the errors that keep a marker from silently doing nothing, the Panel
 * still loading such a blueprint, and draft → publish with a select and a
 * date as row key.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class BlueprintsTest extends TestCase
{
	public function testBehaviour(): void
	{
		try {
			[$tmp, $app] = fude_fixture();
			$none = bp_write($tmp, []);

			// Derived names: the page, and each structure by its default or its marker name.
			// A draft (content/_drafts) carries the same blueprint, but is never scanned.
			@mkdir($tmp . '/content/_drafts/entwurf', 0775, true);
			file_put_contents($tmp . '/content/_drafts/entwurf/standort.txt', "Title: Entwurf\n");
			ok($app()->page('entwurf')->isDraft(), 'Fixture page is a draft', $app()->page('entwurf')?->status());
			$names = array_keys(Blueprints::collections($app()));
			sort($names);
			same(['oeffnung', 'standort', 'standort-team', 'urlaub'], $names, 'Derived collection names: drafts not scanned');

			$c = Config::load($app(options: $none));
			// Page collection: one per page, actions update only, help and marker descriptions.
			same(
				['kind' => 'page', 'label' => 'Standort', 'description' => 'Page "Standort"', 'actions' => ['update'], 'page' => 'standort'],
				array_intersect_key($c['standort'], array_flip(['kind', 'page', 'actions', 'label', 'description'])),
				'Page collection shape'
			);
			same(['text', 'Kurzer Name', true], [$c['standort']['fields']['kurzname']['type'], $c['standort']['fields']['kurzname']['description'], $c['standort']['fields']['kurzname']['required']], 'Help text becomes the description, blueprint required');
			same('Intro text', $c['standort']['fields']['einleitung']['description'], 'Marker description beats the help');
			same(['text', 'date', 'Kategorie'], [$c['standort']['fields']['kategorie']['type'], $c['standort']['fields']['datum']['type'], $c['standort']['fields']['kategorie']['label']], 'Extended field is marked, plain field is not');
			ok(!isset($c['standort']['fields']['interner']), 'Unmarked page field stays out');
			same('Zweite Zeile', $c['standort']['fields']['zweitezeile']['label'] ?? null, 'Camel-case field name arrives lower case, as content keys are');

			// Structure with sub-fields of every mapped type; fude: false and unmapped unmarked sub-fields are out.
			same(['name', 'rolle', 'abteilung', 'foto', 'stand', 'uhr', 'aktiv', 'notiz', 'kontakt'], array_keys($c['standort-team']['fields']), 'Team sub-fields: mapped, not excluded');
			same(
				['text', 'text', 'select', 'image', 'date', 'time', 'toggle', 'textarea', 'text'],
				array_values(array_map(fn ($f) => $f['type'], $c['standort-team']['fields'])),
				'Team sub-field types'
			);
			same(
				['name', ['create', 'update'], 'Wer hier arbeitet', 'standort', 'team'],
				[$c['standort-team']['key'], $c['standort-team']['actions'], $c['standort-team']['label'], $c['standort-team']['page'], $c['standort-team']['field']],
				'Team collection: marker key, actions, field label'
			);
			same(
				[['aerzte', 'empfang'], 800, ['jpg', 'png'], 'name', 'bild'],
				[$c['standort-team']['fields']['abteilung']['options'], $c['standort-team']['fields']['foto']['maxWidth'], $c['standort-team']['fields']['foto']['formats'], $c['standort-team']['fields']['foto']['alt'], $c['standort-team']['fields']['foto']['template']],
				'Image sub-field options from the marker, template from the blueprint'
			);
			same('email', $c['standort-team']['fields']['kontakt']['format'], 'Blueprint email becomes a format');

			// Marker names, a select key, a date key.
			same(['tag', ['create', 'update', 'delete'], ['mo', 'di']], [$c['oeffnung']['key'], $c['oeffnung']['actions'], $c['oeffnung']['fields']['tag']['options']], 'Select key, marker actions');
			same(['von', ['create', 'update', 'delete'], 'date'], [$c['urlaub']['key'], $c['urlaub']['actions'], $c['urlaub']['fields']['von']['type']], 'Date key, marker actions');

			// Panel: the custom key survives in the blueprint, and the form loads the page.
			$page = $app()->page('standort');
			same(true, $page->blueprint()->fields()['kurzname']['fude'] ?? null, 'Marker survives in the blueprint');
			same(800, $page->blueprint()->fields()['team']['fields']['foto']['fude']['maxWidth'] ?? null, 'Sub-field marker survives');
			ok(Form::for($page)->fields()->has('kurzname'), 'Panel form loads the page with markers');
			ok(Form::for($page)->fields()->has('kategorie'), 'Panel form loads the extended field');

			// Select key (opening hours by weekday) and date key (holidays by start day): draft, publish, update, delete.
			$draft = fn (string $collection, string $action, array $fields, string|null $key = null) => fude_call(
				$app,
				'draft',
				array_filter(['collection' => $collection, 'action' => $action, 'key' => $key, 'fields' => $fields], fn ($v) => $v !== null),
				options: $none
			);
			$publish = fn (array $json) => fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $none);

			[$code, $json] = $draft('oeffnung', 'create', ['tag' => 'mo', 'von' => '8:30']);
			same([200, null], [$code, $json['error'] ?? null], 'Select-keyed row drafted');
			same('08:30:00', $json['draft']['fields']['von'] ?? null, 'Time drafted in the stored format', $json);
			same(200, $publish($json)[0], 'Publish a select-keyed row');
			[$code, $json] = $draft('oeffnung', 'update', ['von' => '09:00'], 'mo');
			same(200, $code, 'Update by the select key', $json);
			[$code, $pub] = $publish($json);
			same(['mo', '09:00:00'], [Fude\Content::rows($app(), $c['oeffnung'])[0]['tag'], Fude\Content::rows($app(), $c['oeffnung'])[0]['von']], 'Updated row published by select key', $pub);

			[$code, $json] = $draft('urlaub', 'create', ['von' => '2026-12-24', 'grund' => 'Heiligabend']);
			same(200, $code, 'Date-keyed create', $json);
			same(200, $publish($json)[0], 'Publish a date-keyed row');
			[$code, $json] = $draft('urlaub', 'update', ['grund' => 'Weihnachten'], '2026-12-24');
			same(200, $code, 'Update by the date key', $json);
			same(200, $publish($json)[0], 'Publish the update');
			same([['von' => '2026-12-24', 'grund' => 'Weihnachten']], array_map(fn ($r) => ['von' => $r['von'], 'grund' => $r['grund']], Fude\Content::rows($app(), $c['urlaub'])), 'Date-keyed row updated in place');
			[$code, $json] = $draft('urlaub', 'delete', [], '2026-12-24');
			same(200, $code, 'Delete by the date key', $json);
			same([200, []], [$publish($json)[0], Fude\Content::rows($app(), $c['urlaub'])], 'Row gone after publish');
			[$code, $json] = $draft('urlaub', 'delete', [], '2026-12-24');
			same(404, $code, 'Deleted date key is not found any more', $json);

			// Overrides in site/config/fude.php: false removes, an array replaces, a new name is added.
			$none = bp_write($tmp, []);
			$options = fude_declare($tmp, 'override', <<<'PHP'
            ['collections' => [
                'standort' => false,
                'standort-team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text']],
                'meldungen' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel', 'fields' => ['titel' => 'text']],
            ]]
            PHP);
			$c = Config::load($app(options: $options));
			same(['meldungen', 'oeffnung', 'standort-team', 'urlaub'], (function ($keys) {
				sort($keys);
				return $keys;
			})(array_keys($c)), 'Override: false removes, array replaces, new name added');
			same(['name'], array_keys($c['standort-team']['fields']), 'Override replaces the derived fields completely');

			// fude.blueprints => false: the file alone decides.
			$none = bp_write($tmp, []);
			[$code, $json] = fude_call($app, 'schema', options: ['fude.blueprints' => false, 'fude.config' => 'site/config/missing.php']);
			same([500, 'This site has no site/config/fude.php.'], [$code, $json['error']], 'Blueprints off, no file: missing config');
			[$code, $json] = fude_call($app, 'schema', options: ['fude.blueprints' => false] + $none);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: no collections: mark fields with `fude: true` in a blueprint or declare them in site/config/fude.php'],
				[$code, $json['error']],
				'Blueprints off, empty file: no collections'
			);

		} finally {
			cleanup($tmp);
		}
		global $n;
	}
}
