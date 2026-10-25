<?php
/**
 * Texts of a plugin blueprint that reach the bot: a translation key in a
 * field's `help` or in a marker's `description` reads as its text in the
 * reply language, as the label already did. A text that is not a key stays
 * as written, and so does a key without a text. A translation array as the
 * help of an editable touch date is read too.
 */
require __DIR__ . '/support.php';

// Registered before the first app of the process, as a plugin's index.php would.
Kirby::plugin('muster/texte', [
    'translations' => [
        'de' => [
            'muster.datum.hilfe' => 'Bis einschließlich dieses Tages sichtbar.',
            'muster.marke' => 'Nur mit Vorbestellung.',
            'muster.gericht.hilfe' => 'Mit Beilagen.',
        ],
        'en' => [
            'muster.datum.hilfe' => 'Shown up to and including this day.',
            'muster.marke' => 'Pre-order only.',
            'muster.gericht.hilfe' => 'With sides.',
        ],
    ],
    'blueprints' => [
        'texte/tabs/karte' => [
            'label' => 'Karte',
            'fields' => [
                'anzeigenBis' => ['label' => 'Anzeigen bis', 'type' => 'date', 'help' => 'muster.datum.hilfe'],
                'anzeigenAb' => ['label' => 'Anzeigen ab', 'type' => 'date', 'help' => ['de' => 'Ab diesem Tag sichtbar.', 'en' => 'Shown from this day.']],
                'gerichte' => [
                    'label' => 'Gerichte',
                    'type' => 'structure',
                    'fude' => ['description' => 'muster.marke', 'actions' => ['replace'], 'touch' => ['field' => 'anzeigenBis', 'value' => 'today', 'editable' => true]],
                    'fields' => [
                        'gericht' => ['label' => 'Gericht', 'type' => 'text', 'required' => true, 'help' => 'muster.gericht.hilfe'],
                        'notiz' => ['label' => 'Notiz', 'type' => 'text', 'help' => 'Leer = keine Notiz.'],
                        'sonst' => ['label' => 'Sonst', 'type' => 'text', 'help' => 'muster.unbekannt'],
                    ],
                ],
                'speisen' => [
                    'label' => 'Speisen',
                    'type' => 'structure',
                    'fude' => ['actions' => ['update'], 'touch' => ['field' => 'anzeigenAb', 'value' => 'today', 'editable' => true]],
                    'fields' => ['name' => ['label' => 'Name', 'type' => 'text', 'required' => true]],
                ],
            ],
        ],
    ],
]);

[$tmp, $app] = fude_fixture();
$failed = false;
try {
    file_put_contents($tmp . '/site/blueprints/pages/speisekarte.yml', "title: Speisekarte\ntabs:\n  karte:\n    extends: texte/tabs/karte\n");
    mkdir($tmp . '/content/speisekarte', 0700, true);
    file_put_contents($tmp . '/content/speisekarte/speisekarte.txt', "Title: Speisekarte\n\n----\n\nAnzeigenbis: 2026-10-09\n\n----\n\nAnzeigenab: 2026-10-01\n\n----\n\nGerichte:\n\n- gericht: Linsensuppe\n\n----\n\nSpeisen:\n\n- name: Brot\n");
    $options = fude_declare($tmp, 'none', "['collections' => []]");

    // What the bot reads, per reply language: keys become their text, plain text stays.
    $expected = [
        'de' => ['Bis einschließlich dieses Tages sichtbar.', 'Ab diesem Tag sichtbar.', 'Nur mit Vorbestellung.', 'Mit Beilagen.'],
        'en' => ['Shown up to and including this day.', 'Shown from this day.', 'Pre-order only.', 'With sides.'],
    ];
    foreach ($expected as $language => [$untilText, $fromText, $marker, $dish]) {
        [$code, $schema] = fude_call($app, 'schema', options: $options + ['fude.language' => $language]);
        same(200, $code, "$language: schema", $schema);

        $gerichte = $schema['collections']['speisekarte-gerichte'] ?? [];
        same($untilText, $gerichte['touchDate']['description'] ?? null, "$language: the touch date's help key is translated");
        same($marker, $gerichte['description'] ?? null, "$language: the marker's description key is translated");
        same($dish, $gerichte['fields']['gericht']['description'] ?? null, "$language: a field's help key is translated");
        same('Leer = keine Notiz.', $gerichte['fields']['notiz']['description'] ?? null, "$language: a plain help text stays as written");
        same('muster.unbekannt', $gerichte['fields']['sonst']['description'] ?? null, "$language: a key without a text stays as written");
        same($fromText, $schema['collections']['speisekarte-speisen']['touchDate']['description'] ?? null, "$language: a translation array as the touch date's help is read");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'TranslatedTexts: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
global $n;
echo "TranslatedTexts: $n checks\n";
exit($failed ? 1 : 0);
