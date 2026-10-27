<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

function titles(callable $app, string $version): array
{
	$content = $app()->page('home')->version($version)->read() ?? [];
	$yaml = $content['meldungen'] ?? '';
	$rows = is_string($yaml) && trim($yaml) !== '' ? Data::decode($yaml, 'yaml') : [];
	return array_column(is_array($rows) ? $rows : [], 'titel');
}

/**
 * Collection option `insert` ('top' | 'bottom', default 'bottom'): where a
 * create puts the new row in a structure list. Only structure collections;
 * any other value is a configuration error.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class InsertTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'insert', <<<'PHP'
            ['collections' => [
                'unten' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
                ],
                'oben' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'insert' => 'top',
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
                ],
            ]]
            PHP);

			[, $schema] = fude_call($app, 'schema', options: $options);
			same([null, 'top'], [$schema['collections']['unten']['insert'] ?? null, $schema['collections']['oben']['insert'] ?? null], 'Schema exports insert where declared');

			// Default: bottom. Two rows, in order.
			foreach (['Eins', 'Zwei'] as $title) {
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'unten', 'fields' => ['titel' => $title, 'text' => 't']], options: $options);
				same(200, $code, 'Bottom create', $json);
				[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
				same(200, $code, 'Published', $j);
			}
			same(['Eins', 'Zwei'], titles($app, 'latest'), 'Default puts new rows at the bottom');

			// top: the new row goes to the front; the record names index 0.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'oben', 'fields' => ['titel' => 'Drei', 'text' => 't']], options: $options);
			same([200, 0], [$code, $json['draft']['index']], 'Top create: index 0', $json);
			same(['Drei', 'Eins', 'Zwei'], titles($app, 'changes'), 'Changes: the new row first');
			same(['Eins', 'Zwei'], titles($app, 'latest'), 'Published order untouched');
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([200, ['Drei', 'Eins', 'Zwei']], [$code, titles($app, 'latest')], 'Published with the new row first', $j);

			// bottom still appends, even after a top insert.
			[, $json] = fude_call($app, 'draft', ['collection' => 'unten', 'fields' => ['titel' => 'Vier', 'text' => 't']], options: $options);
			same(3, $json['draft']['index'], 'Bottom create after top: last index');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// Update and delete keep their place.
			[, $json] = fude_call($app, 'draft', ['collection' => 'oben', 'action' => 'update', 'key' => 'Eins', 'fields' => ['text' => 'neu']], options: $options);
			same([1, ['Drei', 'Eins', 'Zwei']], [$json['draft']['index'], titles($app, 'changes')], 'Update does not move the row');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

			// Declaration mistakes are loud.
			$bad = function (string $php, string $name) use ($tmp, $app): array {
				$response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
				return [$response[0], $response[1]['error'] ?? null];
			};
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "x": insert must be "top" or "bottom"'],
				$bad("['collections' => ['x' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'insert' => 'middle', 'fields' => ['titel' => 'text']]]]", 'i1'),
				'Unknown value'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "x": insert is only for structure collections'],
				$bad("['collections' => ['x' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'insert' => 'top', 'fields' => ['title' => 'text']]]]", 'i2'),
				'Insert on pages'
			);
			same([200, null], $bad("['collections' => ['x' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'insert' => 'bottom', 'fields' => ['titel' => 'text']]]]", 'i3'), 'Explicit bottom is fine');
		} finally {
			cleanup($tmp);
		}
	}
}
