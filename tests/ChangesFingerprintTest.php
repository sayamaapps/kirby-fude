<?php
/**
 * A structure draft is confirmed as a whole: the fingerprint covers the whole
 * changes version, not only the structure field. A Panel edit of ANOTHER field
 * (here the introduction of the same page) between draft and publish must be
 * refused with 409, and discard must keep that Panel version.
 */
require __DIR__ . '/support.php';
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Anna Neu', 'rolle' => 'MFA']]);
    same(200, $code, 'Structure draft', $json);
    $draft = ['id' => $json['draft']['id'], 'token' => $json['token']];

    // Somebody edits the introduction in the Panel, without publishing.
    $kirby = $app();
    $kirby->impersonate('kirby');
    $changes = $kirby->page('standort')->version('changes');
    $content = $changes->read();
    $content['einleitung'] = 'Vom Panel geaendert.';
    $changes->save($content);

    [$code, $j] = fude_call($app, 'publish', $draft);
    same([409, 'The draft was changed in the Panel meanwhile. Publish it there, or discard it and start again.'], [$code, $j['error'] ?? null], 'Panel edit of another field blocks publish', $j);
    same(['latest' => 'Unser Haus in Musterstadt.'], ['latest' => $app()->page('standort')->version('latest')->read()['einleitung']], 'Published introduction untouched');

    [$code, $j] = fude_call($app, 'discard', $draft);
    same([200, true], [$code, $j['kept'] ?? null], 'Discard keeps the Panel version', $j);
    same('Vom Panel geaendert.', $app()->page('standort')->version('changes')->read()['einleitung'], 'Panel introduction still in the changes version');
} catch (Throwable $e) {
    fwrite(STDERR, 'ChangesFingerprint: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "ChangesFingerprint: $n checks\n";
exit($failed ? 1 : 0);
