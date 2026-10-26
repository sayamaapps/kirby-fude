<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

function replaceTitles(callable $app, string $version): array
{
    $content = $app()->page('home')->version($version)->read() ?? [];
    $yaml = $content['meldungen'] ?? '';
    $rows = is_string($yaml) && trim($yaml) !== '' ? Data::decode($yaml, 'yaml') : [];
    return array_column(is_array($rows) ? $rows : [], 'titel');
}

/**
 * Action "replace" on a structure collection: `entries` (a JSON list, or the
 * same JSON as a string from a multipart form) replaces the whole list in one
 * draft. Every row is validated like a create; no image; `maxEntries` caps the
 * list for replace and create; an empty list is allowed.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ReplaceTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        try {
            $options = fude_declare($tmp, 'replace', <<<'PHP'
            ['collections' => [
                'menue' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'actions' => ['create', 'update', 'replace'], 'maxEntries' => 3,
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
                'plain' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'fields' => ['titel' => ['type' => 'text'], 'text' => ['type' => 'textarea']],
                ],
            ]]
            PHP);

            // The whole list in one draft, published.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace', 'actor' => 'telegram:42',
                'entries' => [['titel' => 'Grippeimpfung', 'text' => 'Ab Oktober.'], ['titel' => 'Ferien', 'text' => 'Vom 5. bis 15.']]], options: $options);
            same(200, $code, 'Replace draft', $json);
            same(['structure', 'replace', 'home', null, null], [$json['draft']['kind'], $json['draft']['action'], $json['draft']['page'], $json['draft']['index'], $json['draft']['key']], 'Record: no single key, no index');
            same(true, str_contains($json['preview'], '_version=changes') && str_contains($json['preview'], '_token='), 'Preview of the changes');
            same([], replaceTitles($app, 'latest'), 'Published list untouched');
            same(['Grippeimpfung', 'Ferien'], replaceTitles($app, 'changes'), 'Changes version holds the new list');
            same('Willkommen', $app()->page('home')->version('changes')->read()['leitsatz'], 'Changes version carries the other fields');
            [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same([200, ['Grippeimpfung', 'Ferien']], [$code, replaceTitles($app, 'latest')], 'Published', $j);

            // The multipart form sends the list as a JSON string.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace', 'entries' => '[{"titel":"Suppe","text":"Kürbis"}]'], options: $options);
            same(200, $code, 'Replace with a JSON string', $json);
            same(['Suppe'], replaceTitles($app, 'changes'), 'JSON string parsed');
            same([200, false], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('home')->version('changes')->exists()], 'Discard');
            same(['Grippeimpfung', 'Ferien'], replaceTitles($app, 'latest'), 'Discard: published list stays');

            // An empty list is allowed: it empties the list.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace', 'entries' => []], options: $options);
            same(200, $code, 'Empty list', $json);
            [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same([200, []], [$code, replaceTitles($app, 'latest')], 'Publish empties the list', $j);
            same([[], 0], [fude_call($app, 'schema', options: $options)[1]['collections']['menue']['entries'], $app()->page('home')->meldungen()->toStructure()->count()], 'Schema and page: empty');

            // Every row is validated like a create; a refused replace writes nothing.
            $refused = fn (array $body, $files = []) => fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace'] + $body, options: $options, files: $files);
            [$code, $j] = $refused(['entries' => [['text' => 'ohne Titel']]]);
            same([422, 'Field "titel" is required.'], [$code, $j['error']], 'Row without the key field');
            [$code, $j] = $refused(['entries' => [['titel' => 'Pilz', 'preis' => '9']]]);
            same([422, 'Field "preis" is not allowed here. Allowed: titel, text.'], [$code, $j['error']], 'Unknown field in a row');
            [$code, $j] = $refused(['entries' => [['titel' => 'Lang', 'text' => str_repeat('x', 401)]]]);
            same([422, 'Field "text" may have at most 400 characters.'], [$code, $j['error']], 'Blueprint maxlength in a row');
            [$code, $j] = $refused(['entries' => [['titel' => 'Ferien', 'text' => 'a'], ['titel' => 'ferien', 'text' => 'b']]]);
            same([422, 'Two entries have the same Überschrift "ferien".'], [$code, $j['error']], 'Duplicate key in the new list');
            [$code, $j] = $refused(['entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2'], ['titel' => 'Drei', 'text' => '3'], ['titel' => 'Vier', 'text' => '4']]]);
            same([422, 'The list holds at most 3 entries.'], [$code, $j['error']], 'More than maxEntries');
            [$code, $j] = $refused(['entries' => ['titel' => 'Eins']]);
            same([422, 'Action "replace" needs "entries": a JSON array of entries.'], [$code, $j['error']], 'Entries as an object');
            [$code, $j] = $refused([]);
            same([422, 'Action "replace" needs "entries": a JSON array of entries.'], [$code, $j['error']], 'Entries missing');
            [$code, $j] = $refused(['entries' => '{nicht json']);
            same([422, 'Action "replace" needs "entries": a JSON array of entries.'], [$code, $j['error']], 'Entries not JSON');
            [$code, $j] = $refused(['entries' => [], 'fields' => ['titel' => 'x']]);
            same([422, 'Action "replace" takes "entries" only, not fields.'], [$code, $j['error']], 'Fields next to entries');
            [$code, $j] = $refused(['entries' => []], ['image' => fude_upload($tmp, 300, 200)]);
            same([422, 'Action "replace" takes no image.'], [$code, $j['error']], 'No image with replace');
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => []], options: $options);
            same([422, 'Collection "plain" does not allow the action "replace".'], [$code, $j['error']], 'Replace only when listed');
            same(false, $app()->page('home')->version('changes')->exists(), 'No refused draft left a changes version');

            // maxEntries: the list holds three at most, for replace and for create.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace', 'entries' => [['titel' => 'Eins', 'text' => '1'], ['titel' => 'Zwei', 'text' => '2'], ['titel' => 'Drei', 'text' => '3']]], options: $options);
            same(200, $code, 'Exactly maxEntries is fine', $json);
            [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same(['Eins', 'Zwei', 'Drei'], replaceTitles($app, 'latest'), 'Three published', $j);
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'menue', 'fields' => ['titel' => 'Vier', 'text' => '4']], options: $options);
            same([422, 'The list holds at most 3 entries.'], [$code, $j['error']], 'Create beyond maxEntries');
            same(false, $app()->page('home')->version('changes')->exists(), 'Refused create leaves no changes');

            // A Panel change after the draft: publish refuses (409), as with update.
            [, $json] = fude_call($app, 'draft', ['collection' => 'menue', 'action' => 'replace', 'entries' => [['titel' => 'Neu', 'text' => 'n']]], options: $options);
            $kirby = $app();
            $kirby->impersonate('kirby');
            $kirby->page('home')->update(['meldungen' => [['titel' => 'Panel', 'text' => 'p']]]);
            [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same([409, 'The published content changed since this draft was made. Create a new draft.'], [$code, $j['error']], 'Panel edit in between');
            same([200, false], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('home')->version('changes')->exists()], 'Discard after the conflict');

            // Schema: maxEntries and the new action are visible; a plain collection shows neither.
            $schema = fude_call($app, 'schema', options: $options)[1]['collections'];
            same([3, ['create', 'update', 'replace']], [$schema['menue']['maxEntries'], $schema['menue']['actions']], 'Schema: maxEntries and actions');
            same(false, array_key_exists('maxEntries', $schema['plain']), 'Schema: no maxEntries when not declared');

            // Log: the replace is one line with its action.
            $first = fude_log($tmp)[0];
            same(['draft', 'menue', 'replace', 200], [$first['route'], $first['collection'], $first['action'], $first['status']], 'Log line of the first replace');

            // Declaration mistakes are loud.
            $bad = function (string $php, string $name) use ($tmp, $app): array {
                $response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
                return [$response[0], $response[1]['error'] ?? null];
            };
            same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "notiz": maxEntries is only for structure collections'],
                $bad("['collections' => ['notiz' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'maxEntries' => 3, 'fields' => ['title' => 'text']]]]", 'b1'), 'maxEntries on pages');
            same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "menue": maxEntries must be a positive integer'],
                $bad("['collections' => ['menue' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'maxEntries' => 0, 'fields' => ['titel' => 'text']]]]", 'b2'), 'maxEntries zero');
            same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "menue": maxEntries must be a positive integer'],
                $bad("['collections' => ['menue' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'maxEntries' => '3', 'fields' => ['titel' => 'text']]]]", 'b3'), 'maxEntries as a string');
            same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "notiz": replace is only for structure collections'],
                $bad("['collections' => ['notiz' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'actions' => ['create', 'replace'], 'fields' => ['title' => 'text']]]]", 'b4'), 'replace on pages');
            same([200, null], $bad("['collections' => ['menue' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'actions' => ['replace'], 'fields' => ['titel' => 'text']]]]", 'b5'), 'replace alone is fine on structure');
        } finally {
            cleanup($tmp);
        }
    }
}
