<?php
/**
 * POST /fude/publish: token, lifetime, hash conflict with a parallel Panel
 * edit, draft changed in the Panel, then the real publish — content live,
 * changes version gone, record gone, second publish 404.
 */
require __DIR__ . '/support.php';
use Fude\Drafts;
use Kirby\Data\Data;
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    $create = fn () => fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Anna Neu', 'rolle' => 'MFA'], 'actor' => 'telegram:42']);
    [, $json] = $create();
    $id = $json['draft']['id'];
    $token = $json['token'];

    [$code, $j] = fude_call($app, 'publish', ['id' => 'nope']);
    same([404, 'Unknown draft "nope".'], [$code, $j['error']], 'Unknown id');
    [$code, $j] = fude_call($app, 'publish', ['id' => $id, 'token' => 'wrong']);
    same([403, 'The confirmation token does not match this draft.'], [$code, $j['error']], 'Wrong token');
    [$code, $j] = fude_call($app, 'publish', ['id' => $id]);
    same(403, $code, 'Missing token');
    same(2, $app()->page('standort')->team()->toStructure()->count(), 'Nothing published yet');

    // Lifetime: a record older than fude.draftLifetime is refused (410).
    $record = Drafts::read($app(), $id);
    $record['createdTs'] -= 49 * 3600;
    Drafts::write($app(), $record);
    [$code, $j] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
    same([410, 'This draft is older than 48 hours and can no longer be published. Create a new one.'], [$code, $j['error']], 'Expired');
    same(200, fude_call($app, 'publish', ['id' => $id, 'token' => $token, 'actor' => 'x'], options: ['fude.draftLifetime' => 50 * 3600, 'fude.roles' => ['nobody']])[0] === 403 ? 200 : 0, 'A longer lifetime lets the age check pass (next gate: roles)');
    $record['createdTs'] += 49 * 3600;
    Drafts::write($app(), $record);

    // Hash conflict: somebody published a change in the Panel meanwhile.
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page('standort')->update(['team' => [['name' => 'Jörg Muster', 'rolle' => 'Chef', 'abteilung' => 'aerzte'], ['name' => 'Veronika Beispiel', 'abteilung' => 'empfang']]]);
    [$code, $j] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
    same([409, 'The published content changed since this draft was made. Create a new draft.'], [$code, $j['error']], 'Hash conflict');
    same(64, strlen($j['hash']), 'Current hash in the reply');
    same('Chef', $app()->page('standort')->team()->toStructure()->first()->rolle()->value(), 'Panel change stands');
    // The draft is still there — Fude does not resolve the conflict; the
    // bot discards and starts over.
    same(200, fude_call($app, 'discard', ['id' => $id, 'token' => $token])[0], 'Discard after conflict');

    // Draft changed in the Panel: the changes version differs from the record.
    [, $json] = $create();
    $id = $json['draft']['id'];
    $token = $json['token'];
    $kirby = $app();
    $kirby->impersonate('kirby');
    $changes = $kirby->page('standort')->version('changes');
    $rows = Data::decode($changes->read()['team'], 'yaml');
    $rows[2]['rolle'] = 'Vom Panel geändert';
    $changes->update(['team' => $rows]);
    [$code, $j] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
    same([409, 'The draft was changed in the Panel meanwhile. Publish it there, or discard it and start again.'], [$code, $j['error']], 'Draft edited in the Panel');
    [$code, $j] = fude_call($app, 'discard', ['id' => $id, 'token' => $token]);
    same([200, true], [$code, $j['kept']], 'Discard keeps the Panel-edited changes');
    same(true, $app()->page('standort')->version('changes')->exists(), 'Changes version kept');
    $app()->page('standort')->version('changes')->delete();

    // Draft gone from the page (changes deleted in the Panel).
    [, $json] = $create();
    $app()->page('standort')->version('changes')->delete();
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
    same([410, 'The draft no longer exists on the page.'], [$code, $j['error']], 'Changes gone');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);

    // The real thing.
    [, $json] = $create();
    $id = $json['draft']['id'];
    $token = $json['token'];
    [$code, $j] = fude_call($app, 'publish', ['id' => $id, 'token' => $token, 'actor' => 'telegram:42']);
    same([200, $id, 'http://example.invalid/standort'], [$code, $j['id'], $j['url']], 'Published', $j);
    same(64, strlen($j['hash']), 'New hash');
    $page = $app()->page('standort');
    same(3, $page->team()->toStructure()->count(), 'Three rows live');
    same(['Anna Neu', 'MFA'], [$page->team()->toStructure()->last()->name()->value(), $page->team()->toStructure()->last()->rolle()->value()], 'The new row');
    same('Standort', $page->kurzname()->value(), 'Other fields intact');
    same('Unser Haus in Musterstadt.', $page->einleitung()->value(), 'Other fields intact (2)');
    same(false, $page->version('changes')->exists(), 'Changes version gone');
    same(false, is_file($tmp . '/storage/fude/drafts/' . $id . '.json'), 'Record gone');
    same(404, fude_call($app, 'publish', ['id' => $id, 'token' => $token])[0], 'Second publish: 404');
    same(true, str_contains(file_get_contents($tmp . '/content/standort/standort.txt'), 'Anna Neu'), 'In the content file');
    $log = array_values(array_filter(fude_log($tmp), fn ($l) => $l['route'] === 'publish' && $l['status'] === 200));
    $last = end($log);
    $line = array_intersect_key($last, array_flip(['route', 'user', 'actor', 'collection', 'action', 'key', 'draft', 'status']));
    ksort($line);
    same(['action' => 'create', 'actor' => 'telegram:42', 'collection' => 'team', 'draft' => $id, 'key' => 'Anna Neu', 'route' => 'publish', 'status' => 200, 'user' => FUDE_BOT], $line, 'Publish log line');
} catch (Throwable $e) {
    fwrite(STDERR, 'Publish: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Publish: $n checks\n";
exit($failed ? 1 : 0);
