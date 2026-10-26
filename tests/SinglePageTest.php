<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * kind: page — one page is the collection. Its declared fields are the page
 * fields; actions are only ['update'], no key. A draft is a whole-page
 * changes version (like structure), publish/discard work the same way, and
 * the schema lists the one entry with the published values.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class SinglePageTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$options = fude_declare($tmp, 'page', <<<'PHP'
            ['collections' => [
                'profil' => [
                    'kind' => 'page', 'page' => 'standort',
                    'touch' => ['field' => 'datum', 'value' => 'today'],
                    'fields' => [
                        'kurzname' => ['type' => 'text', 'required' => true],
                        'einleitung' => ['type' => 'textarea'],
                    ],
                ],
            ]]
            PHP);

			// Schema: one entry with the published values of the declared fields.
			[$code, $json] = fude_call($app, 'schema', options: $options);
			$profil = $json['collections']['profil'];
			same([200, 'page', ['update'], null, ['kurzname', 'einleitung']], [$code, $profil['kind'], $profil['actions'], $profil['key'], array_keys($profil['fields'])], 'Declaration');
			same([['kurzname' => 'Standort', 'einleitung' => 'Unser Haus in Musterstadt.']], $profil['entries'], 'Exactly one entry: the published values');

			// Only update exists for a page.
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'fields' => ['einleitung' => 'x']], options: $options);
			same([422, 'Collection "profil" does not allow the action "create".'], [$code, $j['error']], 'Create refused');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => []], options: $options);
			same([422, 'Nothing to change.'], [$code, $j['error']], 'Empty update refused');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['kurzname' => '']], options: $options);
			same([422, 'Field "kurzname" is required.'], [$code, $j['error']], 'Required field');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'x']], options: $options, files: ['image' => fude_upload($tmp, 300, 200)]);
			same([422, 'This collection takes no image.'], [$code, $j['error']], 'No image');
			same(false, $app()->page('standort')->version('changes')->exists(), 'Refusals write nothing');

			// A draft: no key, the whole page in the changes version, touch included.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'actor' => 'telegram:42', 'fields' => ['einleitung' => 'Neu: Sprechstunde am Dienstag.']], options: $options);
			same(200, $code, 'Update draft without key', $json);
			same(['page', 'update', 'standort', null, null], [$json['draft']['kind'], $json['draft']['action'], $json['draft']['page'], $json['draft']['key'], $json['draft']['index']], 'Record');
			same(true, str_contains($json['preview'], '_version=changes') && str_contains($json['preview'], '_token='), 'Preview of the changes');
			$changes = $app()->page('standort')->version('changes')->read();
			same(['Standort', 'Neu: Sprechstunde am Dienstag.', '2026-10-09'], [$changes['kurzname'], $changes['einleitung'], $changes['datum']], 'Changes carry the whole page, with today\'s date');
			same(true, isset($changes['team']), 'Other fields of the page are carried, not nulled');
			same('Unser Haus in Musterstadt.', $app()->page('standort')->version('latest')->read()['einleitung'], 'Published text untouched');

			// A second draft while the first is pending: the page is busy.
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'Zwei']], options: $options);
			same(423, $code, 'Pending draft blocks a second');

			// Publish: the text and the date go live, the changes version is gone.
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same([200, 'http://example.invalid/standort', 64], [$code, $j['url'], strlen($j['hash'])], 'Published', $j);
			$page = $app()->page('standort');
			same(['Neu: Sprechstunde am Dienstag.', 'Standort', '2026-10-09', false], [$page->einleitung()->value(), $page->kurzname()->value(), $page->version('latest')->read()['datum'], $page->version('changes')->exists()], 'Live');

			// Discard leaves the page as it is.
			[, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'Verworfen']], options: $options);
			same([200, false], [fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], $app()->page('standort')->version('changes')->exists()], 'Discard');
			same('Neu: Sprechstunde am Dienstag.', $app()->page('standort')->einleitung()->value(), 'Discard: published text stays');

			// A Panel edit in between: the publish is refused (409).
			[, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'Mit Konflikt']], options: $options);
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('standort')->update(['einleitung' => 'Panel war schneller']);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			same(409, $code, 'Panel edit in between: conflict');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('standort')->version('changes')->delete();

			// A Panel changes version on the page: no draft at all (423).
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('standort')->version('changes')->save([...$kirby->page('standort')->version('latest')->read(), 'einleitung' => 'Im Panel']);
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'x']], options: $options);
			same(423, $code, 'Unsaved Panel changes block the draft');
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page('standort')->version('changes')->delete();

			// Declaration mistakes are loud.
			$bad = function (string $php, string $name) use ($tmp, $app): array {
				$response = fude_call($app, 'schema', options: fude_declare($tmp, $name, $php));
				return [$response[0], $response[1]['error'] ?? null];
			};
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": kind page has no key'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'page' => 'standort', 'key' => 'kurzname', 'fields' => ['kurzname' => 'text']]]]", 'p1'),
				'No key for a page'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": kind page allows only the action update'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'page' => 'standort', 'actions' => ['create', 'update'], 'fields' => ['kurzname' => 'text']]]]", 'p2'),
				'Only update'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": image fields are not supported for kind page'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['kurzname' => 'text', 'foto' => 'image']]]]", 'p3'),
				'No image on a page'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": field "gibtsnicht" is not in the blueprint'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['gibtsnicht' => 'text']]]]", 'p4'),
				'Field not in the page blueprint'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": page "nirgends" does not exist'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'page' => 'nirgends', 'fields' => ['kurzname' => 'text']]]]", 'p5'),
				'Page must exist'
			);
			same(
				[500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "p": "page" missing'],
				$bad("['collections' => ['p' => ['kind' => 'page', 'fields' => ['kurzname' => 'text']]]]", 'p6'),
				'Page id missing'
			);

			// Log: the publish is one line with the action update.
			$publishes = array_values(array_filter(fude_log($tmp), fn ($l) => $l['route'] === 'publish' && $l['status'] === 200));
			$last = end($publishes);
			same(['publish', 'profil', 'update', 200], [$last['route'], $last['collection'], $last['action'], $last['status']], 'Log line');
		} finally {
			cleanup($tmp);
		}
	}
}
