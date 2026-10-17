<?php
/**
 * POST /fude/discard: removes the changes version and the uploaded file,
 * deletes the record, needs the token, is idempotent per record (second
 * call 404). A changes version edited in the Panel meanwhile is kept.
 */
require __DIR__ . '/support.php';
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Anna Neu']], files: ['image' => fude_upload($tmp, 900, 600)]);
    same(200, $code, 'Draft with image', $json);
    $id = $json['draft']['id'];
    $token = $json['token'];
    $file = $json['draft']['file'];
    same(true, is_file($tmp . '/content/standort/' . $file), 'Uploaded file on the page');
    same(true, $app()->page('standort')->version('changes')->exists(), 'Changes version there');

    same(403, fude_call($app, 'discard', ['id' => $id, 'token' => 'wrong'])[0], 'Discard needs the token');
    same(404, fude_call($app, 'discard', ['id' => 'ffffffffffffffff', 'token' => $token])[0], 'Unknown id');
    [$code, $j] = fude_call($app, 'discard', ['id' => $id, 'token' => $token, 'actor' => 'telegram:42']);
    same([200, $id, false], [$code, $j['id'], $j['kept']], 'Discarded');
    same(false, $app()->page('standort')->version('changes')->exists(), 'Changes version removed');
    same(false, is_file($tmp . '/content/standort/' . $file), 'Uploaded file removed');
    same(false, is_file($tmp . '/content/standort/' . $file . '.txt'), 'File meta removed');
    same(false, is_file($tmp . '/storage/fude/drafts/' . $id . '.json'), 'Record removed');
    same(2, $app()->page('standort')->team()->toStructure()->count(), 'Published rows unchanged');
    same(404, fude_call($app, 'discard', ['id' => $id, 'token' => $token])[0], 'Second discard: 404');
    same(404, fude_call($app, 'publish', ['id' => $id, 'token' => $token])[0], 'Publish after discard: 404');

    // Discard of an expired draft still works (no lifetime check there).
    [, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Alt']]);
    $record = Fude\Drafts::read($app(), $json['draft']['id']);
    $record['createdTs'] -= 100 * 3600;
    Fude\Drafts::write($app(), $record);
    same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']])[0], 'Expired draft can be discarded');
    same(false, $app()->page('standort')->version('changes')->exists(), 'Changes removed');

    // A draft whose changes version was discarded in the Panel already: fine.
    [, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Weg']]);
    $app()->page('standort')->version('changes')->delete();
    [$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([200, false], [$code, $j['kept']], 'Discard without a changes version');

    // The draft was published in the Panel meanwhile: its image is live and stays.
    [, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Live Foto']], files: ['image' => fude_upload($tmp, 900, 600)]);
    $liveFile = $json['draft']['file'];
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page('standort')->version('changes')->publish();
    [$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([200, false], [$code, $j['kept']], 'Discard after the Panel published the draft');
    same(true, is_file($tmp . '/content/standort/' . $liveFile), 'Image of a published structure draft stays');
    same(3, $app()->page('standort')->team()->toStructure()->count(), 'Published row still there');

    // The changes version is gone (dropped in the Panel): the image stays too.
    [, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Verworfen']], files: ['image' => fude_upload($tmp, 900, 600)]);
    $droppedFile = $json['draft']['file'];
    $app()->page('standort')->version('changes')->delete();
    [$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([200, false], [$code, $j['kept']], 'Discard after the changes were dropped in the Panel');
    same(true, is_file($tmp . '/content/standort/' . $droppedFile), 'Image stays when the changes are gone');

    // Pages update: the child's changes version is discarded, its image goes;
    // once the Panel has published them, the image stays.
    [, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Foto Fall', 'text' => 'Text']]);
    fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    $child = $json['draft']['page'];
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Foto Fall', 'fields' => ['text' => 'Neu']], files: ['image' => fude_upload($tmp, 1800, 1200, 'jpg')]);
    same(200, $code, 'Pages update with image', $json);
    $pageFile = $json['draft']['file'];
    [$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([200, false], [$code, $j['kept']], 'Pages update discarded');
    same(null, $app()->page($child)->file($pageFile), 'Pages update: image of the discarded draft removed');

    [, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Foto Fall', 'fields' => ['text' => 'Neu 2']], files: ['image' => fude_upload($tmp, 1800, 1200, 'jpg')]);
    $pageFile = $json['draft']['file'];
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page($child)->version('changes')->publish();
    [$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([200, false], [$code, $j['kept']], 'Pages update discarded after the Panel published it');
    same(true, $app()->page($child)->file($pageFile) !== null, 'Pages update: published image stays');

    $log = fude_log($tmp);
    $discards = array_values(array_filter($log, fn ($l) => $l['route'] === 'discard' && $l['status'] === 200));
    same(['telegram:42', 'team', 'Anna Neu', $id], [$discards[0]['actor'], $discards[0]['collection'], $discards[0]['key'], $discards[0]['draft']], 'Discard log line');
} catch (Throwable $e) {
    fwrite(STDERR, 'Discard: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Discard: $n checks\n";
exit($failed ? 1 : 0);
