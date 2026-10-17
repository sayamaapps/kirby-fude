<?php
/**
 * Markers that cannot be honoured are loud: a site field marker, an unsupported
 * type, files on a page, a name collision, an unknown option, a touch field
 * the page does not have, too many collections. Kirby caches blueprints once per PHP process, so every case
 * runs in a child process of its own (this file, with the case name as argument).
 */
require __DIR__ . '/support.php';

const CASES = [
    'site' => [
        'files' => ['site.yml' => "title: Site\nfields:\n  titel:\n    type: text\n    fude: true\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: site fields are not supported (field "titel" in site.txt carries a fude marker)',
    ],
    'unsupported' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  kurzname:\n    type: tags\n    fude: true\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "kurzname": type "tags" is not supported by fude',
    ],
    'files-on-page' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  bild:\n    type: files\n    fude: true\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "bild": files fields are only supported inside a structure',
    ],
    'unsupported-sub-field' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: true\n    fields:\n      seite:\n        type: pages\n        fude: true\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "team", sub-field "seite": type "pages" is not supported by fude',
    ],
    'collision' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {name: x}\n    fields:\n      n:\n        type: text\n  zeiten:\n    type: structure\n    fude: {name: x}\n    fields:\n      n:\n        type: text\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: two marked fields would both become the collection "x": set fude: {name: …} on the structure field',
    ],
    'unknown-option' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {foo: 1}\n    fields:\n      n:\n        type: text\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "team": unknown fude option "foo"',
    ],
    'touch-missing-field' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {touch: {field: stand, value: today}}\n    fields:\n      n:\n        type: text\n"],
        'options' => [],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "standort-team": touch field "stand" is not in the blueprint of "standort"',
    ],
    'limit' => [
        'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  kurzname:\n    type: text\n    fude: true\n  team:\n    type: structure\n    fude: true\n    fields:\n      n:\n        type: text\n"],
        'options' => ['fude.maxCollections' => 1],
        'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: 2 collections, at most 1 (fude.maxCollections; the bot reads them all)',
    ],
];

/** Child process: one case, prints the error (or 200) of a schema call. */
if (isset($argv[1])) {
    $case = CASES[$argv[1]];
    [$tmp, $app] = fude_fixture();
    file_put_contents($tmp . '/site/blueprints/pages/standort.yml', "title: Standort\nfields: {}\n");
    foreach ($case['files'] as $path => $yaml) {
        file_put_contents($tmp . '/site/blueprints/' . $path, $yaml);
    }
    $options = fude_declare($tmp, 'none', "['collections' => []]");
    [$code, $json] = fude_call($app, 'schema', options: $case['options'] + $options);
    echo json_encode([$code, $json['error'] ?? null], JSON_UNESCAPED_UNICODE);
    cleanup($tmp);
    exit(0);
}

$failed = false;
try {
    foreach (CASES as $name => $case) {
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name) . ' 2>&1');
        same(json_encode([500, $case['error']], JSON_UNESCAPED_UNICODE), trim((string) $out), 'Error case ' . $name);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'BlueprintsErrors: ' . $e->getMessage() . "\n");
    $failed = true;
}
global $n;
echo "BlueprintsErrors: $n checks\n";
exit($failed ? 1 : 0);
