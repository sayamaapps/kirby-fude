<?php
/** Fixture boots, role exists, config loads, routes registered. */
require __DIR__ . '/support.php';
use Fude\Config;
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    $kirby = $app();
    same(['fude', false], [$kirby->user(FUDE_BOT)->role()->name(), $kirby->user(FUDE_BOT)->role()->permissions()->for('access', 'panel')], 'Role created, without Panel');
    $c = Config::load($kirby);
    same(['team', 'aktuelles', 'ohnebild', 'news'], array_keys($c), 'Collections');
    same(['aerzte', 'empfang', 'labor'], $c['team']['fields']['abteilung']['options'], 'Select options from the blueprint');
    same(['Name', true, 'bild'], [$c['team']['fields']['name']['label'], $c['team']['fields']['name']['required'], $c['team']['fields']['foto']['template']], 'Label, required and upload template from the blueprint');
    same(400, $c['aktuelles']['fields']['text']['maxlength'], 'maxlength from the blueprint');
    same(true, in_array('fude/draft', array_column($app()->extensions('routes'), 'pattern'), true), 'Route registered');
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Smoke: $n checks\n";
exit($failed ? 1 : 0);
