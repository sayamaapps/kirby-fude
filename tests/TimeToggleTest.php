<?php

use Fude\Config;
use Fude\FudeError;
use Fude\Validator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Field types time and toggle. Stored as Kirby's own fields save them: a
 * time as H:i:s ("08:30:00"), a toggle as "true" or "false". Input: 24 h
 * times with an optional seconds part, and booleans (JSON true/false) or
 * the strings "true"/"false" in any case. Empty means not set, unless required.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class TimeToggleTest extends TestCase
{
    public function testBehaviour(): void
    {
        try {
            [$tmp, $app] = fude_fixture();
            file_put_contents($tmp . '/site/blueprints/pages/standort.yml', <<<'YML'
            title: Standort
            fields:
              kurzname:
                label: Kurzname
                type: text
                required: true
              beginn:
                label: Beginn
                type: time
              offen:
                label: Offen
                type: toggle
              termine:
                label: Termine
                type: structure
                fields:
                  name:
                    label: Name
                    type: text
                    required: true
                  zeit:
                    label: Zeit
                    type: time
                  aktiv:
                    label: Aktiv
                    type: toggle
            YML);
            $options = fude_declare($tmp, 'time-toggle', <<<'PHP'
            ['collections' => [
                'zeiten' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['beginn' => ['type' => 'time'], 'offen' => ['type' => 'toggle']]],
                'termine' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'termine', 'key' => 'name', 'actions' => ['create', 'update', 'delete'],
                    'fields' => ['name' => 'text', 'zeit' => 'time', 'aktiv' => 'toggle']],
            ]]
            PHP);
            $c = Config::load($app(options: $options));
            same(['time', 'toggle'], [$c['zeiten']['fields']['beginn']['type'], $c['zeiten']['fields']['offen']['type']], 'Page collection takes both types');
            same(['time', 'toggle'], [$c['termine']['fields']['zeit']['type'], $c['termine']['fields']['aktiv']['type']], 'Structure takes both types');
            $fields = (array) Config::schema($app(options: $options), $c)['zeiten']['fields'];
        same([['type' => 'time', 'label' => 'Beginn', 'required' => false, 'description' => ''], ['type' => 'toggle', 'label' => 'Offen', 'required' => false, 'description' => '']],
            [(array) ($fields['beginn'] ?? []), (array) ($fields['offen'] ?? [])], 'Schema exports the types');

            // Time: accepted forms and the stored H:i:s.
            $time = fn (mixed $value, bool $required = false) => Validator::check(['fields' => ['zeit' => ['type' => 'time', 'required' => $required, 'options' => []]]], ['zeit' => $value], 'update')['zeit'];
            $refused = fn (mixed $value) => throws(fn () => $time($value), 'Field "zeit" must be a time in the form HH:MM (24 h), e.g. 08:30.', 'Refused: ' . var_export($value, true), FudeError::class);
            same('08:30:00', $time('08:30'), 'HH:MM stored as H:i:s');
            same('08:30:00', $time('8:30'), 'H:MM');
            same('09:05:07', $time(' 9:05:07 '), 'Optional seconds, trimmed');
            same('00:00:00', $time('0:00'), 'Midnight');
            same('23:59:59', $time('23:59:59'), 'Last second of the day');
            same('', $time(''), 'Empty means not set');
            same('', $time(null), 'Null means not set');
            $refused('24:00');
            $refused('8.30');
            $refused('08:60');
            $refused('8:30pm');
            $refused('9:5');
            $refused('12:00:60');
            $refused('8:30 Uhr');
            throws(fn () => $time('', true), 'Field "zeit" is required.', 'Required time refuses empty', FudeError::class);

            // Toggle: JSON booleans and the strings "true"/"false" in any case.
            $toggle = fn (mixed $value) => Validator::check(['fields' => ['aktiv' => ['type' => 'toggle', 'required' => false, 'options' => []]]], ['aktiv' => $value], 'update')['aktiv'];
            $toggleRefused = fn (mixed $value) => throws(fn () => $toggle($value), 'Field "aktiv" must be true or false.', 'Refused: ' . var_export($value, true), FudeError::class);
            same(['true', 'false'], [$toggle(true), $toggle(false)], 'JSON booleans');
            same(['true', 'false', 'true'], [$toggle('true'), $toggle('False'), $toggle('TRUE')], 'Strings in any case');
            same('', $toggle(''), 'Empty means not set');
            $toggleRefused('1');
            $toggleRefused(1);
            $toggleRefused('yes');
            $toggleRefused('on');
            // Only toggle takes booleans: a text field still refuses them.
            throws(fn () => Validator::check(['fields' => ['t' => ['type' => 'text', 'required' => false, 'options' => []]]], ['t' => true], 'update'),
                'Field "t" must be a text.', 'A boolean for a text field', FudeError::class);

            // Published: the stored values are Kirby's own, and the schema shows them.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'zeiten', 'action' => 'update', 'fields' => ['beginn' => '8:30', 'offen' => true]], options: $options);
            same(200, $code, 'Draft with a time and a toggle', $json);
            same(['08:30:00', 'true'], [$json['draft']['fields']['beginn'], $json['draft']['fields']['offen']], 'Draft shows the stored values');
            [$code, $pub] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            same(200, $code, 'Publish', $pub);
            $latest = $app()->page('standort')->version('latest')->read();
            same(['08:30:00', 'true'], [$latest['beginn'], $latest['offen']], 'Published as Kirby stores them', $latest);
            // Kirby's own form saves the same strings for the same input.
            $form = Kirby\Form\Form::for($app()->page('standort'), ['values' => ['beginn' => '8:30', 'offen' => true]]);
            same(['08:30:00', 'true'], [$form->data()['beginn'], $form->data()['offen']], 'Kirby form save agrees');
            same(['08:30:00', 'true'], [fude_call($app, 'schema', options: $options)[1]['collections']['zeiten']['entries'][0]['beginn'], fude_call($app, 'schema', options: $options)[1]['collections']['zeiten']['entries'][0]['offen']], 'Schema entry shows the stored values');

            // Structure rows: a time and a toggle, created, updated by name, published.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'action' => 'create', 'fields' => ['name' => 'Sprechstunde', 'zeit' => '14:00', 'aktiv' => false]], options: $options);
            same(200, $code, 'Row draft', $json);
            fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'termine', 'action' => 'update', 'key' => 'Sprechstunde', 'fields' => ['aktiv' => 'TRUE']], options: $options);
            same(200, $code, 'Row update', $json);
            fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            $rows = Fude\Content::rows($app(), $c['termine']);
            same([['name' => 'Sprechstunde', 'zeit' => '14:00:00', 'aktiv' => 'true']],
                array_map(fn ($r) => ['name' => $r['name'], 'zeit' => $r['zeit'], 'aktiv' => $r['aktiv']], $rows), 'Row published with the stored values');

            // Refused drafts write nothing.
            [$code, $json] = fude_call($app, 'draft', ['collection' => 'zeiten', 'action' => 'update', 'fields' => ['beginn' => '25:00']], options: $options);
            same([422, 'Field "beginn" must be a time in the form HH:MM (24 h), e.g. 08:30.'], [$code, $json['error']], 'Refused time over HTTP');
        } finally {
            cleanup($tmp);
        }
        global $n;
    }
}
