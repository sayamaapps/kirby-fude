<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A pages collection: create makes a draft page (invisible, previewable
 * with its token), publish lists it; update writes a changes version of the
 * child; discard deletes the draft page or the changes; hash conflict when
 * the child was published in the Panel meanwhile.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class PagesTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Urlaub im August', 'text' => 'Vom 5. bis 15.', 'kategorie' => 'urlaub']], files: ['image' => fude_upload($tmp, 1800, 1200, 'jpg')]);
			same(200, $code, 'Draft page created', $json);
			$draft = $json['draft'];
			same(['pages', 'create', 'Urlaub im August'], [$draft['kind'], $draft['action'], $draft['key']], 'Record');
			same(true, str_starts_with($draft['page'], 'aktuelles/urlaub-im-august-'), 'Child under the parent', $draft['page']);
			$child = $app()->page($draft['page']);
			same([true, 'note', 'Vom 5. bis 15.', 'urlaub'], [$child->isDraft(), $child->intendedTemplate()->name(), $child->text()->value(), $child->kategorie()->value()], 'Draft page with content');
			same([1600, 'bild', 'Urlaub im August'], [$child->file($draft['file'])->width(), $child->file($draft['file'])->template(), $child->file($draft['file'])->alt()->value()], 'Image resized to the default 1600, template and alt');
			same(true, str_contains($json['preview'], '_token=') && !str_contains($json['preview'], '_version=changes'), 'Preview of a draft page: token, latest version', $json['preview']);
			same(0, $app()->page('aktuelles')->children()->count(), 'Not visible as a child');
			same([], fude_call($app, 'schema')[1]['collections']['news']['entries'], 'Not in the schema entries');
			parse_str((string)parse_url($json['preview'], PHP_URL_QUERY), $query);
			$html = $app(['method' => 'GET', 'url' => 'http://example.invalid/' . $draft['page'], 'query' => $query])->page($draft['page'])->render();
			same('NOTE:Urlaub im August|Vom 5. bis 15.|urlaub|' . $draft['file'], $html, 'Preview renders');

			// Validation against the page blueprint (select options from the blueprint list form).
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'X', 'kategorie' => 'party']]);
			same([422, 'Field "kategorie" must be one of: praxis, urlaub.'], [$code, $j['error']], 'Select options');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Nur Titel']]);
			same(422, $code, 'Blueprint: text is required for a note');
			same(0, count($app()->page('aktuelles')->drafts()->filterBy('title', 'Nur Titel')), 'Refused page not left behind');

			// Publish.
			[$code, $j] = fude_call($app, 'publish', ['id' => $draft['id'], 'token' => $json['token']]);
			same([200, 'http://example.invalid/' . $draft['page']], [$code, $j['url']], 'Published', $j);
			$child = $app()->page($draft['page']);
			same(['listed', 1], [$child->status(), $app()->page('aktuelles')->children()->listed()->count()], 'Listed now');
			same([['title' => 'Urlaub im August', 'text' => 'Vom 5. bis 15.', 'kategorie' => 'urlaub', 'bild' => true]], fude_call($app, 'schema')[1]['collections']['news']['entries'], 'Schema entry');
			same(404, fude_call($app, 'publish', ['id' => $draft['id'], 'token' => $json['token']])[0], 'Second publish 404');

			// Update: changes version of the child.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'urlaub im august', 'fields' => ['text' => 'Vom 5. bis 20.']]);
			same(200, $code, 'Update draft', $json);
			same(true, str_contains($json['preview'], '_version=changes'), 'Changes preview');
			$child = $app()->page($draft['page']);
			same(['Vom 5. bis 20.', 'Urlaub im August'], [$child->version('changes')->read()['text'], $child->version('changes')->read()['title']], 'Changes with the other fields carried');
			same('Vom 5. bis 15.', $child->text()->value(), 'Published text unchanged');
			// Conflict: the child is published in the Panel meanwhile.
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page($draft['page'])->update(['text' => 'Panel war schneller']);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same(409, $code, 'Hash conflict on the child');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']])[0], 'Discard update');
			same(false, $app()->page($draft['page'])->version('changes')->exists(), 'Changes removed');
			same('Panel war schneller', $app()->page($draft['page'])->text()->value(), 'Panel text stands');

			// Update then publish.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Urlaub im August', 'fields' => ['kategorie' => 'praxis']]);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, 'praxis', 'Panel war schneller'], [$code, $app()->page($draft['page'])->kategorie()->value(), $app()->page($draft['page'])->text()->value()], 'Update published, other field kept');

			// Create + discard deletes the draft page.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Weg damit', 'text' => 't']]);
			same(1, $app()->page('aktuelles')->drafts()->count(), 'Draft page exists');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']])[0], 'Discard create');
			same(0, $app()->page('aktuelles')->drafts()->count(), 'Draft page deleted');
			same(409, fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'urlaub im august', 'text' => 't']])[0], 'Create of an existing title refused');

			// A page without a stored UUID (content from before UUIDs). Fude only
			// reads the UUID, it never creates one. Kirby itself writes one into the
			// published version when the changes version is saved (and another on
			// publish, Panel alike); the anchor is whatever is stored at draft time,
			// and the publish goes through.
			$kirby = $app();
			$kirby->impersonate('kirby');
			$page = $kirby->page('aktuelles')->children()->first();
			$id = $page->id();
			$content = $page->version('latest')->read();
			unset($content['uuid']);
			$page->version('latest')->save($content, overwrite: true);
			same(null, $app()->page($id)->version('latest')->read()['uuid'] ?? null, 'Fixture: page without UUID');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Urlaub im August', 'fields' => ['text' => 'Ohne UUID']]);
			same([200, $app()->page($id)->version('latest')->read()['uuid'] ?? null], [$code, $json['draft']['uuid'] ?? 'missing'], 'Draft anchors on the UUID stored at draft time', $json);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, 'Ohne UUID'], [$code, $app()->page($id)?->version('latest')->read()['text'] ?? null], 'Publish of a page that had no UUID', $j);
		} finally {
			cleanup($tmp);
		}
	}
}
