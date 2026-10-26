<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Two guards on what a draft may do to an entry. An update may not give an
 * entry the key of another one, at draft time and again at publish (a pages
 * collection's hash covers only the changed page). And a value for a field
 * the blueprint hides on this entry (`when`) is refused: it would change
 * nothing anyone sees, like a price on a free event.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class KeyShownTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        try {
            foreach (['termine', 'kurse'] as $dir) {
                mkdir($tmp . '/content/' . $dir, 0700, true);
            }
            file_put_contents($tmp . '/content/termine/termine.txt', "Title: Termine\n");
            file_put_contents($tmp . '/content/kurse/kurse.txt', "Title: Kurse\n\n----\n\nKurse:\n\n- name: Frei\n  isfree: \"true\"\n- name: Bezahlt\n  isfree: \"false\"\n  preis: 20\n");
            file_put_contents($tmp . '/site/blueprints/pages/termine.yml', "title: Termine\nsections:\n  termine:\n    type: pages\n    templates:\n      - termin\n");
            file_put_contents($tmp . '/site/blueprints/pages/termin.yml', <<<YML
            title: Termin
            fields:
              isFree:
                label: Kostenlos
                type: toggle
                default: false
              price:
                label: Preis
                type: number
                min: 0
                when:
                  isFree: false
              online:
                label: Online
                type: toggle
              onlineUrl:
                label: Link
                type: url
                when:
                  online: true
            YML);
            file_put_contents($tmp . '/site/blueprints/pages/kurse.yml', <<<YML
            title: Kurse
            fields:
              kurse:
                type: structure
                fields:
                  name:
                    label: Name
                    type: text
                  isFree:
                    label: Kostenlos
                    type: toggle
                  preis:
                    label: Preis
                    type: number
                    when:
                      isFree: false
            YML);
            $options = fude_declare($tmp, 'keyshown', <<<'PHP'
            ['collections' => [
                'termine' => [
                    'kind' => 'pages', 'parent' => 'termine', 'template' => 'termin', 'key' => 'title',
                    'fields' => ['title' => ['type' => 'text', 'required' => true], 'price' => ['type' => 'number'], 'online' => ['type' => 'toggle'], 'onlineurl' => ['type' => 'text']],
                ],
                'kurse' => [
                    'kind' => 'structure', 'page' => 'kurse', 'field' => 'kurse', 'key' => 'name',
                    'fields' => ['name' => ['type' => 'text', 'required' => true], 'preis' => ['type' => 'number']],
                ],
            ]]
            PHP);
            $draft = fn (array $body) => fude_call($app, 'draft', $body, options: $options);
            $publish = fn (array $json) => fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            $discard = fn (array $json) => fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
            $create = function (string $title, array $fields = []) use ($draft, $publish): string {
                [$code, $json] = $draft(['collection' => 'termine', 'fields' => ['title' => $title] + $fields]);
                same(200, $code, "Create $title", $json);
                same(200, $publish($json)[0], "Publish $title");
                return $json['draft']['page'];
            };

            // --- Keys --------------------------------------------------------------

            $eins = $create('Eins');
            $zwei = $create('Zwei');
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Zwei', 'fields' => ['title' => ' eins ']]);
            same([409, 'Another entry already has Title "eins". Two entries could no longer be told apart.'], [$code, $j['error'] ?? null], 'Update to a key another entry has: 409');
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Zwei', 'fields' => ['title' => 'ZWEI']]);
            same(200, $code, 'Update keeping its own key (other case): fine', $j);
            $discard($j);

            // Publish checks again: the new key may have been taken since the draft.
            [, $update] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Zwei', 'fields' => ['title' => 'Drei']]);
            $create('Drei');
            [$code, $j] = $publish($update);
            same(409, $code, 'Publish of an update whose new key was taken meanwhile', $j);
            same('Zwei', $app()->page($zwei)->title()->value(), 'Page unchanged');
            $discard($update);

            // A pending create whose key an update took meanwhile.
            [, $pending] = $draft(['collection' => 'termine', 'fields' => ['title' => 'Vier']]);
            [, $update] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Eins', 'fields' => ['title' => 'Vier']]);
            same(200, $publish($update)[0], 'Update to a key only a pending create has');
            [$code, $j] = $publish($pending);
            same(409, $code, 'Publish of the create: the key is taken now', $j);
            same(true, $app()->page($pending['draft']['page'])->isDraft(), 'Created page stays a draft');
            $discard($pending);

            // Structure rows: the draft refuses, publish is covered by the field hash.
            [$code, $j] = $draft(['collection' => 'kurse', 'action' => 'update', 'key' => 'Bezahlt', 'fields' => ['name' => 'Frei']]);
            same(409, $code, 'Structure: update to a taken key', $j);

            // --- Fields the blueprint hides ----------------------------------------

            // A new entry starts from the blueprint defaults: not free, so a price counts.
            $kostet = $create('Kostet', ['price' => '30']);
            same('30', $app()->page($kostet)->price()->value(), 'Price on a new event');

            // A free event (set in the Panel): a price would show nowhere.
            $frei = $create('Gratis');
            $kirby = $app();
            $kirby->impersonate('kirby');
            $kirby->page($frei)->update(['isFree' => true]);
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Gratis', 'fields' => ['price' => '45']]);
            same([422, 'Field "Preis" has no effect on this entry: it applies only when "Kostenlos" is false. Change "Kostenlos" in the Panel.'], [$code, $j['error'] ?? null], 'Price on a free event: 422', $j);
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Gratis', 'fields' => ['price' => '']]);
            same(200, $code, 'Emptying the hidden field passes', $j);
            $discard($j);
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Kostet', 'fields' => ['price' => '35']]);
            same(200, $code, 'Price on a paid event', $j);
            $discard($j);

            // A condition on a declared field: it can come along in the same draft.
            [$code, $j] = $draft(['collection' => 'termine', 'fields' => ['title' => 'Vor Ort', 'onlineurl' => 'https://example.com/raum']]);
            same([422, 'Field "Link" applies only when "Online" is true. Send "Online" with it, or leave the field out.'], [$code, $j['error'] ?? null], 'Link without online: 422', $j);
            [$code, $j] = $draft(['collection' => 'termine', 'fields' => ['title' => 'Im Netz', 'online' => true, 'onlineurl' => 'https://example.com/raum']]);
            same(200, $code, 'Link with online', $j);
            same(200, $publish($j)[0], 'Published');
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Im Netz', 'fields' => ['onlineurl' => 'https://example.com/neu']]);
            same(200, $code, 'Update of the link: the stored online counts', $j);
            $discard($j);
            [$code, $j] = $draft(['collection' => 'termine', 'action' => 'update', 'key' => 'Im Netz', 'fields' => ['online' => false, 'onlineurl' => 'https://example.com/neu']]);
            same(422, $code, 'Switching online off with a new link: 422', $j);

            // Structure rows: the row's own toggle decides.
            [$code, $j] = $draft(['collection' => 'kurse', 'action' => 'update', 'key' => 'Frei', 'fields' => ['preis' => '10']]);
            same(422, $code, 'Structure: price on a free row', $j);
            [$code, $j] = $draft(['collection' => 'kurse', 'action' => 'update', 'key' => 'Bezahlt', 'fields' => ['preis' => '25']]);
            same(200, $code, 'Structure: price on a paid row', $j);
            $discard($j);
            [$code, $j] = $draft(['collection' => 'kurse', 'fields' => ['name' => 'Neu', 'preis' => '15']]);
            same(200, $code, 'Structure: a new row is not free (toggle without default)', $j);
            $discard($j);
        } finally {
            cleanup($tmp);
        }
    }
}
