<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Limits on the raw input of replace, regardless of maxEntries: at most
 * Service::MAX_REPLACE_ENTRIES rows (checked before any row is validated),
 * a JSON string of at most 64 KB, a JSON depth of 4, and a declared
 * maxEntries above the hard limit is a configuration error.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class EntriesLimitTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        try {
            $options = fude_declare($tmp, 'limits', <<<'PHP'
            ['collections' => [
                'plain' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'actions' => ['create', 'update', 'replace'],
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
                ],
            ]]
            PHP);
            $limit = Fude\Service::MAX_REPLACE_ENTRIES;
            same(100, $limit, 'Hard limit is 100');

            // Over the hard limit, without any maxEntries — and the rows are invalid
            // on purpose: the count must be refused before the rows are looked at.
            $tooMany = array_fill(0, $limit + 1, ['text' => 'ohne Titel']);
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => $tooMany], options: $options);
            same([422, 'The list holds at most 100 entries.'], [$code, $j['error']], 'Count checked before the rows');
            same(false, $app()->page('home')->version('changes')->exists(), 'Nothing written');

            // The same limit as a JSON string (multipart form).
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => json_encode($tooMany)], options: $options);
            same([422, 'The list holds at most 100 entries.'], [$code, $j['error']], 'Count checked for the JSON string too');

            // A JSON string longer than 64 KB is refused before json_decode.
            $huge = '[' . str_repeat('{"titel":"x","text":"' . str_repeat('a', 1000) . '"},', 70) . '{"titel":"y"}]';
            same(true, strlen($huge) > 64 * 1024, 'Fixture is above 64 KB');
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => $huge], options: $options);
            same([422, 'Action "replace" needs "entries": a JSON array of entries.'], [$code, $j['error']], 'JSON string above 64 KB');

            // A JSON string nested deeper than 4 is not decoded.
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => '[{"titel":{"a":{"b":{"c":"x"}}}}]'], options: $options);
            same([422, 'Action "replace" needs "entries": a JSON array of entries.'], [$code, $j['error']], 'JSON deeper than 4');

            // A normal list still works.
            [$code, $j] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => [['titel' => 'Eins', 'text' => '1']]], options: $options);
            same(200, $code, 'A small list is fine', $j);
            fude_call($app, 'discard', ['id' => $j['draft']['id'], 'token' => $j['token']], options: $options);

            // maxEntries above the hard limit is a configuration error.
            $bad = fude_call($app, 'schema', options: fude_declare($tmp, 'big', "['collections' => ['menue' => ['kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'maxEntries' => 101, 'fields' => ['titel' => 'text']]]]"));
            same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "menue": maxEntries must be at most 100'], [$bad[0], $bad[1]['error']], 'maxEntries above the hard limit');
        } finally {
            cleanup($tmp);
        }
    }
}
