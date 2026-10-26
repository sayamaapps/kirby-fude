<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Validation at /fude/draft: unknown field, wrong type, select outside the
 * options, required, maxlength (own and from the blueprint), unknown
 * collection or action, malformed input — nothing is written on any of them.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ValidationTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        try {
            $draft = fn (array $body, array $files = [], array $options = []) => fude_call($app, 'draft', $body, files: $files, options: $options);
            $noDraft = fn () => ok(!is_dir($tmp . '/storage/fude/drafts') || glob($tmp . '/storage/fude/drafts/*.json') === [], 'No draft written')
                && ok(!is_file($tmp . '/content/standort/_changes/standort.txt'), 'No changes version written');

            [$code, $json] = $draft(['collection' => 'nope', 'fields' => ['name' => 'x']]);
            same([404, 'Unknown collection "nope".'], [$code, $json['error']], 'Unknown collection');
            [$code, $json] = $draft(['collection' => 'team', 'action' => 'delete', 'fields' => ['name' => 'x']]);
            same([422, 'Collection "team" does not allow the action "delete".'], [$code, $json['error']], 'Unknown action');
            same(422, fude_call($app, 'draft', ['collection' => 'team', 'action' => ['create']])[0], 'Action that is not text: 422');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'intern' => 'geheim']]);
            same([422, 'Field "intern" is not allowed here. Allowed: name, rolle, abteilung.'], [$code, $json['error']], 'Blueprint field not declared in fude.php is refused');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'passwort' => 'x']]);
            same(422, $code, 'Unknown field');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => ['a', 'b']]]);
            same([422, 'Field "name" must be a text.'], [$code, $json['error']], 'Wrong type');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'abteilung' => 'chef']]);
            same([422, 'Field "abteilung" must be one of: aerzte, empfang, labor.'], [$code, $json['error']], 'Select outside the options');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['rolle' => 'MFA']]);
            same([422, 'Field "name" is required.'], [$code, $json['error']], 'Required field missing on create');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => '   ']]);
            same([422, 'Field "name" is required.'], [$code, $json['error']], 'Whitespace is not a value');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'rolle' => str_repeat('x', 61)]]);
            same([422, 'Field "rolle" may have at most 60 characters.'], [$code, $json['error']], 'maxlength from fude.php');
            [$code, $json] = $draft(['collection' => 'aktuelles', 'fields' => ['titel' => 'T', 'text' => str_repeat('y', 401)]]);
            same([422, 'Field "text" may have at most 400 characters.'], [$code, $json['error']], 'maxlength from the blueprint');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'foto' => 'bild.jpg']]);
            same([422, 'Field "foto" takes an image upload, not a text.'], [$code, $json['error']], 'Image field as text');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => []]);
            same([422, 'Field "name" is required.'], [$code, $json['error']], 'Empty create');
            [$code, $json] = $draft(['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => []]);
            same([422, 'Nothing to change.'], [$code, $json['error']], 'Empty update');
            [$code, $json] = $draft(['collection' => 'team', 'action' => 'update', 'fields' => ['rolle' => 'MFA']]);
            same([422, 'An update needs "key": the Name of the entry to change.'], [$code, $json['error']], 'Update without key');
            [$code, $json] = $draft(['collection' => 'team', 'action' => 'update', 'key' => 'Niemand', 'fields' => ['rolle' => 'MFA']]);
            same([404, 'No entry with Name "Niemand".'], [$code, $json['error']], 'Update of a missing entry');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'jörg muster']]);
            same([409, 'There is already an entry with Name "jörg muster". Use action "update".'], [$code, $json['error']], 'Create of an existing key (case-insensitive)');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => 'not json']);
            same([422, '"fields" must be a JSON object.'], [$code, $json['error']], 'fields as broken JSON string');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['a', 'b']]);
            same([422, '"fields" must be an object.'], [$code, $json['error']], 'fields as a list');
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu'], 'actor' => str_repeat('a', 121)]);
            same([400, '"actor" must be a short text.'], [$code, $json['error']], 'Actor too long');
            // Blueprint validation on the finished entry: "text" is required in the
            // home blueprint's structure, but optional in fude.php for updates.
            [$code, $json] = $draft(['collection' => 'aktuelles', 'fields' => ['titel' => 'Nur Titel']]);
            same([422, 'Field "text" is required.'], [$code, $json['error']], 'Required from the blueprint merges into the declaration');
            [$code, $json] = $draft(['collection' => 'aktuelles', 'fields' => ['titel' => 'Ab', 'text' => 'Zu kurz betitelt']]);
            same(422, $code, 'Blueprint rejects a row violating a rule fude.php does not mirror (minlength)');
            same(true, str_starts_with($json['error'], 'The page blueprint rejects the entry:'), 'Blueprint message', $json['error']);
            $noDraft();
            [$code, $json] = $draft(['collection' => 'aktuelles', 'fields' => ['titel' => 'Grippeimpfung', 'text' => 'Ab Oktober, bitte anmelden.']]);
            same(200, $code, 'Valid row passes the blueprint');
            fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
            // Key field without "required" in fude.php: create without it is refused.
            file_put_contents($tmp . '/site/config/fude-key.php', "<?php\nreturn ['collections' => ['team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'rolle', 'fields' => ['name' => 'text', 'rolle' => 'text']]]];\n");
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Ohne Schluessel']], [], ['fude.config' => 'site/config/fude-key.php']);
            same([422, 'Field "rolle" is required.'], [$code, $json['error']], 'Create without the key field is refused');
            $noDraft();
            // German replies
            [$code, $json] = $draft(['collection' => 'team', 'fields' => ['name' => 'Neu', 'abteilung' => 'chef']], options: ['locale' => 'de_DE.utf8']);
            same('Das Feld „abteilung“ muss eines davon sein: aerzte, empfang, labor.', $json['error'], 'German validation message');
            // Nothing written by any of the refused calls: no drafts, no changes version.
            same([], glob($tmp . '/storage/fude/drafts/*.json') ?: [], 'No draft records left');
            same(false, is_file($tmp . '/content/standort/_changes/standort.txt'), 'No changes version on the location page');
            same(false, is_file($tmp . '/content/home/_changes/home.txt'), 'No changes version on the home page');
            $errors = array_filter(fude_log($tmp), fn ($l) => $l['status'] !== 200);
            same(true, count($errors) >= 20, 'Refusals are logged');
        } finally {
            cleanup($tmp);
        }
    }
}
