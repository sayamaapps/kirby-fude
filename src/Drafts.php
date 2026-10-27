<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use RuntimeException;

/**
 * Draft records: one JSON file per draft in `<storage>/fude/drafts/`.
 *
 * The record is what makes a publish safe: it carries the hash of the
 * published state the draft was based on, the confirmation token the
 * caller must present, and — for drafts that created a file or a page —
 * what to remove again on discard. The content itself lives in Kirby (the
 * `changes` version, or a draft page); the record only points at it.
 *
 * @author    Simon Räder <https://sayamaapps.de>
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright Simon Räder
 * @license   MIT
 */
class Drafts
{
	public const DEFAULT_LIFETIME = 48 * 3600;

	/** Refuses a draft older than its lifetime (`draftLifetime`, 48 hours by default). */
	public static function checkAge(App $kirby, array $record, \DateTimeImmutable $now): void
	{
		$lifetime = self::lifetime($kirby);
		if ($now->getTimestamp() - (int)($record['createdTs'] ?? 0) > $lifetime) {
			throw FudeError::t('draft.expired', ['hours' => (int)round($lifetime / 3600)], 410);
		}
	}

	/** Constant-time comparison of the confirmation token. */
	public static function checkToken(array $record, mixed $token): void
	{
		if (!is_string($token) || !hash_equals((string)$record['token'], $token)) {
			throw FudeError::t('draft.token', [], 403);
		}
	}

	/** Creates the record and returns it (with id and token). */
	public static function create(App $kirby, array $record, \DateTimeImmutable $now): array
	{
		$dir = self::directory($kirby);
		if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
			throw new RuntimeException('Cannot create ' . $dir);
		}
		$record['id'] = bin2hex(random_bytes(8));
		$record['token'] = bin2hex(random_bytes(16));
		$record['createdAt'] = $now->format(DATE_ATOM);
		$record['createdTs'] = $now->getTimestamp();
		self::write($kirby, $record);
		return $record;
	}

	/** Removes the record of a draft. A missing record is no error. */
	public static function delete(App $kirby, string $id): void
	{
		$file = self::directory($kirby) . '/' . $id . '.json';
		if (is_file($file)) {
			@unlink($file);
		}
	}

	/** The folder of the draft records. */
	public static function directory(App $kirby): string
	{
		return Options::directory($kirby) . '/drafts';
	}

	/** How long a draft stays publishable, in seconds. */
	public static function lifetime(App $kirby): int
	{
		$seconds = Options::get($kirby, 'draftLifetime', self::DEFAULT_LIFETIME);
		return is_int($seconds) && $seconds > 0 ? $seconds : self::DEFAULT_LIFETIME;
	}

	/** The record without the secret, for responses. */
	public static function public(array $record): array
	{
		unset($record['token']);
		return $record;
	}

	/** @throws FudeError 404 */
	public static function read(App $kirby, string $id): array
	{
		if (!preg_match('/^[0-9a-f]{16}$/D', $id)) {
			throw FudeError::t('draft.notFound', ['id' => $id], 404);
		}
		$file = self::directory($kirby) . '/' . $id . '.json';
		$json = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
		if (!is_array($json)) {
			throw FudeError::t('draft.notFound', ['id' => $id], 404);
		}
		return $json;
	}

	/** Stores the record of a draft as JSON. */
	public static function write(App $kirby, array $record): void
	{
		$file = self::directory($kirby) . '/' . $record['id'] . '.json';
		$json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false || @file_put_contents($file, $json, LOCK_EX) === false) {
			throw new RuntimeException('Cannot write ' . $file);
		}
	}
}
