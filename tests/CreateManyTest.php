<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

function createManyRows(callable $app, string $version): array
{
	$content = $app()->page('home')->version($version)->read() ?? [];
	$yaml = $content['meldungen'] ?? '';
	$rows = is_string($yaml) && trim($yaml) !== '' ? Data::decode($yaml, 'yaml') : [];
	return is_array($rows) ? array_values($rows) : [];
}

/**
 * Action "create" with `entries` on a structure collection: several new
 * entries in one draft, each with its own image as form part `image<n>` (n
 * = the entry's index). Every row is validated like a create, the keys must
 * be free and distinct, `maxEntries` counts the published rows plus the new
 * ones, `insert: top` puts the new rows first in their order. Discard
 * removes every file the draft stored; a refused draft stores nothing. A
 * `pages` collection, `fields` next to `entries`, a single `image` and an
 * `image<n>` that names no entry are refused. The schema says `createMany`.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class CreateManyTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'createmany', <<<'PHP'
            ['collections' => [
                'menue' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'actions' => ['create', 'update'], 'maxEntries' => 4,
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
                'oben' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel', 'insert' => 'top',
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
                'ohnebild' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
                ],
                'news' => [
                    'kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => 'title',
                    'fields' => ['title' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
            ]]
            PHP);
			$draft = fn (array $body, array $files = [], string $collection = 'menue') => fude_call($app, 'draft', $body + ['collection' => $collection, 'action' => 'create'], options: $options, files: $files);
			$files = fn () => array_map('basename', glob($tmp . '/content/home/*.{png,jpg,gif}', GLOB_BRACE) ?: []);

			// Two entries, each with its image, in one draft.
			[$code, $json] = $draft(
				['actor' => 'telegram:42', 'entries' => [['titel' => 'Tomaten', 'text' => 'Rot.'], ['titel' => 'Gurken', 'text' => 'Grün.']]],
				['image0' => fude_upload($tmp, 300, 200, 'png', 'eins'), 'image1' => fude_upload($tmp, 300, 200, 'jpg', 'zwei')]
			);
			same(200, $code, 'Create with two entries', $json);
			$record = $json['draft'];
			same(['structure', 'create', 'home', null, null, [], ['Tomaten', 'Gurken']], [$record['kind'], $record['action'], $record['page'], $record['key'], $record['index'], $record['fields'], $record['keys']], 'Record: the keys, no single key or index');
			same([['titel' => 'Tomaten', 'text' => 'Rot.'], ['titel' => 'Gurken', 'text' => 'Grün.']], $record['entries'], 'Record: the checked rows');
			same(2, count($record['files']), 'Record: both files');
			same(true, str_starts_with($record['files'][0]['name'], 'tomaten-') && str_ends_with($record['files'][0]['name'], '.png'), 'File of entry 0 named after its key', $record['files']);
			same(true, str_starts_with($record['files'][1]['name'], 'gurken-') && str_ends_with($record['files'][1]['name'], '.jpg'), 'File of entry 1 keeps its format', $record['files']);
			same(true, str_contains($json['preview'], '_version=changes') && str_contains($json['preview'], '_token='), 'Preview of the changes');
			same([], createManyRows($app, 'latest'), 'Published list untouched');
			$rows = createManyRows($app, 'changes');
			same(['Tomaten', 'Gurken'], array_column($rows, 'titel'), 'Changes version holds both rows in order');
			same([1, 1], [count($rows[0]['bild']), count($rows[1]['bild'])], 'Each row references its own file', $rows);
			same(true, $rows[0]['bild'] !== $rows[1]['bild'], 'Two different files');
			same(2, count($files()), 'Two files on the page', $files());
			same('Tomaten', $app()->page('home')->file($record['files'][0]['name'])->alt()->value(), 'Alt text from the key');
			same('Willkommen', $app()->page('home')->version('changes')->read()['leitsatz'], 'Changes version carries the other fields');
			[$code, $j] = fude_call($app, 'publish', ['id' => $record['id'], 'token' => $json['token']], options: $options);
			same(200, $code, 'Published', $j);
			$rows = createManyRows($app, 'latest');
			same(['Tomaten', 'Gurken'], array_column($rows, 'titel'), 'Both rows published');
			$html = $app(['method' => 'GET', 'url' => 'http://example.invalid/home'])->page('home')->render();
			same('NEWS:Tomaten|Rot.|' . $record['files'][0]['name'] . ';Gurken|Grün.|' . $record['files'][1]['name'] . ';', $html, 'The template finds both files');
			same([2, false], [count($files()), $app()->page('home')->version('changes')->exists()], 'Files stay after the publish, changes gone');
			$log = array_values(array_filter(fude_log($tmp), fn (array $line) => ($line['route'] ?? '') === 'draft'));
			same(['menue', 'create', null, 200], [$log[0]['collection'], $log[0]['action'], $log[0]['key'], $log[0]['status']], 'Log: one line, no single key', $log[0]);

			// Entries without an image, a single entry, and the JSON string of a multipart form.
			[$code, $json] = $draft(['entries' => '[{"titel":"Zucchini","text":"Grün."}]']);
			same(200, $code, 'One entry as a JSON string', $json);
			$rows = createManyRows($app, 'changes');
			same(['Tomaten', 'Gurken', 'Zucchini'], array_column($rows, 'titel'), 'Appended after the published rows');
			same(false, isset($rows[2]['bild']) && $rows[2]['bild'] !== [] && $rows[2]['bild'] !== '', 'No image reference without an upload', $rows[2]);
			same(['Zucchini'], $json['draft']['keys'], 'Keys of one entry');
			same([], $json['draft']['files'], 'No files');
			same([200, false, 2], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('home')->version('changes')->exists(), count($files())], 'Discard without files');

			// insert: top puts the new rows first, in their order; discard removes every file of the draft.
			[$code, $json] = $draft(
				['entries' => [['titel' => 'Erstes', 'text' => '1'], ['titel' => 'Zweites', 'text' => '2']]],
				['image1' => fude_upload($tmp, 300, 200, 'png', 'nur-zwei')],
				'oben'
			);
			same(200, $code, 'Create on top', $json);
			$rows = createManyRows($app, 'changes');
			same(['Erstes', 'Zweites', 'Tomaten', 'Gurken'], array_column($rows, 'titel'), 'New rows first, in order');
			same([false, true], [!empty($rows[0]['bild']), !empty($rows[1]['bild'])], 'Only entry 1 has an image', $rows);
			same([null, 1], [$json['draft']['index'], count($json['draft']['files'])], 'No index, one file');
			same(3, count($files()), 'The file is stored');
			[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([200, false], [$code, $j['kept']], 'Discard', $j);
			same([2, false], [count($files()), $app()->page('home')->version('changes')->exists()], 'Discard removed the file and the changes');
			same(['Tomaten', 'Gurken'], array_column(createManyRows($app, 'latest'), 'titel'), 'Published rows untouched');

			// A refused draft stores nothing: format, duplicate and taken keys, maxEntries, fields or image next to entries.
			$refused = function (array $body, array $uploads = [], string $collection = 'menue') use ($draft, $app, $files, $tmp): string {
				[$code, $j] = $draft($body, $uploads, $collection);
				same(422, $code, 'Refused: ' . json_encode($body), $j);
				same([2, false, []], [count($files()), $app()->page('home')->version('changes')->exists(), glob($tmp . '/storage/fude/tmp/*') ?: []], 'Nothing stored by the refusal');
				return $j['error'];
			};
			same('Image format "gif" is not allowed. Allowed: jpg, png, webp.', $refused(
				['entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2']]],
				['image0' => fude_upload($tmp, 300, 200, 'png'), 'image1' => fude_upload($tmp, 300, 200, 'gif')]
			), 'Every image is checked before anything is stored');
			same('Two entries have the same Überschrift "tomatensalat".', $refused(['entries' => [['titel' => 'Tomatensalat', 'text' => '1'], ['titel' => 'tomatensalat', 'text' => '2']]]), 'Duplicate key in the list');
			same('Field "titel" is required.', $refused(['entries' => [['text' => 'ohne Titel']]]), 'Row without the key field');
			same('Field "preis" is not allowed here. Allowed: titel, text.', $refused(['entries' => [['titel' => 'Pilze', 'preis' => '9']]]), 'Unknown field in a row');
			same('Action "create" needs "entries": a JSON array of entries.', $refused(['entries' => ['titel' => 'Eins']]), 'Entries as an object');
			same('"entries" of a create needs at least one entry.', $refused(['entries' => []]), 'No entries');
			same('A create with "entries" takes no "fields".', $refused(['entries' => [['titel' => 'Eins', 'text' => '1']], 'fields' => ['titel' => 'Zwei']]), 'Fields next to entries');
			same('A create with "entries" takes its images as "image0", "image1", … (one per entry), not as "image".', $refused(['entries' => [['titel' => 'Eins', 'text' => '1']]], ['image' => fude_upload($tmp, 300, 200)]), 'Single image next to entries');
			same('The upload "image2" matches no entry in "entries".', $refused(['entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2']]], ['image2' => fude_upload($tmp, 300, 200)]), 'Image for an entry that is not there');
			same('The upload "image0" matches no entry in "entries".', $refused(['fields' => ['titel' => 'Eins', 'text' => '1']], ['image0' => fude_upload($tmp, 300, 200)]), 'image<n> on a create of one entry');
			same('The upload "image0" matches no entry in "entries".', $refused(['action' => 'update', 'key' => 'Tomaten', 'fields' => ['text' => 'neu']], ['image0' => fude_upload($tmp, 300, 200)]), 'image<n> on an update');
			same('The list holds at most 4 entries.', $refused(['entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2'], ['titel' => 'Drei', 'text' => '3']]]), 'maxEntries counts the published rows plus the new ones');
			same('This collection takes no image.', $refused(['entries' => [['titel' => 'Eins', 'text' => '1']]], ['image0' => fude_upload($tmp, 300, 200)], 'ohnebild'), 'Collection without an image field');
			same('Several entries in one create ("entries") need a structure collection; "news" is not one.', $refused(['entries' => [['title' => 'Eins', 'text' => '1']]], [], 'news'), 'Pages collection');
			[$code, $j] = $draft(['entries' => [['titel' => 'Lauch', 'text' => 'l'], ['titel' => 'tomaten', 'text' => 't']]]);
			same([409, 'There is already an entry with Überschrift "tomaten". Use action "update".'], [$code, $j['error']], 'A key that is taken, whatever the case');
			same(false, $app()->page('home')->version('changes')->exists(), 'No refused draft left a changes version');

			// Exactly maxEntries is fine; a collection without an image field takes entries without images.
			[$code, $json] = $draft(['entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2']]], [], 'ohnebild');
			same(200, $code, 'Two entries without images', $json);
			same(['Tomaten', 'Gurken', 'Eins', 'Zwei'], array_column(createManyRows($app, 'changes'), 'titel'), 'Four rows in the changes');
			same([200, false], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('home')->version('changes')->exists()], 'Discard');

			// Schema: a structure collection with create says so; a pages collection does not.
			$schema = fude_call($app, 'schema', options: $options)[1]['collections'];
			same([true, true, true, false], [$schema['menue']['createMany'] ?? false, $schema['oben']['createMany'] ?? false, $schema['ohnebild']['createMany'] ?? false, array_key_exists('createMany', $schema['news'])], 'Schema: createMany');

			// A publish after a Panel edit refuses, as with every structure draft.
			[, $json] = $draft(['entries' => [['titel' => 'Neu', 'text' => 'n']]]);
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('home')->update(['meldungen' => [['titel' => 'Panel', 'text' => 'p']]]);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([409, 'The published content changed since this draft was made. Create a new draft.'], [$code, $j['error']], 'Panel edit in between');
		} finally {
			cleanup($tmp);
		}
	}
}
