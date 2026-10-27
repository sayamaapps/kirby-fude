<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Validator;

/**
 * Field type `date`: YYYY-MM-DD, a real calendar date, empty allowed unless
 * required. `min: 'today'` refuses dates before today (today = the draft's
 * clock in PHP's time zone). Other values of min are configuration errors.
 */
#[CoversClass(Validator::class)]
#[RunTestsInSeparateProcesses]
final class DateTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'date', <<<'PHP'
            ['collections' => [
                'termin' => [
                    'kind' => 'page', 'page' => 'standort',
                    'fields' => ['datum' => ['type' => 'date', 'min' => 'today']],
                ],
                'stand' => [
                    'kind' => 'page', 'page' => 'standort',
                    'fields' => ['datum' => ['type' => 'date']],
                ],
            ]]
            PHP);

			[$code, $json] = fude_call($app, 'schema', options: $options);
			same(
				[['type' => 'date', 'label' => 'Stand', 'required' => false, 'description' => '', 'min' => 'today'], ['type' => 'date', 'label' => 'Stand', 'required' => false, 'description' => '']],
				[$json['collections']['termin']['fields']['datum'], $json['collections']['stand']['fields']['datum']],
				'Schema: date fields, min only where declared'
			);

			$set = fn (string $collection, string $value) => fude_call($app, 'draft', ['collection' => $collection, 'action' => 'update', 'fields' => ['datum' => $value]], options: $options);
			[$code, $j] = $set('termin', '2026-02-30');
			same([422, 'Field "datum" must be a date in the form YYYY-MM-DD.'], [$code, $j['error']], 'Not a real date');
			[$code, $j] = $set('termin', '09.10.2026');
			same([422, 'Field "datum" must be a date in the form YYYY-MM-DD.'], [$code, $j['error']], 'German form refused');
			[$code, $j] = $set('termin', '2026-10-08');
			same([422, 'Field "datum" must not be before today.'], [$code, $j['error']], 'Yesterday refused with min today');
			same(false, $app()->page('standort')->version('changes')->exists(), 'Refused dates write nothing');

			[$code, $json] = $set('termin', '2026-10-09');
			same(200, $code, 'Today is fine', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			[$code, $json] = $set('termin', '2999-12-31');
			same(200, $code, 'A future date', $json);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([200, '2999-12-31'], [$code, $app()->page('standort')->version('latest')->read()['datum']], 'Published', $j);
			same('2999-12-31', fude_call($app, 'schema', options: $options)[1]['collections']['termin']['entries'][0]['datum'], 'Schema entry shows the date');

			// Without min the past is fine; an empty value clears a non-required date.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'stand', 'action' => 'update', 'fields' => ['datum' => '2000-01-01']], options: $options);
			same(200, $code, 'No min: a past date is fine', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'stand', 'action' => 'update', 'fields' => ['datum' => '']], options: $options);
			same(200, $code, 'Empty clears a date that is not required', $json);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// Declaration mistakes are loud.
			$bad = function (string $php, string $name) use ($tmp, $app): array {
				$response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
				return [$response[0], $response[1]['error'] ?? null];
			};
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "x": field "datum" min must be "today"'],
				$bad("['collections' => ['x' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['datum' => ['type' => 'date', 'min' => 'tomorrow']]]]]", 'd1'),
				'Only today'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "x": field "kurzname" min is only for date and number fields'],
				$bad("['collections' => ['x' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['kurzname' => ['type' => 'text', 'min' => 'today']]]]]", 'd2'),
				'min on text'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "x": field "kurzname" is declared as date but the blueprint says text'],
				$bad("['collections' => ['x' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['kurzname' => 'date']]]]", 'd3'),
				'Date on a text field'
			);
		} finally {
			cleanup($tmp);
		}
	}
}
