<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

function fude_editor(callable $app): Kirby\Cms\App
{
	$kirby = $app();
	$kirby->impersonate('kirby');
	return $kirby;
}

/**
 * Files are only removed while the draft owns them alone. A discard keeps
 * an uploaded image that another page (or the site) uses, or that was
 * edited since the draft; a confirmed page delete refuses when the page's
 * files changed after the draft or another page uses one of them.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class FileOwnershipTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		/** The kirby superuser on a fresh app, for Panel-like edits between calls. */
		try {
			// --- Discard of a structure draft --------------------------------------

			$discard = function (string $what, callable $between, bool $kept) use ($app, $tmp): void {
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Bild ' . bin2hex(random_bytes(2))]], files: ['image' => fude_upload($tmp, 300, 200)]);
				same(200, $code, "$what: draft", $json);
				$file = fude_editor($app)->page('standort')->file($json['draft']['file']);
				$root = $file->root();
				$between($file);
				[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
				same([200, $kept], [$code, $j['kept'] ?? null], "$what: discard", $j);
				same($kept, is_file($root), "$what: file " . ($kept ? 'stays' : 'goes'));
				same(false, fude_editor($app)->page('standort')->version('changes')->exists(), "$what: changes version gone");
				fude_editor($app)->page('aktuelles')->update(['text' => '']);
			};

			$discard('Kirbytext on another page', fn ($file) => fude_editor($app)->page('aktuelles')->update(['text' => '(image: ' . $file->id() . ')']), true);
			$discard('UUID on another page', fn ($file) => fude_editor($app)->page('aktuelles')->update(['text' => $file->uuid()->toString()]), true);
			$discard('Unsaved Panel changes on another page', fn ($file) => fude_editor($app)->page('aktuelles')->version('changes')->save(['title' => 'Aktuelles', 'text' => '(image: ' . $file->id() . ')']), true);
			fude_editor($app)->page('aktuelles')->version('changes')->delete();
			$discard('Site content', function ($file) use ($app) {
				fude_editor($app)->site()->update(['logo' => $file->uuid()->toString()]);
			}, true);
			fude_editor($app)->site()->update(['logo' => '']);
			$discard('Alt text edited since the draft', fn ($file) => $file->update(['alt' => 'Im Panel gepflegt']), true);
			$discard('Bare file name on another page names a file there', fn ($file) => fude_editor($app)->page('aktuelles')->update(['text' => '(image: ' . $file->filename() . ')']), false);
			$discard('Unused', fn ($file) => null, false);

			// --- Discard of a created page -----------------------------------------

			$created = function (string $what, callable $between, bool $kept) use ($app, $tmp): void {
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Neu ' . bin2hex(random_bytes(2)), 'text' => 'Mit Bild']], files: ['image' => fude_upload($tmp, 300, 200)]);
				same(200, $code, "$what: draft", $json);
				$page = fude_editor($app)->page($json['draft']['page']);
				$root = $page->root();
				$between($page, $page->file($json['draft']['file']));
				[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
				same([200, $kept], [$code, $j['kept'] ?? null], "$what: discard", $j);
				same($kept, is_dir($root), "$what: page " . ($kept ? 'stays' : 'goes'));
				fude_editor($app)->page('aktuelles')->update(['text' => '']);
			};

			$created('Image used on another page', fn ($page, $file) => fude_editor($app)->page('aktuelles')->update(['text' => '(image: ' . $file->id() . ')']), true);
			$created('Alt text edited since the draft', fn ($page, $file) => $file->update(['alt' => 'Im Panel gepflegt']), true);
			$created('Image replaced since the draft', fn ($page, $file) => file_put_contents($file->root(), file_get_contents(fude_upload($tmp, 310, 200)['tmp_name'])), true);
			$created('Another file uploaded', fn ($page, $file) => $page->createFile(['source' => fude_upload($tmp, 50, 50)['tmp_name'], 'filename' => 'dazu.png', 'template' => 'bild', 'content' => ['alt' => 'Dazu']]), true);
			$created('Untouched', fn ($page, $file) => null, false);

			// A draft made before files were fingerprinted: kept, when in doubt.
			[, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Alter Entwurf', 'text' => 'x']]);
			$record = Fude\Drafts::read($app(), $json['draft']['id']);
			unset($record['filesHash']);
			Fude\Drafts::write($app(), $record);
			[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, true], [$code, $j['kept']], 'Record without filesHash: the page stays');

			// --- Confirmed page delete ---------------------------------------------

			$options = fude_declare($tmp, 'files', <<<'PHP'
            ['collections' => [
                'notiz' => [
                    'kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'key' => 'title',
                    'actions' => ['create', 'update', 'delete'],
                    'fields' => ['title' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
            ]]
            PHP);
			$delete = function (string $what, callable $between, int $status, string|null $error = null) use ($app, $tmp, $options): void {
				$title = 'Weg ' . bin2hex(random_bytes(2));
				[, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'fields' => ['title' => $title, 'text' => 'x']], options: $options, files: ['image' => fude_upload($tmp, 300, 200)]);
				same(200, fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options)[0], "$what: page live");
				$id = $json['draft']['page'];
				$image = $json['draft']['file'];
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'notiz', 'action' => 'delete', 'key' => $title], options: $options);
				same(200, $code, "$what: delete draft", $json);
				$page = fude_editor($app)->page($id);
				$root = $page->root();
				$between($page, $page->file($image));
				[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
				same($status, $code, "$what: confirm", $j);
				if ($error !== null) {
					ok(str_contains($j['error'] ?? '', $error), "$what: message", $j);
				}
				same($status !== 200, is_dir($root), "$what: page " . ($status === 200 ? 'goes' : 'stays'));
				if ($status !== 200) {
					fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
					fude_editor($app)->page('home')->update(['leitsatz' => 'Willkommen']);
				}
			};
			$changed = 'Files on the page changed since this draft was made (added, replaced or edited). Create a new draft.';

			$delete('Panel upload after the draft', fn ($page) => $page->createFile(['source' => fude_upload($tmp, 50, 50)['tmp_name'], 'filename' => 'spaeter.png', 'template' => 'bild', 'content' => ['alt' => 'Später']]), 409, $changed);
			$delete('Alt text of an existing file edited', fn ($page, $file) => $file->update(['alt' => 'Nachträglich gepflegt']), 409, $changed);
			$delete('Existing file replaced', fn ($page, $file) => file_put_contents($file->root(), file_get_contents(fude_upload($tmp, 310, 200)['tmp_name'])), 409, $changed);
			$delete('File used on another page', fn ($page, $file) => fude_editor($app)->page('home')->update(['leitsatz' => '(image: ' . $file->id() . ')']), 409, 'has a file another page uses');
			$delete('Files unchanged', fn () => null, 200);

			// A delete draft made before files were fingerprinted: refused, when in doubt.
			$delete('Record without filesHash', function () use ($tmp) {
				foreach (glob($tmp . '/storage/fude/drafts/*.json') as $file) {
					$record = json_decode(file_get_contents($file), true);
					if ($record['action'] === 'delete') {
						unset($record['filesHash']);
						file_put_contents($file, json_encode($record));
					}
				}
			}, 409, $changed);
		} finally {
			cleanup($tmp);
		}
	}
}
