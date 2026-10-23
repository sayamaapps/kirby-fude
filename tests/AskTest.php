<?php
/**
 * Asked fields and conditional required fields. `ask: true` in fude.php or
 * `fudeAsk: true` at a blueprint field: the schema says `ask: true`, the bot
 * asks before the first draft and takes "don't know". A blueprint `when`
 * makes a field required (or asked) only while it is shown: on a declared
 * field the schema says `requiredWhen`/`askWhen`, on an undeclared field the
 * blueprint default decides. Kirby skips its check of a hidden field, so an
 * in-person event without a link is fine and an online one is refused. The
 * page "termine" lives in this file only.
 */
require __DIR__ . '/support.php';

const ASK_TERMIN = <<<'YML'
    title: Termin
    fields:
      startdate:
        label: Beginn
        type: date
        required: true
      ganztags:
        label: Ganztägig
        type: toggle
        default: false
      starttime:
        label: Uhrzeit
        type: time
        when:
          ganztags: false
        required: true
      endtime:
        label: Ende
        type: time
        when:
          ganztags: false
        fudeAsk: true
      vorort:
        label: Vor Ort
        type: toggle
        default: true
      online:
        label: Online
        type: toggle
      link:
        label: Link
        type: url
        when:
          online: true
        required: true
      plattform:
        label: Plattform
        type: text
        when:
          online: true
        fudeAsk: true
      raum:
        label: Raum
        type: text
        when:
          vorort: false
        required: true
      kostenlos:
        label: Kostenlos
        type: toggle
        default: false
      preis:
        label: Preis
        type: number
        when:
          kostenlos: false
        fudeAsk: true
      anmeldung:
        label: Anmeldung
        type: url
      notiz:
        label: Notiz
        type: text
        required: true
        fudeAsk: true
      kaputt:
        label: Kaputt
        type: text
        fudeAsk: ja
      zeiten:
        label: Zeiten
        type: structure
        fude: {key: tag}
        fields:
          tag:
            label: Tag
            type: text
          hinweis:
            label: Hinweis
            type: text
            fudeAsk: true
    YML;

[$tmp, $app] = fude_fixture();
$failed = false;
try {
    mkdir($tmp . '/content/termine', 0700, true);
    file_put_contents($tmp . '/content/termine/termine.txt', "Title: Termine\n");
    file_put_contents($tmp . '/site/blueprints/pages/termin.yml', ASK_TERMIN);
    $declaration = <<<'PHP'
    ['collections' => [
        'termine' => ['kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title',
            'fields' => ['title' => 'text', 'startdate' => 'date', 'starttime' => 'time', 'endtime' => 'time',
                'online' => 'toggle', 'link' => 'text', 'plattform' => 'text', 'raum' => 'text', 'preis' => 'number',
                'anmeldung' => ['type' => 'text', 'ask' => true], 'notiz' => 'text']],
    ]]
    PHP;
    $opt = fude_declare($tmp, 'ask', $declaration) + ['fude.blueprints' => false];

    [$code, $json] = fude_call($app, 'schema', options: $opt);
    same(200, $code, 'Schema');
    $f = $json['collections']['termine']['fields'] ?? [];
    same([true, null, null], [$f['starttime']['required'] ?? null, $f['starttime']['requiredWhen'] ?? null, $f['starttime']['ask'] ?? null],
        'when on an undeclared toggle whose default holds: required as before');
    same([false, ['online' => 'true']], [$f['link']['required'] ?? null, $f['link']['requiredWhen'] ?? null],
        'when on a declared toggle: requiredWhen with the stored value');
    same([false, null], [$f['raum']['required'] ?? null, $f['raum']['requiredWhen'] ?? null],
        'when on an undeclared toggle whose default fails: never required');
    same([true, null], [$f['endtime']['ask'] ?? null, $f['endtime']['askWhen'] ?? null], 'fudeAsk, condition holds by default: asked');
    same([true, ['online' => 'true']], [$f['plattform']['ask'] ?? null, $f['plattform']['askWhen'] ?? null], 'fudeAsk on a declared condition: askWhen');
    same(true, $f['preis']['ask'] ?? null, 'fudeAsk on a number, the free toggle off by default: asked');
    same(true, $f['anmeldung']['ask'] ?? null, 'ask: true in fude.php');
    same([true, null], [$f['notiz']['required'] ?? null, $f['notiz']['ask'] ?? null], 'Required needs no asking');
    same(false, array_key_exists('ask', $f['title'] ?? []), 'Not asked: no ask key, the schema reads as before');

    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Herbstkurs', 'startdate' => '2030-05-05', 'starttime' => '10:00', 'notiz' => 'x']], options: $opt);
    same(200, $code, 'In person without a link: Kirby skips the hidden field');
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Webinar', 'startdate' => '2030-05-06', 'starttime' => '18:00', 'notiz' => 'x', 'online' => true]], options: $opt);
    same(422, $code, 'Online without a link: Kirby refuses');
    ok(str_contains($json['error'] ?? '', 'Link'), 'Kirby names the field', $json['error'] ?? null);
    [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'fields' => ['title' => 'Webinar', 'startdate' => '2030-05-06', 'starttime' => '18:00', 'notiz' => 'x', 'online' => true, 'link' => 'https://example.com/raum']], options: $opt);
    same(200, $code, 'Online with a link');

    $bad = fude_declare($tmp, 'askbad', "['collections' => ['t' => ['kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title', 'fields' => ['title' => ['type' => 'text', 'ask' => 'ja']]]]]") + ['fude.blueprints' => false];
    [$code, $json] = fude_call($app, 'schema', options: $bad);
    ok($code === 500 && str_contains($json['error'] ?? '', 'ask must be true or false'), 'ask in fude.php must be a boolean', $json['error'] ?? null);
    $bad = fude_declare($tmp, 'askbp', "['collections' => ['t' => ['kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title', 'fields' => ['title' => 'text', 'kaputt' => 'text']]]]") + ['fude.blueprints' => false];
    [$code, $json] = fude_call($app, 'schema', options: $bad);
    ok($code === 500 && str_contains($json['error'] ?? '', 'fudeAsk must be true or false'), 'fudeAsk must be a boolean', $json['error'] ?? null);

    // A marked structure: the sub-field's fudeAsk reaches the schema, and it
    // does not make the event page a collection of its own.
    mkdir($tmp . '/content/termine/1_sommerkurs', 0700, true);
    file_put_contents($tmp . '/content/termine/1_sommerkurs/termin.txt', "Title: Sommerkurs\n\n----\n\nZeiten:\n\n- tag: Montag\n");
    $page = 'termine/sommerkurs';
    $none = fude_declare($tmp, 'asknone', "['collections' => []]");
    [$code, $json] = fude_call($app, 'schema', options: $none);
    same(200, $code, 'Schema from markers');
    $name = str_replace('/', '-', $page) . '-zeiten';
    same(true, $json['collections'][$name]['fields']['hinweis']['ask'] ?? null, 'fudeAsk in a marked structure');
    same([$name], array_values(array_filter(array_keys($json['collections'] ?? []), fn ($c) => str_starts_with($c, 'termine-'))), 'fudeAsk alone is no marker: no page collection');
} catch (Throwable $e) {
    fwrite(STDERR, 'Ask: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
echo "Ask: $n checks\n";
exit($failed ? 1 : 0);
