<?php
/**
 * Select fields whose options come from a Kirby query
 * (`options: {type: query, query: page.bereiche.toStructure}`): Fude resolves
 * them against the page, as Kirby's Panel does, so the declared field takes
 * the query's values and refuses the others. Static options are covered by
 * BlueprintsTest and ValidationTest.
 */
require __DIR__ . '/support.php';

use Fude\Config;

const QUERY_STANDORT = <<<'YML'
title: Standort
fields:
  bereiche:
    label: Bereiche
    type: structure
    fields:
      kuerzel:
        label: Kürzel
        type: text
      titel:
        label: Titel
        type: text
  betrieb:
    label: Schließungen
    type: structure
    fields:
      name:
        label: Name
        type: text
        required: true
      bereich:
        label: Bereich
        type: select
        options:
          type: query
          query: page.bereiche.toStructure
          text: "{{ item.titel }}"
          value: "{{ item.kuerzel }}"
YML;

const QUERY_CONTENT = <<<'TXT'
Title: Standort

----

Bereiche:

- kuerzel: aerzte
  titel: Ärzte
- kuerzel: labor
  titel: Labor

----

Betrieb:

- name: Sommerpause
  bereich: aerzte
TXT;

$failed = false;
try {
    [$tmp, $app] = fude_fixture();
    file_put_contents($tmp . '/site/blueprints/pages/standort.yml', QUERY_STANDORT);
    file_put_contents($tmp . '/content/standort/standort.txt', QUERY_CONTENT);
    $options = fude_declare($tmp, 'query', <<<'PHP'
    ['collections' => [
        'betrieb' => [
            'kind' => 'structure',
            'page' => 'standort',
            'field' => 'betrieb',
            'key' => 'name',
            'actions' => ['create', 'update', 'delete'],
            'fields' => ['name' => 'text', 'bereich' => 'select'],
        ],
    ]]
    PHP);

    // The query's values (the structure's keys) are the allowed options.
    $c = Config::load($app(options: $options));
    same(['aerzte', 'labor'], $c['betrieb']['fields']['bereich']['options'], 'Query options resolved against the page');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'betrieb', 'fields' => ['name' => 'Urlaub', 'bereich' => 'labor']], options: $options);
    same(200, $code, 'Value from the query is accepted', $json);

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'betrieb', 'fields' => ['name' => 'Urlaub 2', 'bereich' => 'chef']], options: $options);
    same([422, 'Field "bereich" must be one of: aerzte, labor.'], [$code, $json['error'] ?? null], 'Value outside the query is refused');
} catch (Throwable $e) {
    fwrite(STDERR, 'QueryOptions: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    if (isset($tmp)) {
        cleanup($tmp);
    }
}
global $n;
echo "QueryOptions: $n checks\n";
exit($failed ? 1 : 0);
