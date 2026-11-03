<?php

use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Drafts;
use Sayamaapps\Fude\Service;

/**
 * A draft whose record cannot be written leaves nothing behind. The folder
 * of the records is checked before anything is stored (500, nothing
 * changed), and a failure after the content was written undoes it: the
 * uploaded files, a created draft page, and the page's changes version back
 * to what it was (gone, or its previous fields). The same request works
 * again once the storage is repaired. If the undo fails too, the reply says
 * so instead of claiming that nothing changed. The routes that can fail
 * half-way (publish, discard) never claim it. Every content route holds the
 * write lock while it works.
 *
 * The "late" failures use tests/fixtures/storage-seam.php: a callback that
 * runs around Kirby's content writes and deletes.
 *
 * A Kirby `after` hook (the regular `hooks` option) runs after the page or
 * the image is stored and before Kirby hands it back. When it throws, the draft
 * has to undo what Kirby stored all the same, though the code never got the
 * page or the file; and an undo that fails says so.
 */
#[CoversClass(Service::class)]
#[CoversClass(Drafts::class)]
#[RunTestsInSeparateProcesses]
final class DraftRollbackTest extends TestCase
{
	private const NOTHING_CHANGED = 'Internal error, nothing changed.';

	/**
	 * Every draft that stores a page or an image, with the `after` hook that
	 * fails it: [request, uploads, the id of the page whose changes version the
	 * draft writes (null: a new page), hook name => the call it throws from].
	 *
	 * @return array<string, array{0: array, 1: callable, 2: string|null, 3: array<string, int>}>
	 */
	private function afterHookScenarios(string $tmp, string $news): array
	{
		$entries = [['titel' => 'Tomaten', 'text' => 'Rot.'], ['titel' => 'Gurken', 'text' => 'Grün.']];
		$two = fn () => ['image0' => fude_upload($tmp, 300, 200, 'png', 'eins'), 'image1' => fude_upload($tmp, 300, 200, 'jpg', 'zwei')];
		$one = fn (string $format = 'jpg') => fn () => ['image' => fude_upload($tmp, 900, 600, $format)];
		return [
			'new page' => [
				['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Text']],
				fn () => [],
				null,
				['page.create:after' => 1],
			],
			'new page with an image, the page hook fails' => [
				['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Text']],
				$one(),
				null,
				['page.create:after' => 1],
			],
			'new page with an image, the file hook fails' => [
				['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Text']],
				$one(),
				null,
				['file.create:after' => 1],
			],
			'new page with an image, the page update hook fails' => [
				['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Text']],
				$one(),
				null,
				['page.update:after' => 1],
			],
			'structure create with an image' => [
				['collection' => 'aktuelles', 'fields' => ['titel' => 'Tomaten', 'text' => 'Rot.']],
				$one('png'),
				'home',
				['file.create:after' => 1],
			],
			'structure update with an image' => [
				['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => ['rolle' => 'Empfang']],
				$one(),
				'standort',
				['file.create:after' => 1],
			],
			'pages update with an image' => [
				['collection' => 'news', 'action' => 'update', 'key' => 'Foto Fall', 'fields' => ['text' => 'Neu']],
				$one(),
				$news,
				['file.create:after' => 1],
			],
			'structure create with two entries, the second image fails' => [
				['collection' => 'aktuelles', 'action' => 'create', 'entries' => $entries],
				$two,
				'home',
				['file.create:after' => 2],
			],
		];
	}

	/** A file where the folder of the records belongs: nothing can be created in it. */
	private function block(string $tmp): void
	{
		$drafts = $tmp . '/storage/fude/drafts';
		if (is_dir($drafts)) {
			Dir::remove($drafts);
		}
		if (!file_exists($drafts)) {
			@mkdir(dirname($drafts), 0775, true);
			file_put_contents($drafts, 'in the way');
		}
	}

	/** Takes the changes version an earlier scenario left on a page away again. */
	private function dropChanges(callable $app, string|null $page): void
	{
		if ($page !== null && $app()->page($page)->version('changes')->exists()) {
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kirby->page($page)->version('changes')->delete();
		}
	}

	/** The folders below content/, to tell a draft folder that stays from one that went. */
	private function folders(string $tmp): array
	{
		$folders = [];
		$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp . '/content', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($items as $path => $info) {
			if ($info->isDir()) {
				$folders[] = substr((string)$path, strlen($tmp));
			}
		}
		sort($folders);
		return $folders;
	}

	/**
	 * Kirby hooks from the regular `hooks` option. Each hook counts its calls
	 * in $calls and throws from the call number given on (0 = never).
	 *
	 * @param array<string, int> $throwing hook name => number of the call it throws from
	 * @param array<string, int> $calls
	 */
	private function hooks(array $throwing, array &$calls = []): array
	{
		$hooks = [];
		foreach ($throwing as $name => $from) {
			$hooks[$name] = function (mixed ...$arguments) use ($name, $from, &$calls): void {
				$calls[$name] = ($calls[$name] ?? 0) + 1;
				if ($from > 0 && $calls[$name] >= $from) {
					throw new RuntimeException('hook: ' . $name);
				}
			};
		}
		return ['hooks' => $hooks];
	}

	/** A changes version equal to the published page, as the Panel leaves it after a save without edits. */
	private function identicalChanges(callable $app, string $id): array
	{
		$kirby = $app();
		$kirby->impersonate('kirby');
		$kirby->page($id)->version('changes')->save($kirby->page($id)->version('latest')->read());
		return $app()->page($id)->version('changes')->read();
	}

	/** A published page "Foto Fall" to update; returns its id. */
	private function publishedNews(callable $app): string
	{
		[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Foto Fall', 'text' => 'Text']]);
		same(200, $code, 'Setup: draft', $json);
		[$code] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
		same(200, $code, 'Setup: published');
		return $json['draft']['page'];
	}

	/** The draft records on disk, by file name. */
	private function records(string $tmp): array
	{
		$records = glob($tmp . '/storage/fude/drafts/*.json') ?: [];
		sort($records);
		return array_map('basename', $records);
	}

	/** The same draft without hooks works, leaves one record, and discards again. */
	private function retries(callable $app, string $tmp, string $case, array $body, callable $uploads, string|null $page): void
	{
		[$code, $json] = fude_call($app, 'draft', $body, files: $uploads());
		same(200, $code, $case . ': the same request without the failing hook works', $json);
		same(1, count($this->records($tmp)), $case . ': one record');
		[$code] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
		same(200, $code, $case . ': discarded');
		same([], $this->records($tmp), $case . ': no record after the discard');
		$this->dropChanges($app, $page);
	}

	/**
	 * Every scenario: [request, uploads, options, the id of the page whose
	 * changes version the draft writes (null: a new page)].
	 *
	 * @return array<string, array{0: array, 1: callable, 2: array, 3: string|null}>
	 */
	private function scenarios(string $tmp, string $news): array
	{
		$profile = fude_declare($tmp, 'profile', <<<'PHP'
        ['collections' => ['profil' => [
            'kind' => 'page', 'page' => 'standort',
            'fields' => ['kurzname' => ['type' => 'text', 'required' => true], 'einleitung' => ['type' => 'textarea']],
        ]]]
        PHP);
		$replace = fude_declare($tmp, 'replace', <<<'PHP'
        ['collections' => ['liste' => [
            'kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'name', 'actions' => ['replace'],
            'fields' => ['name' => ['type' => 'text', 'required' => true], 'rolle' => ['type' => 'text']],
        ]]]
        PHP);
		return [
			'structure create with two entries' => [
				['collection' => 'aktuelles', 'action' => 'create', 'entries' => [['titel' => 'Tomaten', 'text' => 'Rot.'], ['titel' => 'Gurken', 'text' => 'Grün.']]],
				fn () => ['image0' => fude_upload($tmp, 300, 200, 'png', 'eins'), 'image1' => fude_upload($tmp, 300, 200, 'jpg', 'zwei')],
				[],
				'home',
			],
			'structure update with an image' => [
				['collection' => 'team', 'action' => 'update', 'key' => 'Veronika Beispiel', 'fields' => ['rolle' => 'Empfang']],
				fn () => ['image' => fude_upload($tmp, 900, 600)],
				[],
				'standort',
			],
			'structure replace' => [
				['collection' => 'liste', 'action' => 'replace', 'entries' => [['name' => 'Jörg Muster', 'rolle' => 'Chef'], ['name' => 'Neu Dabei']]],
				fn () => [],
				$replace,
				'standort',
			],
			'pages update with an image' => [
				['collection' => 'news', 'action' => 'update', 'key' => 'Foto Fall', 'fields' => ['text' => 'Neu']],
				fn () => ['image' => fude_upload($tmp, 900, 600, 'jpg')],
				[],
				$news,
			],
			'new page with an image' => [
				['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Text']],
				fn () => ['image' => fude_upload($tmp, 900, 600, 'jpg')],
				[],
				null,
			],
			'page' => [
				['collection' => 'profil', 'action' => 'update', 'fields' => ['einleitung' => 'Neu: Sprechstunde am Dienstag.']],
				fn () => [],
				$profile,
				'standort',
			],
		];
	}

	/** What a draft request leaves on disk: the content files, the folders and the draft records. */
	private function state(string $tmp): array
	{
		return ['files' => $this->tree($tmp), 'folders' => $this->folders($tmp), 'records' => $this->records($tmp)];
	}

	public function testAfterHookThatThrowsLeavesNothing(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$news = $this->publishedNews($app);
			foreach ($this->afterHookScenarios($tmp, $news) as $label => [$body, $uploads, $page, $throwing]) {
				foreach ($page === null ? [false] : [false, true] as $existing) {
					$case = $label . ($existing ? ' (changes version existed)' : '');
					$prior = $existing ? $this->identicalChanges($app, $page) : null;
					$before = $this->state($tmp);

					$calls = [];
					[$code, $json] = fude_call($app, 'draft', $body, options: $this->hooks($throwing, $calls), files: $uploads());
					$hook = (string)array_key_first($throwing);
					ok(($calls[$hook] ?? 0) >= $throwing[$hook], $case . ': the hook ran, so the failure came from there', $calls);
					same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], $case . ': 500, nothing changed');
					same($before, $this->state($tmp), $case . ': files, draft folder, changes version and records as before');
					if ($page !== null) {
						same($prior, $app()->page($page)->version('changes')->exists() ? $app()->page($page)->version('changes')->read() : null, $case . ': the changes version is what it was');
					}
					$log = fude_log($tmp);
					same(500, end($log)['status'], $case . ': request log: 500');

					$this->retries($app, $tmp, $case, $body, $uploads, $page);
				}
			}
		} finally {
			cleanup($tmp);
		}
	}

	public function testBeforeHookThatThrowsAndAfterHooksThatDoNotAreControls(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$news = $this->publishedNews($app);
			foreach ($this->afterHookScenarios($tmp, $news) as $label => [$body, $uploads, $page, $throwing]) {
				// The same hook, but before Kirby stores anything: nothing to undo, nothing left.
				$before = $this->state($tmp);
				$calls = [];
				$name = str_replace(':after', ':before', (string)array_key_first($throwing));
				[$code, $json] = fude_call($app, 'draft', $body, options: $this->hooks([$name => 1], $calls), files: $uploads());
				ok($calls !== [], $label . ': the before hook ran', $calls);
				same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], $label . ': before hook throws: 500, nothing changed');
				same($before, $this->state($tmp), $label . ': before hook throws: nothing left');

				// Hooks that run and do not throw: the draft works and leaves one record.
				$calls = [];
				[$code, $json] = fude_call($app, 'draft', $body, options: $this->hooks(['page.create:after' => 0, 'file.create:after' => 0], $calls), files: $uploads());
				same(200, $code, $label . ': after hooks that do not throw: 200', $json);
				ok(array_sum($calls) > 0, $label . ': the after hooks ran', $calls);
				same(1, count($this->records($tmp)), $label . ': one record');
				[$code] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
				same(200, $code, $label . ': discarded');
				$this->dropChanges($app, $page);
			}
		} finally {
			cleanup($tmp);
		}
	}

	public function testFailingUndoSaysSo(): void
	{
		[$tmp, $app] = fude_fixture(seam: true);
		try {
			$seam = ['test.storage' => function (string $when, string $operation, string $version, ModelWithContent $model) use ($tmp): void {
				if (!$model instanceof Page || $version !== 'changes') {
					return;
				}
				if ($when === 'after' && $operation === 'write') {
					$this->block($tmp);
				}
				if ($when === 'before' && $operation === 'delete') {
					throw new RuntimeException('seam: cannot delete the changes version');
				}
			}];
			$before = $this->tree($tmp);
			[$code, $json] = fude_call(
				$app,
				'draft',
				['collection' => 'aktuelles', 'action' => 'create', 'entries' => [['titel' => 'Tomaten', 'text' => 'Rot.'], ['titel' => 'Gurken', 'text' => 'Grün.']]],
				options: $seam,
				files: ['image0' => fude_upload($tmp, 300, 200, 'png', 'eins'), 'image1' => fude_upload($tmp, 300, 200, 'jpg', 'zwei')]
			);
			same(
				[500, 'The draft could not be saved, and undoing it failed. Check the page in the Panel before trying again.'],
				[$code, $json['error'] ?? null],
				'Undo failed: 500 that does not claim "nothing changed"'
			);
			$after = $this->tree($tmp);
			$left = array_keys(array_diff_key($after, $before));
			same(['/content/home/_changes/home.txt'], $left, 'The steps that could run did: both images are gone, only the changes version stays');

			// Both errors are in the PHP error log; the request log has the 500.
			$errors = (string)file_get_contents($GLOBALS['fudeLogFile']);
			ok(str_contains($errors, 'Fude: RuntimeException: Cannot create ' . $tmp . '/storage/fude/drafts'), 'The failure that caused the undo is logged', $errors);
			ok(str_contains($errors, 'Fude: RuntimeException: seam: cannot delete the changes version'), 'The failure of the undo is logged', $errors);
			$log = fude_log($tmp);
			same(500, end($log)['status'], 'Request log: 500');

			// What is left is for the Panel to clean up: the page counts as having unsaved changes.
			unlink($tmp . '/storage/fude/drafts');
			[$code] = fude_call($app, 'draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Radieschen', 'text' => 'Rot.']]);
			same(423, $code, 'The leftover changes version blocks the next draft');
		} finally {
			cleanup($tmp);
		}
	}

	public function testFailureAfterTheContentWasWrittenIsUndone(): void
	{
		[$tmp, $app] = fude_fixture(seam: true);
		try {
			$news = $this->publishedNews($app);
			// The record folder breaks right after the content of the draft was written.
			$breakAfterWrite = fn (string $version) => ['test.storage' => function (string $when, string $operation, string $v, ModelWithContent $model) use ($tmp, $version): void {
				if ($when === 'after' && $operation === 'write' && $v === $version && $model instanceof Page) {
					$this->block($tmp);
				}
			}];

			foreach ($this->scenarios($tmp, $news) as $label => [$body, $uploads, $options, $page]) {
				// A new page is written as a published version of its own; every other draft writes the changes version.
				$seam = $breakAfterWrite($page === null ? 'latest' : 'changes');
				foreach ($page === null ? [false] : [false, true] as $existing) {
					$case = $label . ($existing ? ' (changes version existed)' : '');
					$prior = $existing ? $this->identicalChanges($app, $page) : null;
					$before = $this->tree($tmp);

					[$code, $json] = fude_call($app, 'draft', $body, options: $options + $seam, files: $uploads());
					same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], $case . ': 500 after the content was written');
					same(true, is_file($tmp . '/storage/fude/drafts'), $case . ': the record folder was in fact broken');
					same($before, $this->tree($tmp), $case . ': content as before: files, draft page and changes version');
					if ($page !== null) {
						same($prior, $app()->page($page)->version('changes')->exists() ? $app()->page($page)->version('changes')->read() : null, $case . ': the changes version is what it was');
					}

					unlink($tmp . '/storage/fude/drafts');
					[$code, $json] = fude_call($app, 'draft', $body, options: $options, files: $uploads());
					same(200, $code, $case . ': the same request after the repair is no 423', $json);
					[$code] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
					same(200, $code, $case . ': discarded');
					if ($existing) {
						$kirby = $app();
						$kirby->impersonate('kirby');
						$kirby->page($page)->version('changes')->delete();
					}
				}
			}
		} finally {
			cleanup($tmp);
		}
	}

	public function testPageOfTheSameSlugIsNotOursToUndo(): void
	{
		// The slug of a new page ends in two random bytes; here they are fixed, so that
		// the page the draft wants to create is there already.
		require_once __DIR__ . '/fixtures/fixed-random.php';
		$GLOBALS['fudeRandom'] = [2 => "\xab\xcd"];
		[$tmp, $app] = fude_fixture();
		try {
			$slug = 'neue-meldung-abcd';
			foreach (['draft' => null, 'unlisted page' => 'unlisted', 'listed page' => 'listed'] as $label => $status) {
				$kirby = $app();
				$kirby->impersonate('kirby');
				$existing = Page::create(['parent' => $kirby->page('aktuelles'), 'slug' => $slug, 'template' => 'note', 'isDraft' => true, 'content' => ['title' => 'Alt', 'text' => 'Bleibt']]);
				if ($status !== null) {
					$existing->changeStatus($status);
				}
				$before = $this->state($tmp);

				file_put_contents($GLOBALS['fudeLogFile'], '');
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Neu']], files: ['image' => fude_upload($tmp, 900, 600, 'jpg')]);
				same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], $label . ': Kirby refuses the duplicate slug: 500, nothing changed');
				ok(str_contains((string)file_get_contents($GLOBALS['fudeLogFile']), 'DuplicateException'), $label . ': it was the duplicate that failed it');
				same($before, $this->state($tmp), $label . ': the page that was there stays, with its content');
				same('Bleibt', (string)$app()->page('aktuelles/' . $slug)?->text(), $label . ': and can be read');

				$kirby = $app();
				$kirby->impersonate('kirby');
				$kirby->page('aktuelles/' . $slug)->delete(true);
			}

			// Control: with the slug free, the same request works.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'news', 'fields' => ['title' => 'Neue Meldung', 'text' => 'Neu']]);
			same([200, 'aktuelles/' . $slug], [$code, $json['draft']['page'] ?? null], 'Free slug: the draft is created under it');
		} finally {
			cleanup($tmp);
		}
	}

	public function testRecordFolderIsCheckedBeforeAnythingIsStored(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$news = $this->publishedNews($app);
			foreach ($this->scenarios($tmp, $news) as $label => [$body, $uploads, $options]) {
				$before = $this->tree($tmp);
				$resized = is_dir($tmp . '/storage/fude/tmp');
				$this->block($tmp);
				[$code, $json] = fude_call($app, 'draft', $body, options: $options, files: $uploads());
				same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], $label . ': 500, nothing changed');
				same($resized, is_dir($tmp . '/storage/fude/tmp'), $label . ': found out before an upload was processed');
				same($before, $this->tree($tmp), $label . ': no changes version, no image, no draft page');
				same('in the way', file_get_contents($tmp . '/storage/fude/drafts'), $label . ': the blocker is untouched');

				unlink($tmp . '/storage/fude/drafts');
				[$code, $json] = fude_call($app, 'draft', $body, options: $options, files: $uploads());
				same(200, $code, $label . ': the same request after the repair is no 423', $json);
				[$code] = fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $options);
				same(200, $code, $label . ': discarded');
			}

			// A folder that exists but takes no files is found just the same.
			$drafts = $tmp . '/storage/fude/drafts';
			if (!is_dir($drafts)) {
				mkdir($drafts, 0775, true);
			}
			chmod($drafts, 0555);
			if (!is_writable($drafts)) {
				$before = $this->tree($tmp);
				[$code, $json] = fude_call($app, 'draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Radieschen', 'text' => 'Rot.']]);
				same([500, self::NOTHING_CHANGED], [$code, $json['error'] ?? null], 'Folder without write permission: 500, nothing changed');
				same($before, $this->tree($tmp), 'Folder without write permission: nothing stored');
			}
			chmod($drafts, 0775);
		} finally {
			cleanup($tmp);
		}
	}

	public function testRefusedDraftLeavesAChangesVersionItDidNotWrite(): void
	{
		[$tmp, $app] = fude_fixture(seam: true);
		try {
			// The blueprint refuses what the declaration lets through: a title and a text that are too short.
			file_put_contents($tmp . '/site/blueprints/pages/kurz.yml', "title: Kurz\nfields:\n  text:\n    type: textarea\n    minlength: 20\n  bild:\n    type: files\n    multiple: false\n    uploads:\n      template: bild\n");
			$kirby = $app();
			$kirby->impersonate('kirby');
			Page::create(['parent' => $kirby->page('aktuelles'), 'slug' => 'kurz', 'template' => 'kurz', 'isDraft' => true, 'content' => ['title' => 'Kurznotiz', 'text' => 'Ein ausreichend langer Text.']])->changeStatus('listed');
			$options = fude_declare($tmp, 'refused', <<<'PHP'
            ['collections' => [
                'meldung' => [
                    'kind' => 'structure', 'page' => 'home', 'field' => 'meldungen', 'key' => 'titel',
                    'fields' => ['titel' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
                'notiz' => [
                    'kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'kurz', 'key' => 'title', 'actions' => ['update'],
                    'fields' => ['title' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea'], 'bild' => ['type' => 'image']],
                ],
            ]]
            PHP);
			// While the draft processes its image, an editor starts working on the page in the Panel.
			$editorStarts = function (string $page, array $edit) {
				$started = false;
				return ['test.storage' => function (string $when, string $operation, string $version, ModelWithContent $model) use (&$started, $page, $edit): void {
					if ($started || $when !== 'before' || $operation !== 'write' || !$model instanceof File) {
						return;
					}
					$started = true;
					$target = $model->kirby()->page($page);
					$target->version('changes')->save([...$target->version('latest')->read(), ...$edit]);
				}];
			};
			$scenarios = [
				'structure create with an image' => [
					['collection' => 'meldung', 'fields' => ['titel' => 'Ab', 'text' => 'x']],
					fn () => ['image' => fude_upload($tmp, 300, 200)],
					'home',
					'leitsatz',
				],
				'structure create with two entries' => [
					['collection' => 'meldung', 'action' => 'create', 'entries' => [['titel' => 'Ab', 'text' => '1'], ['titel' => 'Gurken', 'text' => '2']]],
					fn () => ['image0' => fude_upload($tmp, 300, 200, 'png', 'eins'), 'image1' => fude_upload($tmp, 300, 200, 'jpg', 'zwei')],
					'home',
					'leitsatz',
				],
				'pages update with an image' => [
					['collection' => 'notiz', 'action' => 'update', 'key' => 'Kurznotiz', 'fields' => ['text' => 'kurz']],
					fn () => ['image' => fude_upload($tmp, 900, 600, 'jpg')],
					'aktuelles/kurz',
					'text',
				],
			];
			foreach ($scenarios as $label => [$body, $uploads, $page, $field]) {
				$before = $this->tree($tmp);
				[$code, $json] = fude_call($app, 'draft', $body, options: $options + $editorStarts($page, [$field => 'Redaktion tippt']), files: $uploads());
				ok($code === 422 && str_contains($json['error'] ?? '', 'blueprint'), $label . ': refused by the blueprint', [$code, $json]);
				$changes = $app()->page($page)->version('changes');
				same([true, 'Redaktion tippt'], [$changes->exists(), $changes->read()[$field] ?? null], $label . ': the editor\'s changes version is still there, unchanged');
				$content = array_filter($this->tree($tmp), fn (string $path) => !str_contains($path, '/_changes/'), ARRAY_FILTER_USE_KEY);
				same($before, $content, $label . ': the images of the refused draft are gone, nothing else was touched');
				$kirby = $app();
				$kirby->impersonate('kirby');
				$kirby->page($page)->version('changes')->delete();
			}
		} finally {
			cleanup($tmp);
		}
	}

	public function testRoutesThatCanFailHalfWayDoNotClaimNothingChanged(): void
	{
		[$tmp, $app] = fude_fixture(seam: true);
		try {
			$armed = null;
			$locked = [];
			$seam = ['test.storage' => function (string $when, string $operation, string $version, ModelWithContent $model) use ($tmp, &$armed, &$locked): void {
				if ($when !== 'before' || !$model instanceof Page) {
					return;
				}
				// Every route that changes content holds the write lock while it writes.
				$handle = fopen($tmp . '/storage/fude/write.lock', 'c');
				$locked[] = !flock($handle, LOCK_EX | LOCK_NB);
				fclose($handle);
				if ($armed === [$operation, $version]) {
					throw new RuntimeException('seam: ' . $operation . ' ' . $version);
				}
			}];
			$call = fn (string $route, array $body, array $options = []) => fude_call($app, $route, $body, options: $seam + $options);

			[$code, $json] = $call('draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Radieschen', 'text' => 'Rot.']]);
			same(200, $code, 'Draft', $json);
			ok($locked !== [] && !in_array(false, $locked, true), 'The draft route held the write lock while writing', $locked);
			$ticket = ['id' => $json['draft']['id'], 'token' => $json['token']];

			// Publish fails while the published version is written.
			$armed = ['write', 'latest'];
			$locked = [];
			[$code, $j] = $call('publish', $ticket);
			same([500, 'Internal error. Check the page in the Panel before trying again.'], [$code, $j['error'] ?? null], 'Publish: 500 without "nothing changed"');
			[$code, $j] = $call('publish', $ticket, ['fude.language' => 'de']);
			same([500, 'Interner Fehler. Die Seite im Panel prüfen, bevor es noch einmal versucht wird.'], [$code, $j['error'] ?? null], 'Publish: the German text');
			$armed = null;
			$locked = [];
			[$code] = $call('publish', $ticket);
			same(200, $code, 'Publish again once the storage works');
			ok($locked !== [] && !in_array(false, $locked, true), 'The publish route held the write lock while writing', $locked);

			// Discard fails while the changes version is removed.
			[$code, $json] = $call('draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Lauch', 'text' => 'Grün.']]);
			same(200, $code, 'Second draft', $json);
			$ticket = ['id' => $json['draft']['id'], 'token' => $json['token']];
			$armed = ['delete', 'changes'];
			$locked = [];
			[$code, $j] = $call('discard', $ticket);
			same([500, 'Internal error. Check the page in the Panel before trying again.'], [$code, $j['error'] ?? null], 'Discard: 500 without "nothing changed"');
			$armed = null;
			$locked = [];
			[$code] = $call('discard', $ticket);
			same(200, $code, 'Discard again once the storage works');
			ok($locked !== [] && !in_array(false, $locked, true), 'The discard route held the write lock while deleting', $locked);

			// A failing draft says "nothing changed" because the undo made it true.
			$this->block($tmp);
			[$code, $j] = $call('draft', ['collection' => 'aktuelles', 'fields' => ['titel' => 'Rettich', 'text' => 'Rot.']]);
			same([500, self::NOTHING_CHANGED], [$code, $j['error'] ?? null], 'Draft: nothing changed');
		} finally {
			cleanup($tmp);
		}
	}

	public function testUndoThatFailsAfterAHookSaysSo(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$news = $this->publishedNews($app);
			foreach ($this->afterHookScenarios($tmp, $news) as $label => [$body, $uploads, , $throwing]) {
				// Besides the failing after hook, the undo cannot delete what the draft stored.
				$stuck = ['file.delete:before' => 1, 'page.delete:before' => 1];
				$before = $this->state($tmp);
				file_put_contents($GLOBALS['fudeLogFile'], '');

				[$code, $json] = fude_call($app, 'draft', $body, options: $this->hooks($throwing + $stuck), files: $uploads());
				same(
					[500, 'The draft could not be saved, and undoing it failed. Check the page in the Panel before trying again.'],
					[$code, $json['error'] ?? null],
					$label . ': undo failed: 500 that does not claim "nothing changed"'
				);
				$after = $this->state($tmp);
				ok($after['files'] !== $before['files'], $label . ': what the draft stored is still there, as the reply says');
				same($before['records'], $after['records'], $label . ': no record');

				// Both failures are in the PHP error log, the request log has the 500.
				$errors = (string)file_get_contents($GLOBALS['fudeLogFile']);
				ok(str_contains($errors, 'Fude: RuntimeException: hook: ' . array_key_first($throwing)), $label . ': the failure that caused the undo is logged', $errors);
				ok(preg_match('/Fude: RuntimeException: hook: (file|page)\.delete:before/', $errors) === 1, $label . ': the failure of the undo is logged', $errors);
				$log = fude_log($tmp);
				same(500, end($log)['status'], $label . ': request log: 500');
			}
		} finally {
			cleanup($tmp);
		}
	}

	/**
	 * The content files by path and checksum: what a draft leaves behind shows
	 * here. Kirby gives a page a UUID the first time it tracks changes for it,
	 * and keeps it; that line is left out.
	 */
	private function tree(string $tmp): array
	{
		$tree = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp . '/content', FilesystemIterator::SKIP_DOTS));
		foreach ($files as $path => $info) {
			$text = (string)file_get_contents((string)$path);
			$tree[substr((string)$path, strlen($tmp))] = md5(str_ends_with((string)$path, '.txt') ? rtrim((string)preg_replace('/\n*----\n+Uuid: [^\n]*/i', '', $text)) : $text);
		}
		ksort($tree);
		return $tree;
	}
}
