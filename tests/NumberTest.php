<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Field type number: a decimal comma or point, stored with a point. Bounds
 * `min` and `max` come from the declaration and the blueprint — the tighter
 * one wins — and the schema exports them. Anything else is refused.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class NumberTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'number', <<<'PHP'
            ['collections' => [
                'team' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'actions' => ['create', 'update'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true], 'preis' => ['type' => 'number', 'min' => -5, 'max' => 100]],
                ],
            ]]
            PHP);

			// Schema: blueprint min 0 beats the declared -5, declared max 100 beats the blueprint's 999.
			[$code, $json] = fude_call($app, 'schema', options: $options);
			same(200, $code, 'Schema', $json);
			same(
				['type' => 'number', 'label' => 'Price', 'required' => false, 'description' => '', 'min' => 0, 'max' => 100],
				$json['collections']['team']['fields']['preis'] ?? null,
				'Schema exports the tighter bounds',
				$json
			);

			// The blueprint label is a translation key; the schema carries the text in the reply language.
			[, $json] = fude_call($app, 'schema', options: $options + ['fude.language' => 'de']);
			same('Preis', $json['collections']['team']['fields']['preis']['label'] ?? null, 'Translated label in German', $json['collections']['team']['fields']['preis'] ?? null);

			$preis = function (mixed $value) use ($app, $options): array {
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Anna Neu', 'preis' => $value]], options: $options);
				if ($code === 200) {
					fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
					return [$code, $json['draft']['fields']['preis']];
				}
				return [$code, $json['error'] ?? null];
			};
			same([200, '7.50'], $preis('7,50'), 'Decimal comma stored with a point');
			same([200, '7.5'], $preis(' 7.5 '), 'Decimal point, trimmed');
			same([200, '8'], $preis(8), 'JSON integer');
			same([200, '0'], $preis('0'), 'Lower bound itself');
			same([200, '100'], $preis('100'), 'Upper bound itself');
			same([200, ''], $preis(''), 'Empty is fine when not required');
			same([422, 'Field "preis" must be a number, e.g. 7.50.'], $preis('7,50 €'), 'Currency sign refused');
			same([422, 'Field "preis" must be a number, e.g. 7.50.'], $preis('1.000,50'), 'Thousands separator refused');
			same([422, 'Field "preis" must be a number, e.g. 7.50.'], $preis('1e3'), 'Exponent refused');
			same([422, 'Field "preis" must be at least 0.'], $preis('-1'), 'Below min (from the blueprint)');
			same([422, 'Field "preis" must be at most 100.'], $preis('100,01'), 'Above max (declared)');

			// Published: Kirby's number field accepts the stored value.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Anna Neu', 'preis' => '6,50']], options: $options);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same(200, $code, 'Publish with a number', $j);
			$rows = Kirby\Data\Data::decode($app()->page('standort')->version('latest')->read()['team'], 'yaml');
			// Kirby saves number fields as floats: 6.50 arrives as 6.5.
			same(6.5, (float)end($rows)['preis'], 'Published value', end($rows));

			// Declaration mistakes are loud.
			$bad = function (string $fields, string $name) use ($tmp, $app): array {
				$php = "['collections' => ['team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'fields' => ['name' => 'text', $fields]]]]";
				$response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
				return [$response[0], $response[1]['error'] ?? null];
			};
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "preis" min must be a number'],
				$bad("'preis' => ['type' => 'number', 'min' => '0']", 'n1'),
				'min as a string'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "preis" min is above max'],
				$bad("'preis' => ['type' => 'number', 'min' => 5, 'max' => 1]", 'n2'),
				'min above max'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "preis" min is above max (with the blueprint)'],
				$bad("'preis' => ['type' => 'number', 'min' => 1000]", 'n3'),
				'min above the blueprint max'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "rolle" max is only for number fields'],
				$bad("'rolle' => ['type' => 'text', 'max' => 5]", 'n4'),
				'max on text'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "rolle" is declared as number but the blueprint says text'],
				$bad("'rolle' => 'number'", 'n5'),
				'number on a text field'
			);
		} finally {
			cleanup($tmp);
		}
	}
}
