<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Drafts;
use Sayamaapps\Fude\Service;

/**
 * Publishing a draft respects what is going on in the Panel. A page that
 * someone else holds a native Kirby lock on, or has unsaved Panel changes
 * on, is not published, for a new page (create) as for an update: 423, the
 * page stays as it is and the draft stays confirmable. The content conflict
 * (409) and the publish by the user who holds the lock are unchanged.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class PublishLockTest extends TestCase
{
	private const LOCKED = 'The page is open in the Panel right now. Save or discard there, then try again.';
	private const PENDING = 'The page has unsaved Panel changes. Save or discard them in the Panel first.';

	private function assertStillConfirmable(callable $app, string $id, string $page, string $text): void
	{
		same(true, $app()->page($page)->isDraft(), 'The page is still a draft');
		same($text, $app()->page($page)->version('latest')->read()['text'], 'Its content is as it was');
		same(true, is_file($app()->root('storage') . '/fude/drafts/' . $id . '.json'), 'The draft record is still there');
		same($id, Drafts::read($app(), $id)['id'], 'The record can be read');
	}

	/** Whether the page is locked for Fude, which publishes as Kirby itself. */
	private function lockedForFude(callable $app, string $page): bool
	{
		$kirby = $app();
		$kirby->impersonate('kirby');
		return $kirby->page($page)->lock()->isLocked();
	}

	/** Makes the changes version look as if the last Panel save was long ago: the lock has run out. */
	private function lockRunsOut(callable $app, string $page): void
	{
		$file = $app()->page($page)->version('changes')->contentFile();
		touch($file, time() - 3600);
		clearstatcache();
	}

	/** @return array{string, string, string} the draft's id, its token and the new page's id */
	private function newPage(callable $app, string $title = 'Lock example'): array
	{
		[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'create', 'fields' => ['title' => $title, 'text' => 'Original text']]);
		same(200, $code, 'Draft of a new page', $json);
		same(true, $app()->page($json['draft']['page'])->isDraft(), 'The new page is a draft');
		return [$json['draft']['id'], $json['token'], $json['draft']['page']];
	}

	/** The editor discards the page's changes version in the Panel (only the holder of the lock may). */
	private function panelDiscards(callable $app, string $page, string $as): void
	{
		$kirby = $app();
		$kirby->impersonate($as);
		$kirby->page($page)->version('changes')->delete();
	}

	/** Somebody saves the page's changes version in the Panel as $as; $edit changes fields on top of the page's content. */
	private function panelSaves(callable $app, string $page, string $as, array $edit = []): void
	{
		$kirby = $app();
		$kirby->impersonate($as);
		$child = $kirby->page($page);
		$changes = $child->version('changes');
		$changes->save($edit + ($changes->exists() ? $changes->read() : $child->version('latest')->read()));
	}

	public function testControlsOfTheCreatePublish(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			// Nobody is in the Panel: published, record gone.
			[$id, $token, $page] = $this->newPage($app, 'Free page');
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([200, 'listed', false], [$code, $app()->page($page)->status(), is_file($tmp . '/storage/fude/drafts/' . $id . '.json')], 'Unlocked page is published', $json);

			// The content changed after the draft: still 409, draft kept.
			[$id, $token, $page] = $this->newPage($app, 'Changed page');
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page($page)->update(['text' => 'Later Panel content']);
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([409, 'The draft was changed in the Panel meanwhile. Publish it there, or discard it and start again.'], [$code, $json['error'] ?? null], 'Changed content', $json);
			$this->assertStillConfirmable($app, $id, $page, 'Later Panel content');

			// The lock is held by the identity that publishes (Fude writes its drafts as Kirby itself): not a lock for Fude.
			[$id, $token, $page] = $this->newPage($app, 'Own page');
			$this->panelSaves($app, $page, 'kirby');
			same('kirby', $app()->page($page)->version('changes')->read()['lock'] ?? null, 'The changes version carries the publishing identity as its lock');
			same(false, $this->lockedForFude($app, $page), 'No lock for Fude');
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([200, 'listed'], [$code, $app()->page($page)->status()], 'Lock held by the same user does not stop the publish', $json);
		} finally {
			cleanup($tmp);
		}
	}

	public function testControlsOfTheUpdatePublish(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			[$id, $token, $page] = $this->newPage($app, 'Existing page');
			same(200, fude_call($app, 'publish', ['id' => $id, 'token' => $token])[0], 'Setup: published');

			// The draft's own changes version holds the lock of the user who made it: no obstacle.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Existing page', 'fields' => ['text' => 'First update']]);
			same(200, $code, 'Update draft', $json);
			same('kirby', $app()->page($page)->version('changes')->read()['lock'] ?? null, 'The changes version carries the publishing identity as its lock');
			[$code, $published] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([200, 'First update'], [$code, $app()->page($page)->version('latest')->read()['text']], 'Lock held by the same user does not stop the update', $published);

			// Another user opens the page after the draft: 423, nothing published, draft kept.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'action' => 'update', 'key' => 'Existing page', 'fields' => ['text' => 'Second update']]);
			same(200, $code, 'Second update draft', $json);
			$this->panelSaves($app, $page, FUDE_ADMIN);
			same(true, $this->lockedForFude($app, $page), 'The Panel lock is active');
			[$code, $published] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same([423, self::LOCKED], [$code, $published['error'] ?? null], 'Update with a lock of another user', $published);
			same('First update', $app()->page($page)->version('latest')->read()['text'], 'Published text unchanged');
			same(true, is_file($tmp . '/storage/fude/drafts/' . $json['draft']['id'] . '.json'), 'The draft record is still there');
		} finally {
			cleanup($tmp);
		}
	}

	public function testNativeLockOfAnotherUserRefusesPublishOfANewPage(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			[$id, $token, $page] = $this->newPage($app);
			$this->panelSaves($app, $page, FUDE_ADMIN);
			same(true, $this->lockedForFude($app, $page), 'The Panel lock is active');

			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([423, self::LOCKED], [$code, $json['error'] ?? null], 'Locked by another user', $json);
			$this->assertStillConfirmable($app, $id, $page, 'Original text');
			same(0, $app()->page('aktuelles')->children()->listed()->count(), 'Nothing listed');

			// The editor closes the page; the same confirmation goes through.
			$this->panelDiscards($app, $page, FUDE_ADMIN);
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same(200, $code, 'Published once the lock is gone', $json);
			same('listed', $app()->page($page)->status(), 'Listed now');
			same(false, is_file($tmp . '/storage/fude/drafts/' . $id . '.json'), 'Record gone');
		} finally {
			cleanup($tmp);
		}
	}

	public function testUnsavedPanelChangesOfAnotherUserRefuseThePublishOfANewPage(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			[$id, $token, $page] = $this->newPage($app);
			// Changes saved in the Panel, the lock of the editor has run out meanwhile.
			$this->panelSaves($app, $page, FUDE_ADMIN, ['text' => 'Edited in the Panel']);
			$this->lockRunsOut($app, $page);
			same(false, $this->lockedForFude($app, $page), 'No active lock any more');
			same(true, $app()->page($page)->version('changes')->exists(), 'The unsaved changes are there');

			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([423, self::PENDING], [$code, $json['error'] ?? null], 'Unsaved changes', $json);
			$this->assertStillConfirmable($app, $id, $page, 'Original text');
			same('Edited in the Panel', $app()->page($page)->version('changes')->read()['text'], 'The Panel changes are untouched');

			// Saved under the identity Fude publishes as (no foreign lock to see): still unsaved changes.
			$this->panelDiscards($app, $page, 'kirby');
			$this->panelSaves($app, $page, 'kirby', ['text' => 'Edited by a script']);
			same(false, $this->lockedForFude($app, $page), 'No lock for Fude');
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same([423, self::PENDING], [$code, $json['error'] ?? null], 'Unsaved changes without a lock', $json);
			$this->assertStillConfirmable($app, $id, $page, 'Original text');

			// Discarded in the Panel: the confirmation goes through.
			$this->panelDiscards($app, $page, 'kirby');
			[$code, $json] = fude_call($app, 'publish', ['id' => $id, 'token' => $token]);
			same(200, $code, 'Published once the changes are gone', $json);
			same(['listed', 'Original text'], [$app()->page($page)->status(), $app()->page($page)->version('latest')->read()['text']], 'Listed with the confirmed content');
		} finally {
			cleanup($tmp);
		}
	}
}
