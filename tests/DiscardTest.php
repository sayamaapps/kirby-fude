<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

/**
 * POST /fude/discard: removes the changes version and the uploaded file,
 * deletes the record, needs the token, is idempotent per record (second
 * call 404). A changes version edited in the Panel meanwhile is kept, and so
 * is a created page the Panel has worked on since.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class DiscardTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
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
			$record = Sayamaapps\Fude\Drafts::read($app(), $json['draft']['id']);
			$record['createdTs'] -= 100 * 3600;
			Sayamaapps\Fude\Drafts::write($app(), $record);
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
			same([200, true], [$code, $j['kept']], 'Discard after the Panel published the draft: consumed, kept');
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
			same([200, true], [$code, $j['kept']], 'Pages update discarded after the Panel published it: consumed, kept');
			same(true, $app()->page($child)->file($pageFile) !== null, 'Pages update: published image stays');

			// The draft's changes version is still exactly ours, but the published
			// version already uses the image (written there by something other than the
			// Panel's publish): the image belongs to the live page now. The draft's
			// own changes version goes, the image stays, the reply says kept.
			[, $json] = fude_call($app, 'draft', ['collection' => 'team', 'fields' => ['name' => 'Schon Live']], files: ['image' => fude_upload($tmp, 900, 600)]);
			$usedFile = $json['draft']['file'];
			$kirby = $app();
			$kirby->impersonate('kirby');
			$uuid = $kirby->page('standort')->file($usedFile)->uuid()->toString();
			$kirby->page('standort')->version('latest')->save([...$kirby->page('standort')->version('latest')->read(), 'einleitung' => 'Mit Foto: ' . $uuid]);
			[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, true], [$code, $j['kept']], 'Discard of a draft whose image the published page references');
			same(true, is_file($tmp . '/content/standort/' . $usedFile), 'Image referenced by the published page stays');
			same(false, $app()->page('standort')->version('changes')->exists(), 'The draft\'s own changes version still goes');

			// Same for a page update, referenced by file name (Kirbytext).
			[, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Foto Fall', 'fields' => ['text' => 'Neu 3']], files: ['image' => fude_upload($tmp, 1800, 1200, 'jpg')]);
			$usedPageFile = $json['draft']['file'];
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page($child)->version('latest')->save([...$kirby->page($child)->version('latest')->read(), 'text' => '(image: ' . $usedPageFile . ')']);
			[$code, $j] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, true], [$code, $j['kept']], 'Pages update: discard with an image the published page references');
			same(true, $app()->page($child)->file($usedPageFile) !== null, 'Pages update: referenced image stays');

			// Pages create: the discard deletes the draft page only while it is
			// still what the draft left. isDraft() alone is no licence.
			$create = function (string $title, bool $image = false) use ($app, $tmp): array {
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => $title, 'text' => 'Erster Text']], files: $image ? ['image' => fude_upload($tmp, 900, 600, 'jpg')] : []);
				same(200, $code, 'Create draft ' . $title, $json);
				return [$json['draft']['page'], ['id' => $json['draft']['id'], 'token' => $json['token']]];
			};
			$panel = function () use ($app) {
				$kirby = $app();
				$kirby->impersonate('kirby');
				return $kirby;
			};

			[$page, $draft] = $create('Unberuehrt', image: true);
			same(1, $app()->page($page)->files()->count(), 'Untouched draft page holds its image');
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, false, null], [$code, $j['kept'], $app()->page($page)], 'Untouched draft page (with its image) is deleted');

			[$page, $draft] = $create('Panel Text');
			$panel()->page($page)->update(['text' => 'Redaktion hat weitergeschrieben']);
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, true], [$code, $j['kept']], 'Discard of a draft page edited in the Panel keeps it');
			same('Redaktion hat weitergeschrieben', $app()->page($page)?->text()->value(), 'Panel text of the created page stays');
			same(false, is_file($tmp . '/storage/fude/drafts/' . $draft['id'] . '.json'), 'Record removed although the page stays');
			same(404, fude_call($app, 'discard', $draft)[0], 'Second discard: 404');

			[$page, $draft] = $create('Panel Ungespeichert');
			$kirby = $app();
			$kirby->impersonate(FUDE_ADMIN);
			$kirby->page($page)->version('changes')->save([...$kirby->page($page)->version('latest')->read(), 'text' => 'Admin tippt noch']);
			[$code, $j] = fude_call($app, 'discard', $draft, options: ['content.locking' => false]);
			same([200, true], [$code, $j['kept']], 'Unsaved Panel changes keep the created page');
			same('Admin tippt noch', $app()->page($page)?->version('changes')->read()['text'] ?? null, 'Unsaved Panel changes stay');

			[$page, $draft] = $create('Panel Sperre');
			$lock = $panel()->page($page);
			\Kirby\Data\Data::write($lock->root() . '/.lock', ['/' . $lock->id() => ['lock' => ['user' => $lock->kirby()->user(FUDE_ADMIN)->id(), 'time' => time()]]], 'yml');
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, true, true], [$code, $j['kept'], $app()->page($page) !== null], 'An active Panel lock keeps the created page');

			[$page, $draft] = $create('Mit Unterseite');
			$kirby = $panel();
			Kirby\Cms\Page::create(['parent' => $kirby->page($page), 'slug' => 'unterseite', 'template' => 'note', 'content' => ['title' => 'Unterseite'], 'isDraft' => true]);
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, true, true], [$code, $j['kept'], $app()->page($page . '/unterseite') !== null], 'A subpage keeps the created page and itself');

			[$page, $draft] = $create('Mit Datei', image: true);
			$kirby = $panel();
			$kirby->page($page)->createFile(['source' => fude_upload($tmp, 200, 100, 'jpg')['tmp_name'], 'filename' => 'aus-dem-panel.jpg', 'template' => 'bild', 'content' => ['alt' => 'Panel']]);
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, true, 2], [$code, $j['kept'], $app()->page($page)?->files()->count()], 'A file added in the Panel keeps the created page');

			[$page, $draft] = $create('Im Panel veroeffentlicht');
			$panel()->page($page)->changeStatus('listed');
			[$code, $j] = fude_call($app, 'discard', $draft);
			same([200, true, 'listed'], [$code, $j['kept'], $app()->page($page)?->status()], 'A page published in the Panel stays');

			$log = fude_log($tmp);
			$discards = array_values(array_filter($log, fn ($l) => $l['route'] === 'discard' && $l['status'] === 200));
			same(['telegram:42', 'team', 'Anna Neu', $id], [$discards[0]['actor'], $discards[0]['collection'], $discards[0]['key'], $discards[0]['draft']], 'Discard log line');
		} finally {
			cleanup($tmp);
		}
	}
}
