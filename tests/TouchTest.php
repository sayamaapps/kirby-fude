<?php
/**
 * Collection option `touch`: every draft of the collection also sets a page
 * field to today's date (Y-m-d). Only `value: today` is accepted; the field
 * must exist on the page with a date or text type. Other collections leave
 * the field alone.
 */
require __DIR__ . '/support.php';
[$tmp, $app] = fude_fixture();
$failed = false;
try {
    $options = fude_declare($tmp, 'touch', <<<'PHP'
    ['collections' => [
        'staff' => [
            'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
            'actions' => ['create', 'update', 'delete'],
            'touch' => ['field' => 'datum', 'value' => 'today'],
            'fields' => ['name' => ['type' => 'text', 'required' => true], 'rolle' => ['type' => 'text']],
        ],
        'plain' => [
            'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name',
            'fields' => ['name' => ['type' => 'text', 'required' => true]],
        ],
    ]]
    PHP);

    // The published date is an old one, so a touch is visible.
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page('standort')->update(['datum' => '2020-01-01']);
    $datum = fn (string $version) => ($app()->page('standort')->version($version)->read() ?? [])['datum'] ?? null;

    [, $schema] = fude_call($app, 'schema', options: $options);
    same(['datum', null], [$schema['collections']['staff']['touch'] ?? null, $schema['collections']['plain']['touch'] ?? null], 'Schema names the touched field, only where declared');

    // Without the option nothing is touched.
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'plain', 'fields' => ['name' => 'Anna Neu']], options: $options);
    same(200, $code, 'Draft of a plain collection', $json);
    same(['2020-01-01', '2020-01-01'], [$datum('latest'), $datum('changes')], 'Plain collection: date untouched');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

    // Create: the date in the changes version is today; published stays until publish.
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'fields' => ['name' => 'Anna Neu', 'rolle' => 'MFA']], options: $options);
    same(200, $code, 'Create with touch', $json);
    same(['2020-01-01', '2026-10-09'], [$datum('latest'), $datum('changes')], 'Changes carry today, published date stays');
    same(3, count(Kirby\Data\Data::decode($app()->page('standort')->version('changes')->read()['team'], 'yaml')), 'Rows still complete');
    [$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same([200, '2026-10-09'], [$code, $datum('latest')], 'Published with the draft', $j);

    // Update and delete touch as well (the date is set back first to see it).
    $kirby = $app();
    $kirby->impersonate('kirby');
    $kirby->page('standort')->update(['datum' => '2020-01-01']);
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'update', 'key' => 'Anna Neu', 'fields' => ['rolle' => 'Praxismanagerin']], options: $options);
    same('2026-10-09', $datum('changes'), 'Update touches the date');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    same('2020-01-01', $datum('latest'), 'Discard keeps the published date');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Anna Neu'], options: $options);
    same('2026-10-09', $datum('changes'), 'Delete touches the date');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);

    // "Today" is in fude.timezone (default Europe/Berlin), not PHP's default:
    // a hoster on UTC must not stamp yesterday at 00:30 in Berlin. The test
    // clock is 09:00 in Berlin, still the day before in Honolulu.
    $previous = date_default_timezone_get();
    date_default_timezone_set('Pacific/Honolulu');
    [, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Anna Neu'], options: $options);
    $default = $datum('changes');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
    date_default_timezone_set($previous);
    same('2026-10-09', $default, 'Default zone Europe/Berlin, whatever PHP\'s default is');

    $honolulu = $options + ['fude.timezone' => 'Pacific/Honolulu'];
    [, $json] = fude_call($app, 'draft', ['collection' => 'staff', 'action' => 'delete', 'key' => 'Anna Neu'], options: $honolulu);
    same('2026-10-08', $datum('changes'), 'Date in the zone of fude.timezone');
    fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $honolulu);

    [$code, $json] = fude_call($app, 'schema', options: $options + ['fude.timezone' => 'Europe/Bad_Nordstadt']);
    same([500, 'The option fude.timezone is not a time zone: Europe/Bad_Nordstadt'], [$code, $json['error'] ?? null], 'Unknown zone is loud on every route', $json);

    // Declaration mistakes are loud.
    $bad = function (string $php, string $name) use ($tmp, $app): array {
        $response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
        return [$response[0], $response[1]['error'] ?? null];
    };
    $staff = fn (string $touch) => "['collections' => ['staff' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'touch' => $touch, 'fields' => ['name' => 'text']]]]";
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": touch value must be "today"'],
        $bad($staff("['field' => 'datum', 'value' => 'yesterday']"), 't1'), 'Only today');
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": touch needs a "field" and the value "today"'],
        $bad($staff("['value' => 'today']"), 't2'), 'Field missing');
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": touch field "gibtsnicht" is not in the blueprint of "standort"'],
        $bad($staff("['field' => 'gibtsnicht', 'value' => 'today']"), 't3'), 'Field not in the blueprint');
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": touch field "einleitung" must be a date or text field'],
        $bad($staff("['field' => 'einleitung', 'value' => 'today']"), 't4'), 'Field of the wrong type');
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "staff": touch field "team" is the structure field itself'],
        $bad($staff("['field' => 'team', 'value' => 'today']"), 't5'), 'Field is the structure field');
    same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "notiz": touch is only for structure and page collections'],
        $bad("['collections' => ['notiz' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'touch' => ['field' => 'datum', 'value' => 'today'], 'fields' => ['title' => 'text']]]]", 't6'), 'Touch on pages');
    same([200, null], $bad($staff("['field' => 'datum', 'value' => 'today']"), 't7'), 'Valid touch');
} catch (Throwable $e) {
    fwrite(STDERR, 'Touch: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Touch: $n checks\n";
exit($failed ? 1 : 0);
