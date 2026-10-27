<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use RuntimeException;

/**
 * `<storage>/fude/log.jsonl`: one JSON line per call — who (Kirby account
 * and the channel actor the bot passes along), when, what (route,
 * collection, action, key, draft id) and the outcome (HTTP status, error
 * message). No field values, no images: the content itself is in Git or
 * in the nightly backup; the log answers "who changed what when".
 *
 * Logging never breaks a request: errors are swallowed after a note in the
 * PHP error log. Starts over at 4 MB.
 *
 * @package   Sayamaapps\Fude
 * @author    Simon Räder <https://sayamaapps.de>
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright Simon Räder
 * @license   MIT
 */
class Log
{
	public const FILE = 'log.jsonl';
	private const MAX_BYTES = 4 * 1024 * 1024;

	/** The path of the log file. */
	public static function file(App $kirby): string
	{
		return Options::directory($kirby) . '/' . self::FILE;
	}

	/** Appends one entry as a JSON line. Failures are noted in the PHP error log only. */
	public static function write(App $kirby, array $entry, \DateTimeImmutable $now): void
	{
		try {
			$dir = Options::directory($kirby);
			if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
				throw new RuntimeException('Cannot create ' . $dir);
			}
			$file = self::file($kirby);
			$mode = is_file($file) && filesize($file) > self::MAX_BYTES ? 0 : FILE_APPEND;
			$line = json_encode(['at' => $now->format(DATE_ATOM)] + $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($line === false || @file_put_contents($file, $line . "\n", $mode | LOCK_EX) === false) {
				throw new RuntimeException('Cannot write ' . $file);
			}
		} catch (\Throwable $e) {
			error_log('Fude: log not written: ' . $e->getMessage());
		}
	}
}
