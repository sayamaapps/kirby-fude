<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Toolkit\Str;
use RuntimeException;

/**
 * Takes a photo from the chat and stores it the way the blueprint wants it.
 *
 * The upload is identified by its content (getimagesize), never by the
 * name or the declared MIME type. It is resized to the declared maximum
 * width with Kirby's thumb component (GD/ImageMagick, whatever the site
 * uses) and only the resized copy becomes a Kirby file — no full-size
 * original ends up on the server. Formats outside the declaration are
 * refused.
 *
 * @package   Sayamaapps\Fude
 * @author    Simon Räder <https://sayamaapps.de>
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright Simon Räder
 * @license   MIT
 */
class Images
{
	public const DEFAULT_MAX_BYTES = 15 * 1024 * 1024;

	/** Width times height, checked from the header before GD decodes anything. */
	public const DEFAULT_MAX_PIXELS = 40_000_000;

	/** Formats a declaration may allow, by extension. */
	public const FORMATS = ['jpg', 'png', 'webp', 'gif'];
	private const MIME = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	];

	/**
	 * Checks an upload (one entry of $_FILES) against the rule and returns
	 * the detected format.
	 *
	 * @throws FudeError
	 */
	public static function check(App $kirby, array $upload, array $rule): string
	{
		$error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($error !== UPLOAD_ERR_OK) {
			throw FudeError::t('image.upload', ['code' => $error]);
		}
		$maxBytes = (int)Options::get($kirby, 'maxUploadBytes', self::DEFAULT_MAX_BYTES);
		$tmp = (string)($upload['tmp_name'] ?? '');
		if ($tmp === '' || !is_file($tmp)) {
			throw FudeError::t('image.upload', ['code' => 'missing']);
		}
		if (filesize($tmp) > $maxBytes) {
			throw FudeError::t('image.size', ['max' => round($maxBytes / 1048576, 1)]);
		}
		$info = @getimagesize($tmp);
		$format = self::MIME[$info['mime'] ?? ''] ?? null;
		if ($info === false || $format === null) {
			throw FudeError::t('image.unreadable');
		}
		$maxPixels = (int)Options::get($kirby, 'maxPixels', self::DEFAULT_MAX_PIXELS);
		if ($info[0] * $info[1] > $maxPixels) {
			throw FudeError::t('image.pixels', ['max' => $maxPixels]);
		}
		if (!in_array($format, $rule['formats'], true)) {
			throw FudeError::t('image.format', ['format' => $format, 'allowed' => implode(', ', $rule['formats'])]);
		}
		return $format;
	}

	/**
	 * Resizes the upload and creates the Kirby file on the page.
	 *
	 * @param $baseName text the filename is derived from (the entry's key)
	 */
	public static function store(App $kirby, Page $page, array $upload, array $rule, string $format, string $baseName, string $alt): File
	{
		$tmpDir = Options::directory($kirby) . '/tmp';
		if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
			throw new RuntimeException('Cannot create ' . $tmpDir);
		}
		// Capped: a key may be 500 characters, a filename must stay well
		// under the 255-byte limit of common filesystems.
		$slug = rtrim(mb_substr(Str::slug($baseName), 0, 60), '-') ?: 'bild';
		$filename = $slug . '-' . date('Ymd') . '-' . bin2hex(random_bytes(3)) . '.' . $format;
		$resized = $tmpDir . '/' . $filename;
		try {
			// Thumb options: only `width`, so the aspect ratio stays; Kirby
			// never upscales a smaller image. `format` keeps the format of
			// the upload — the extension of $resized says the same.
			$kirby->thumb((string)$upload['tmp_name'], $resized, [
				'width'   => $rule['maxWidth'],
				'format'  => $format,
				'quality' => 85,
			]);
			if (!is_file($resized)) {
				throw new RuntimeException('Thumb component wrote nothing');
			}
			$props = [
				'source'   => $resized,
				'filename' => $filename,
				'content'  => ['alt' => $alt],
			];
			if ($rule['template'] !== null) {
				$props['template'] = $rule['template'];
			}
			return $page->createFile($props, true);
		} finally {
			if (is_file($resized)) {
				@unlink($resized);
			}
		}
	}
}
