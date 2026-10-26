<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Collection option `touch` with `editable: true`: a draft may name the day
 * the touch sets (request parameter `touch`, YYYY-MM-DD, not before today in
 * fude.timezone); without one it is today. The schema describes the date
 * (`touchDate`) with its published value. A collection whose touch is not
 * editable, or that has no touch, refuses the parameter.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class TouchEditableTest extends TestCase
{
	public function testBehaviour(): void
	{
		Kirby::plugin('muster/karte', [
			'blueprints' => [
				'karte/tabs/karte' => [
					'label' => 'Tageskarte',
					'fields' => [
						'anzeigenBis' => ['label' => 'Anzeigen bis', 'type' => 'date', 'help' => 'Leer = <b>unbefristet</b>.'],
						'gerichte' => [
							'label' => 'Gerichte',
							'type' => 'structure',
							'fude' => ['actions' => ['replace'], 'touch' => ['field' => 'anzeigenBis', 'value' => 'today', 'editable' => true]],
							'fields' => ['gericht' => ['label' => 'Gericht', 'type' => 'text', 'required' => true]],
						],
					],
				],
			],
		]);

		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'touch-editable', <<<'PHP'
            ['collections' => [
                'staff' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'actions' => ['create', 'update', 'delete', 'replace'],
                    'touch' => ['field' => 'datum', 'value' => 'today', 'editable' => true, 'description' => 'Bis einschließlich dieses Tages sichtbar.'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true], 'rolle' => ['type' => 'text']],
                ],
                'fixed' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'touch' => ['field' => 'datum', 'value' => 'today'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true]],
                ],
                'plain' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'fields' => ['name' => ['type' => 'text', 'required' => true]],
                ],
                'seite' => [
                    'kind' => 'page', 'page' => 'standort',
                    'touch' => ['field' => 'datum', 'value' => 'today', 'editable' => true, 'label' => 'Anzeigen bis'],
                    'fields' => ['einleitung' => ['type' => 'textarea']],
                ],
            ]]
            PHP);

			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('standort')->update(['datum' => '2020-01-01']);
			$datum = fn (string $version) => ($app()->page('standort')->version($version)->read() ?? [])['datum'] ?? null;

			// Schema: the date only where it is editable, with label, description and the published value.
			[, $schema] = fude_call($app, 'schema', options: $options);
			same(
				['label' => 'Stand', 'description' => 'Bis einschließlich dieses Tages sichtbar.', 'min' => 'today', 'default' => 'today', 'current' => '2020-01-01'],
				$schema['collections']['staff']['touchDate'] ?? null,
				'Schema describes the editable date'
			);
			same('datum', $schema['collections']['staff']['touch'] ?? null, 'touch still names the field');
			same([null, null], [$schema['collections']['fixed']['touchDate'] ?? null, $schema['collections']['plain']['touchDate'] ?? null], 'No touchDate without editable');
			same('Anzeigen bis', $schema['collections']['seite']['touchDate']['label'] ?? null, 'Label from the declaration');

			// Without a date: today, and the record says so.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'fields' => ['name' => 'Anna Neu']], options: $options);
			same(200, $code, 'Draft without a date', $json);
			same(['2026-10-09', ['field' => 'datum', 'value' => '2026-10-09']], [$datum('changes'), $json['draft']['touch'] ?? null], 'Default today');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// A later day goes into the changes version and is published with the draft.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'fields' => ['name' => 'Anna Neu'], 'touch' => '2026-10-16'], options: $options);
			same(200, $code, 'Draft with a later date', $json);
			same(['2020-01-01', '2026-10-16', ['field' => 'datum', 'value' => '2026-10-16']], [$datum('latest'), $datum('changes'), $json['draft']['touch'] ?? null], 'Chosen date in the changes version');
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([200, '2026-10-16'], [$code, $datum('latest')], 'Published with the chosen date', $j);
			[, $schema] = fude_call($app, 'schema', options: $options);
			same('2026-10-16', $schema['collections']['staff']['touchDate']['current'] ?? null, 'Schema shows the published date');

			// Today itself is fine; replace and delete take a date too.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'replace', 'entries' => [['name' => 'Anna Neu']], 'touch' => '2026-10-09'], options: $options);
			same([200, '2026-10-09'], [$code, $datum('changes')], 'Replace with today', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Anna Neu', 'touch' => '2026-12-24'], options: $options);
			same([200, '2026-12-24'], [$code, $datum('changes')], 'Delete with a date', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// A multipart form sends the date as a field like any other.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'update', 'key' => 'Anna Neu', 'fields' => '{"rolle":"MFA"}', 'touch' => '2026-10-10'], options: $options);
			same([200, '2026-10-10'], [$code, $datum('changes')], 'Multipart with a date', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// Kind page: the date goes in with the fields.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'seite', 'action' => 'update', 'fields' => ['einleitung' => 'Neu'], 'touch' => '2026-10-11'], options: $options);
			same([200, '2026-10-11', ['field' => 'datum', 'value' => '2026-10-11']], [$code, $datum('changes'), $json['draft']['touch'] ?? null], 'Page collection with a date', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// Refused, and nothing stored: before today (in fude.timezone), not a day, not a text.
			$before = $app()->page('standort')->version('changes')->exists();
			$refused = function (mixed $touch, string $collection = 'staff') use ($app, $options): array {
				[$code, $json] = fude_call($app, 'draft', ['collection' => $collection, 'action' => 'update', 'key' => 'Anna Neu', 'fields' => ['rolle' => 'MFA'], 'touch' => $touch], options: $options);
				return [$code, $json['error'] ?? null];
			};
			same([422, '"touch" must not be before today.'], $refused('2026-10-08'), 'Yesterday refused');
			same([422, '"touch" must be a date in the form YYYY-MM-DD.'], $refused('2026-02-30'), 'No such day');
			same([422, '"touch" must be a date in the form YYYY-MM-DD.'], $refused('16.10.2026'), 'German form refused');
			same([422, '"touch" must be a date in the form YYYY-MM-DD.'], $refused(['2026-10-16']), 'A list refused');
			same([422, 'Collection "fixed" sets its date to today; a draft cannot choose another one.'], $refused('2026-10-16', 'fixed'), 'Not editable');
			same([422, 'Collection "plain" sets no date: "touch" is not allowed here.'], $refused('2026-10-16', 'plain'), 'No touch at all');
			same([false, '2026-10-16'], [$app()->page('standort')->version('changes')->exists() && !$before, $datum('latest')], 'Refusals stored nothing');

			// Empty means not given: today. Honolulu is still on the 8th, so the 8th is allowed there.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'fixed', 'fields' => ['name' => 'Ben Neu'], 'touch' => ''], options: $options);
			same([200, '2026-10-09'], [$code, $datum('changes')], 'Empty touch on a fixed collection is today', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			$honolulu = $options + ['fude.timezone' => 'Pacific/Honolulu'];
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'update', 'key' => 'Anna Neu', 'fields' => ['rolle' => 'MFA'], 'touch' => '2026-10-08'], options: $honolulu);
			same([200, '2026-10-08'], [$code, $datum('changes')], 'Today in fude.timezone', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $honolulu);

			// A marker in a plugin blueprint: label and help text of the field, the
			// published value under its lower-case content key.
			file_put_contents($tmp . '/site/blueprints/pages/speisekarte.yml', "title: Speisekarte\ntabs:\n  karte:\n    extends: karte/tabs/karte\n");
			mkdir($tmp . '/content/speisekarte', 0700, true);
			file_put_contents($tmp . '/content/speisekarte/speisekarte.txt', "Title: Speisekarte\n\n----\n\nAnzeigenbis: 2026-10-09\n\n----\n\nGerichte:\n\n- gericht: Linsensuppe\n");
			$none = fude_declare($tmp, 'none', "['collections' => []]");
			[, $schema] = fude_call($app, 'schema', options: $none);
			same(
				['label' => 'Anzeigen bis', 'description' => 'Leer = unbefristet.', 'min' => 'today', 'default' => 'today', 'current' => '2026-10-09'],
				$schema['collections']['speisekarte-gerichte']['touchDate'] ?? null,
				'Marker: date described from the blueprint field'
			);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'speisekarte-gerichte', 'action' => 'replace', 'entries' => [['gericht' => 'Gulasch']], 'touch' => '2026-10-13'], options: $none);
			same(200, $code, 'Marker: replace with a date', $json);
			fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $none);
			same(
				['2026-10-13', 1],
				[$app()->page('speisekarte')->anzeigenBis()->value(), preg_match_all('/^Anzeigenbis:/mi', (string)file_get_contents($tmp . '/content/speisekarte/speisekarte.txt'))],
				'Marker: published date, stored once'
			);

			// A weekday as default (option `default`, editable only): a draft without a date gets the next such
			// day, today included (the test clock is Friday, 2026-10-09). A date in the draft still wins.
			$woche = fude_declare($tmp, 'touch-default', <<<'PHP'
            ['collections' => [
                'samstag' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'actions' => ['create', 'replace'],
                    'touch' => ['field' => 'datum', 'value' => 'today', 'editable' => true, 'default' => 'saturday'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true]]],
                'freitag' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'actions' => ['replace'],
                    'touch' => ['field' => 'datum', 'value' => 'today', 'editable' => true, 'default' => 'friday'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true]]],
                'donnerstag' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'actions' => ['replace'],
                    'touch' => ['field' => 'datum', 'value' => 'today', 'editable' => true, 'default' => 'thursday'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true]]],
            ]]
            PHP);
			[, $schema] = fude_call($app, 'schema', options: $woche);
			same(
				['saturday', 'today'],
				[$schema['collections']['samstag']['touchDate']['default'] ?? null, $schema['collections']['staff']['touchDate']['default'] ?? 'today'],
				'Schema names the default day'
			);
			foreach (['samstag' => '2026-10-10', 'freitag' => '2026-10-09', 'donnerstag' => '2026-10-15'] as $sammlung => $tag) {
				[$code, $json] = fude_call($app, 'draft', ['collection' => $sammlung, 'action' => 'replace', 'entries' => []], options: $woche);
				same([200, $tag, $tag], [$code, $datum('changes'), $json['draft']['touch']['value'] ?? null], "Default $sammlung", $json);
				fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $woche);
			}
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'samstag', 'fields' => ['name' => 'Ben Neu'], 'touch' => '2026-10-09'], options: $woche);
			same([200, '2026-10-09'], [$code, $datum('changes')], 'A date in the draft wins over the default', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $woche);
			same('saturday', Sayamaapps\Fude\Config::load($app(options: $woche))['samstag']['touch']['default'] ?? null, 'Config keeps the default day');

			// Declaration mistakes are loud.
			$bad = function (string $touch, string $name) use ($tmp, $app): array {
				$php = "['collections' => ['staff' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'touch' => $touch, 'fields' => ['name' => 'text']]]]";
				$response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
				return [$response[0], $response[1]['error'] ?? null];
			};
			$prefix = 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": ';
			same([500, $prefix . 'touch editable must be true or false'], $bad("['field' => 'datum', 'value' => 'today', 'editable' => 'ja']", 'te1'), 'editable must be a bool');
			same([500, $prefix . 'touch label must be a text'], $bad("['field' => 'datum', 'value' => 'today', 'editable' => true, 'label' => 5]", 'te2'), 'label must be a text');
			same([500, $prefix . 'touch description must be a text'], $bad("['field' => 'datum', 'value' => 'today', 'description' => []]", 'te3'), 'description must be a text');
			same(
				[500, $prefix . 'touch default must be "today" or a weekday (monday … sunday)'],
				$bad("['field' => 'datum', 'value' => 'today', 'editable' => true, 'default' => 'samstag']", 'te4'),
				'default must be a weekday'
			);
			same(
				[500, $prefix . 'touch default needs editable: true'],
				$bad("['field' => 'datum', 'value' => 'today', 'default' => 'saturday']", 'te5'),
				'default only with editable'
			);

			// The non-editable touch keeps its old shape (sites and packages compare it).
			$config = Sayamaapps\Fude\Config::load($app(options: $options));
			same(['field' => 'datum', 'value' => 'today'], $config['fixed']['touch'], 'Fixed touch unchanged');
			same(
				['field' => 'datum', 'value' => 'today', 'editable' => true, 'label' => 'Stand', 'description' => 'Bis einschließlich dieses Tages sichtbar.'],
				$config['staff']['touch'],
				'Editable touch with label and description'
			);
		} finally {
			cleanup($tmp);
		}
	}
}
