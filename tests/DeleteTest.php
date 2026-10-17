<?php
/**
 * Action "delete" on both collection kinds. A structure row leaves the
 * changes version (published rows stay until publish); a page is marked and
 * only deleted by publish. Delete takes no fields and no image, needs
 * "delete" in the collection's actions, and an unknown key is a 404.
 */
require __DIR__ . '/support.php';
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    $options = fude_declare($tmp, 'delete', <<<'PHP'
    ['collections' => [
        'staff' => [
            'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
            'actions' => ['create', 'update', 'delete'],
            'fields' => ['name' => ['type' => 'text', 'required' => true], 'rolle' => ['type' => 'text']],
        ],
        'notiz' => [
            'kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => 'title',
            'actions' => ['create', 'update', 'delete'],
            'fields' => ['title' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
        ],
    ]]
    PHP);

    // Structure: the row is removed in the changes version only.
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Veronika Beispiel'], options: $options);
    same(200, $code, 'Delete draft on a structure row', $json);
    same(['structure', 'delete', 'standort', 'Veronika Beispiel', 1, null], [$json['draft']['kind'], $json['draft']['action'], $json['draft']['page'], $json['draft']['key'], $json['draft']['index'], $json['draft']['file']], 'Record');
    same(2, $app()->page('standort')->team()->toStructure()->count(), 'Published rows untouched');
    $changes = $app()->page('standort')->version('changes')->read();
    same(['Jörg Muster'], array_column(Kirby\Data\Data::decode($changes['team'], 'yaml'), 'name'), 'Changes version: the row is gone, the other stays');
    same('Standort', $changes['kurzname'], 'Changes version carries the other fields');
    same(true, str_contains($json['preview'], '_version=changes') && str_contains($json['preview'], '_token='), 'Preview of the changes');

    [$code, $j] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Niemand'], options: $options);
    same(404, $code, 'Unknown key: 404 like update');
    [$code, $j] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Jörg Muster', 'fields' => ['rolle' => 'Chef']], options: $options);
    same([422, 'Action "delete" takes only the key: no fields, no image.'], [$code, $j['error']], 'Delete takes no fields');
    [$code, $j] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete'], options: $options);
    same(422, $code, 'Delete without a key');
    [$code, $j] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'delete', 'key' => 'Veronika Beispiel']);
    same([422, 'Collection "team" does not allow the action "delete".'], [$code, $j['error']], 'Only with delete in actions');

    same(['create', 'update', 'delete'], fude_call($app, 'schema', options: $options)[1]['collections']['staff']['actions'], 'Schema lists delete');

    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same(200, $code, 'Publish the structure delete', $j);
    $page = $app()->page('standort');
    same([1, 'Jörg Muster', 'Standort', 'Unser Haus in Musterstadt.', false], [$page->team()->toStructure()->count(), $page->team()->toStructure()->first()->name()->value(), $page->kurzname()->value(), $page->einleitung()->value(), $page->version('changes')->exists()], 'Row gone, rest intact');

    // Discard leaves the published rows alone.
    [, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Jörg Muster'], options: $options);
    same([200, false], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('standort')->version('changes')->exists()], 'Discard of a delete');
    same(1, $app()->page('standort')->team()->toStructure()->count(), 'Discard changes nothing published');

    // Pages: the page stays until publish.
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'fields' => ['title' => 'Alte Meldung', 'text' => 'Weg damit']], options: $options);
    same(200, $code, 'Create a note to delete later', $json);
    $child = $json['draft']['page'];
    [, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same(true, $app()->page($child) !== null, 'Note is live');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => 'Alte Meldung'], options: $options);
    same(200, $code, 'Delete draft on a page', $json);
    same(['pages', 'delete', $child, 0, 'Alte Meldung'],[$json['draft']['kind'], $json['draft']['action'], $json['draft']['page'], $json['draft']['index'], $json['draft']['key']], 'Record');
    same('http://example.invalid/' . $child, $json['preview'], 'Preview: the page as it is now (published, so no token)');
    same(true, $app()->page($child) !== null && !$app()->page($child)->version('changes')->exists(), 'Not deleted before publish, no changes version either');
    same(1, $app()->page('aktuelles')->children()->listed()->count(), 'Still listed');

    [$code, $discard] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same([200, false], [$code, $discard['kept']], 'Discard of a page delete');
    same(true, $app()->page($child) !== null, 'Discard keeps the page');

    [, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => 'Alte Meldung'], options: $options);
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page($child)->version('changes')->save(['title' => 'Alte Meldung', 'text' => 'Panel-Entwurf']);
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same([409, 'The draft was changed in the Panel meanwhile. Publish it there, or discard it and start again.'], [$code, $j['error']], 'A Panel changes version on the page blocks the delete');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page($child)->version('changes')->delete();

    // The draft is anchored on the page's UUID, not only its path: a page
    // deleted and created again in the same place, with the same content, is
    // not the page that was confirmed.
    [, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => 'Alte Meldung'], options: $options);
    same(true, ($json['draft']['uuid'] ?? '') !== '' && $json['draft']['uuid'] === $app()->page($child)->version('latest')->read()['uuid'], 'Record carries the stored UUID', $json['draft']);
    $kirby = $app();
    $kirby->impersonate('kirby');
    $old = $kirby->page($child);
    $content = $old->version('latest')->read();
    unset($content['uuid']);
    $slug = $old->slug();
    $old->delete();
    Kirby\Cms\Page::create(['parent' => $kirby->page('aktuelles'), 'slug' => $slug, 'template' => 'note', 'content' => $content])->changeStatus('listed');
    same(true, $app()->page($child) !== null && $app()->page($child)->uuid()->id() !== $json['draft']['uuid'], 'Same path, new page');
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same(410, $code, 'Publish refuses the page in the old one\'s place', $j);
    same(true, $app()->page($child) !== null, 'New page not deleted');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

    // Publish deletes the page, and only then.
    [, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => 'Alte Meldung'], options: $options);
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token'], 'actor' => 'telegram:42'], options: $options);
    same([200, 'http://example.invalid/aktuelles'], [$code, $j['url']], 'Published: the parent is the reply URL', $j);
    same(64, strlen($j['hash']), 'New hash');
    same(null, $app()->page($child), 'Page deleted');
    same(0, $app()->page('aktuelles')->children()->count(), 'No child left');
    same(404, fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], 'Second publish: 404');
    same(false, is_file($tmp . '/storage/fude/drafts/' . $json['draft']['id'] . '.json'), 'Record gone');

    // A page with children is not deleted by the bot.
    [, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'fields' => ['title' => 'Mit Kind', 'text' => 'Oben']], options: $options);
    [, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    $parent = $json['draft']['page'];
    $kirby = $app();
    $kirby->impersonate('kirby');
    Kirby\Cms\Page::create(['parent' => $kirby->page($parent), 'slug' => 'unterseite', 'template' => 'note', 'content' => ['title' => 'Unterseite', 'text' => 'x'], 'isDraft' => false]);
    [, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => 'Mit Kind'], options: $options);
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same([409, 'The page "Mit Kind" has child pages and cannot be deleted here.'], [$code, $j['error']], 'Child pages block the delete');
    same(true, $app()->page($parent) !== null, 'Page with children still there');

    $log = fude_log($tmp);
    $last = end($log);
    same(['publish', 'delete', 409], [$last['route'], $last['action'], $last['status']], 'Log line of the last (refused) publish');
} catch (Throwable $e) {
    fwrite(STDERR, 'Delete: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Delete: $n checks\n";
exit($failed ? 1 : 0);
