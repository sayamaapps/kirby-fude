<?php
/**
 * A required blueprint field with a default, in a pages collection: Kirby
 * fills the default when it creates the child page, so the bot need not send
 * a value. The field is not required in the schema, a create without it
 * stores the default, and an update that empties it is still refused by the
 * blueprint. Declared `required: true` and structure rows (which get no
 * defaults) stay required. The page "termine" lives in this file only.
 */
require __DIR__ . '/support.php';

const RD_TERMIN = <<<'YML'
    title: Termin
    fields:
      zustand:
        label: Zustand
        type: select
        options:
          - geplant
          - abgesagt
        default: geplant
        required: true
      kapazitaet:
        label: Plätze
        type: number
        required: true
      zeiten:
        label: Zeiten
        type: structure
        fields:
          tag:
            label: Tag
            type: text
          zustand:
            label: Zustand
            type: select
            options:
              - geplant
              - abgesagt
            default: geplant
            required: true
    YML;

[$tmp, $app] = fude_fixture();
$failed = false;
try {
    mkdir($tmp . '/content/termine', 0700, true);
    file_put_contents($tmp . '/content/termine/termine.txt', "Title: Termine\n");
    file_put_contents($tmp . '/site/blueprints/pages/termin.yml', RD_TERMIN);
    $declaration = <<<'PHP'
    ['collections' => [
        'termine' => ['kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title',
            'fields' => ['title' => 'text', 'zustand' => 'select', 'kapazitaet' => 'number']],
        'streng' => ['kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title',
            'fields' => ['title' => 'text', 'zustand' => ['type' => 'select', 'required' => true]]],
    ]]
    PHP;
    $opt = fude_declare($tmp, 'rd', $declaration) + ['fude.blueprints' => false];

    [$code, $json] = fude_call($app, 'schema', options: $opt);
    same(200, $code, 'Schema');
    same([false, true, true], [
        $json['collections']['termine']['fields']['zustand']['required'] ?? null,
        $json['collections']['termine']['fields']['kapazitaet']['required'] ?? null,
        $json['collections']['streng']['fields']['zustand']['required'] ?? null,
    ], 'Required with a default: not required; without a default or declared required: required');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Herbstkurs', 'kapazitaet' => '8']], options: $opt);
    same(200, $code, 'Create without the defaulted field');
    same('geplant', $app()->page($json['draft']['page'])->zustand()->value(), 'Kirby filled the default');
    same(200, fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $opt)[0], 'Published');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Winterkurs']], options: $opt);
    same([422, 'Field "kapazitaet" is required.'], [$code, $json['error'] ?? null], 'Required without a default stays required');
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'streng', 'fields' => ['title' => 'Winterkurs']], options: $opt);
    same([422, 'Field "zustand" is required.'], [$code, $json['error'] ?? null], 'Declared required stays required');
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'action' => 'update', 'key' => 'Herbstkurs', 'fields' => ['zustand' => '']], options: $opt);
    same(422, $code, 'Emptying it on an existing page: the blueprint refuses');
    ok(str_contains($json['error'] ?? '', 'Zustand'), 'The blueprint names the field', $json['error'] ?? null);

    $kirby = $app();
    $kirby->impersonate('kirby');
    $slug = $kirby->page('termine')->children()->first()->id();
    $structure = fude_declare($tmp, 'rds', "['collections' => ['zeiten' => ['kind' => 'structure', 'page' => '$slug', 'field' => 'zeiten', 'key' => 'tag', 'fields' => ['tag' => 'text', 'zustand' => 'select']]]]") + ['fude.blueprints' => false];
    same(true, fude_call($app, 'schema', options: $structure)[1]['collections']['zeiten']['fields']['zustand']['required'] ?? null, 'Structure rows get no defaults: still required');
} catch (Throwable $e) {
    fwrite(STDERR, 'RequiredDefault: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "RequiredDefault: $n checks\n";
exit($failed ? 1 : 0);
