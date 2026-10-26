<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

function teamRows(callable $app, string $version): array
{
    $yaml = $app()->page('standort')->version($version)->read()['team'] ?? '';
    $rows = is_string($yaml) && trim($yaml) !== '' ? Data::decode($yaml, 'yaml') : [];
    $byName = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $byName[$row['name']] = $row;
    }
    return $byName;
}

/**
 * Action "replace" takes over the published rows: a new row with the key of
 * a published row (Content::sameKey: case, spacing) keeps that row's
 * undeclared columns and image reference. The declared text fields come from
 * the new list alone: one left out is empty afterwards. New keys start empty, vanished keys leave the
 * list (their files stay on the page, as with delete). The draft reports
 * kept, added, removed and withoutImage.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ReplaceMergeTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        try {
            $options = fude_declare($tmp, 'merge', <<<'PHP'
            ['collections' => [
                'team' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'actions' => ['create', 'update', 'replace'],
                    'fields' => [
                        'name' => ['type' => 'text', 'required' => true],
                        'rolle' => ['type' => 'text'],
                        'abteilung' => ['type' => 'select'],
                        'foto' => ['type' => 'image', 'alt' => 'name'],
                    ],
                ],
                'plain' => [
                    'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
                    'actions' => ['replace'],
                    'fields' => ['name' => ['type' => 'text', 'required' => true], 'rolle' => ['type' => 'text']],
                ],
            ]]
            PHP);

            // Published list: one row with an undeclared column ("intern") and an image, one without.
            $kirby = $app();
            $kirby->impersonate('kirby');
            // The image must exist: Kirby's files field drops references to missing files.
            $upload = fude_upload($tmp, 40, 40, 'png', 'foto-joerg');
            $kirby->page('standort')->createFile(['source' => $upload['tmp_name'], 'filename' => 'foto-joerg.png', 'template' => 'bild', 'content' => ['alt' => 'Jörg Muster']]);
            $kirby->page('standort')->update(['team' => [
                ['name' => 'Jörg Muster', 'rolle' => 'Facharzt', 'abteilung' => 'aerzte', 'intern' => 'Urlaub im Mai', 'foto' => ['foto-joerg.png']],
                ['name' => 'Veronika Beispiel', 'rolle' => 'Empfang', 'abteilung' => 'empfang', 'intern' => 'Teilzeit'],
            ]]);
            $foto = teamRows($app, 'latest')['Jörg Muster']['foto'] ?? null;
            same(true, $foto !== null && $foto !== [] && $foto !== '', 'Fixture: the published row has an image reference', $foto);

            // "jörg  MUSTER" has the key of the first row (case and spacing ignored); "Anna Neu" is new.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'replace', 'entries' => [
                ['name' => 'jörg  MUSTER', 'rolle' => 'Chefarzt'],
                ['name' => 'Anna Neu', 'rolle' => 'MFA'],
            ]], options: $options);
            same(200, $code, 'Replace draft', $json);
            same(['kept' => ['jörg MUSTER'], 'added' => ['Anna Neu'], 'removed' => ['Veronika Beispiel'], 'withoutImage' => ['Anna Neu']],
                $json['draft']['replace'] ?? null, 'Replace summary', $json);

            [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same(200, $code, 'Publish the replace', $j);
            $rows = teamRows($app, 'latest');
            same(['jörg MUSTER', 'Anna Neu'], array_keys($rows), 'Published list: kept row and new row', $rows);
            same(['Chefarzt', 'Urlaub im Mai', $foto, true],
                [$rows['jörg MUSTER']['rolle'] ?? null, $rows['jörg MUSTER']['intern'] ?? null, $rows['jörg MUSTER']['foto'] ?? null, empty($rows['jörg MUSTER']['abteilung'] ?? null)],
                'Kept row: declared field replaced, undeclared column and image reference kept, declared field left out is empty');
            // Kirby writes missing columns as empty values ('' or []) when it saves.
            same(['MFA', true, true], [$rows['Anna Neu']['rolle'] ?? null, empty($rows['Anna Neu']['intern'] ?? null), empty($rows['Anna Neu']['foto'] ?? null)], 'New row starts empty', $rows['Anna Neu']);

            // Plain collection (no image field): withoutImage is empty, and a dropped key is "removed".
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'plain', 'action' => 'replace', 'entries' => [['name' => 'anna neu']]], options: $options);
            same(200, $code, 'Plain replace', $json);
            same(['kept' => ['anna neu'], 'added' => [], 'removed' => ['jörg MUSTER'], 'withoutImage' => []],
                $json['draft']['replace'] ?? null, 'Plain replace summary', $json);
            fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
        } finally {
            cleanup($tmp);
        }
    }
}
