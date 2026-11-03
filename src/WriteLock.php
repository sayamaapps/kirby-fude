<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use RuntimeException;

/**
 * One Fude write at a time per site: a lock file in `<storage>/fude/` that
 * the draft, publish and discard routes hold while they work.
 *
 * Without it, two drafts for the same page could overlap: both pass the
 * check for unsaved Panel changes, both save, and the fingerprint one of
 * them takes afterwards covers the other's content. Publishing with the
 * first draft's token would then publish the second draft.
 *
 * The lock serialises Fude requests against each other, not against Panel
 * saves. Those keep going through Kirby's own content lock and the checks
 * Fude already makes (fingerprint of the changes version, Panel lock).
 *
 * Named WriteLock to keep it apart from the Panel's `$page->lock()`.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class WriteLock
{
	public const FILE = 'write.lock';

	/** Pause between two tries, in microseconds. */
	private const STEP = 50000;

	/** How long a request waits for the lock, in seconds, before it answers 503. */
	public const WAIT = 30.0;

	/**
	 * Runs the callback while holding the lock and returns its result. The
	 * lock file stays; the lock ends with the callback, however it ends.
	 *
	 * @throws FudeError 503 when the lock stays taken for $wait seconds
	 * @throws RuntimeException when the lock file cannot be opened or locked
	 */
	public static function run(App $kirby, callable $callback, float $wait = self::WAIT): mixed
	{
		$dir = Options::directory($kirby);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new RuntimeException('Cannot create ' . $dir);
		}
		$file = $dir . '/' . self::FILE;
		$handle = @fopen($file, 'c');
		if ($handle === false) {
			throw new RuntimeException('Cannot open ' . $file);
		}
		try {
			$deadline = microtime(true) + $wait;
			$blocked = false;
			while (!flock($handle, LOCK_EX | LOCK_NB, $blocked)) {
				if (!$blocked) {
					throw new RuntimeException('Cannot lock ' . $file);
				}
				if (microtime(true) >= $deadline) {
					throw FudeError::t('draft.busy', [], 503);
				}
				usleep(self::STEP);
			}
			return $callback();
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
