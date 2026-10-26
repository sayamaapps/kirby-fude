<?php

use Kirby\Data\Data;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Image rules: formats outside the declaration refused, non-images refused,
 * too large refused, the stored file is resized to the declared width with
 * the declared template and an alt text, smaller images are not upscaled,
 * the row references the file so the Panel and the template find it, and
 * the file survives the publish. A collection without an image field takes
 * no upload.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ImageTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$draft = fn (array $files, array $fields = ['name' => 'Anna Neu'], string $collection = 'team', array $options = []) => fude_call($app, 'draft', ['collection' => $collection, 'fields' => $fields], files: $files, options: $options);

			[$code, $json] = $draft(['image' => fude_upload($tmp, 300, 200, 'gif')]);
			same([422, 'Image format "gif" is not allowed. Allowed: jpg, png.'], [$code, $json['error']], 'Format outside the declaration');
			[$code, $json] = $draft(['image' => fude_upload($tmp, 300, 200, 'webp')]);
			same(422, $code, 'webp refused for team (declared jpg, png)');
			$fake = $tmp . '/fake.jpg';
			file_put_contents($fake, 'not an image');
			[$code, $json] = $draft(['image' => ['name' => 'fake.jpg', 'type' => 'image/jpeg', 'tmp_name' => $fake, 'error' => 0, 'size' => 12]]);
			same([422, 'The file is not a readable image.'], [$code, $json['error']], 'Content decides, not the name or type');
			// Pixel limit: a valid 10x10 PNG whose header claims 8001x5001 is refused
			// before GD decodes it (default 40 000 000 pixels).
			$bomb = fude_upload($tmp, 10, 10);
			file_put_contents($bomb['tmp_name'], substr_replace((string)file_get_contents($bomb['tmp_name']), pack('NN', 8001, 5001), 16, 8));
			[$code, $json] = $draft(['image' => $bomb]);
			same([422, 'The image has more than 40000000 pixels.'], [$code, $json['error']], 'Pixel bomb refused by default');
			same([], glob($tmp . '/storage/fude/tmp/*') ?: [], 'Pixel bomb: nothing resized, nothing stored');
			same([], glob($tmp . '/content/standort/*.png') ?: [], 'Pixel bomb: no file on the page');
			[$code, $json] = $draft(['image' => fude_upload($tmp, 11, 10)], options: ['fude.maxPixels' => 100]);
			same([422, 'The image has more than 100 pixels.'], [$code, $json['error']], 'Option fude.maxPixels is read');
			[$code, $json] = $draft(['image' => ['name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0]]);
			same([422, 'The image upload failed (code 3).'], [$code, $json['error']], 'Upload error code');
			[$code, $json] = $draft(['image' => fude_upload($tmp, 900, 600)], options: ['fude.maxUploadBytes' => 1000]);
			same(422, $code, 'Too large');
			same(true, str_starts_with($json['error'], 'The image is larger than'), 'Size message', $json['error']);
			[$code, $json] = $draft(['image' => fude_upload($tmp, 300, 200)], ['titel' => 'Ohne Bild', 'text' => 'Text'], 'ohnebild');
			same([422, 'This collection takes no image.'], [$code, $json['error']], 'Collection without an image field takes no upload');
			[$code, $json] = $draft(['image' => fude_upload($tmp, 300, 200)], ['name' => 'X'], 'team', ['fude.config' => 'site/config/missing.php']);
			same([500, 'This site has no site/config/fude.php.'], [$code, $json['error']], 'Missing configuration file');
			same([], glob($tmp . '/content/standort/*.png') ?: [], 'No file stored by any refusal');
			same(false, $app()->page('standort')->version('changes')->exists(), 'No draft by any refusal');

			// A 300-character key: the filename stays short, the prefix is the slug.
			$long = str_repeat('anna ', 60);
			[$code, $json] = $draft(['image' => fude_upload($tmp, 300, 200, 'jpg')], ['name' => $long]);
			same(200, $code, 'Long key with image', $json);
			$name = $json['draft']['file'];
			preg_match('/^(.+)-\d{8}-[0-9a-f]{6}\.jpg$/', $name, $m);
			same(true, strlen($name) <= 100 && str_starts_with($name, 'anna-anna-anna-') && isset($m[1]) && strlen($m[1]) <= 60 && !str_ends_with($m[1], '-'), 'Long key: filename at most 100 bytes, slug cut to 60 without trailing dash', $name);
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);

			// The real upload: resized, template, alt, referenced, previewed.
			[$code, $json] = $draft(['image' => fude_upload($tmp, 2400, 1600, 'png')]);
			same(200, $code, 'Draft with image', $json);
			$filename = $json['draft']['file'];
			same(true, str_starts_with($filename, 'anna-neu-') && str_ends_with($filename, '.png'), 'Filename from the key', $filename);
			$file = $app()->page('standort')->file($filename);
			same([800, 533], [$file->width(), $file->height()], 'Resized to the declared maxWidth, ratio kept');
			same(['bild', 'Anna Neu'], [$file->template(), $file->alt()->value()], 'Template from the blueprint uploads, alt from the key field');
			$rows = Data::decode($app()->page('standort')->version('changes')->read()['team'], 'yaml');
			same(true, is_array($rows[2]['foto']) && count($rows[2]['foto']) === 1 && str_starts_with($rows[2]['foto'][0], 'file://'), 'Row references the file by UUID', $rows[2]['foto']);
			parse_str((string)parse_url($json['preview'], PHP_URL_QUERY), $query);
			$html = $app(['method' => 'GET', 'url' => 'http://example.invalid/standort', 'query' => $query])->page('standort')->render();
			same(true, str_contains($html, 'Anna Neu||' . '|' . $filename . ';'), 'Preview resolves the file through toFile()', $html);
			same(true, filesize($tmp . '/content/standort/' . $filename) < 200 * 1024, 'No full-size original stored');
			same([], glob($tmp . '/storage/fude/tmp/*') ?: [], 'Temporary resized copy removed');

			// Publish: the file stays, the row goes live with it.
			[$code] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same(200, $code, 'Published with image');
			$page = $app()->page('standort');
			same($filename, $page->team()->toStructure()->last()->foto()->toFile()?->filename(), 'Live row shows the file');
			same(true, is_file($tmp . '/content/standort/' . $filename), 'File still there after publish');

			// Smaller than maxWidth: not upscaled.
			[$code, $json] = $draft(['image' => fude_upload($tmp, 400, 300, 'jpg')], ['name' => 'Klein Bild']);
			same(200, $code, 'Small image', $json);
			$small = $app()->page('standort')->file($json['draft']['file']);
			same([400, 300, 'jpg'], [$small->width(), $small->height(), $small->extension()], 'Not upscaled, format kept');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same(null, $app()->page('standort')->file($json['draft']['file']), 'Discard removed the small image');

			// Update with an image replaces the reference; the old file is not touched.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'update', 'key' => 'Anna Neu', 'fields' => []], files: ['image' => fude_upload($tmp, 500, 500, 'jpg')]);
			same(200, $code, 'Update with only an image', $json);
			$rows = Data::decode($app()->page('standort')->version('changes')->read()['team'], 'yaml');
			same(['Anna Neu', 1], [$rows[2]['name'], count($rows[2]['foto'])], 'Reference replaced');
			same(true, is_file($tmp . '/content/standort/' . $filename), 'Old file kept (the Panel editor decides)');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
			same(true, is_file($tmp . '/content/standort/' . $filename), 'Discard removes only the file of the discarded draft');
			same(null, $app()->page('standort')->file($json['draft']['file']), 'New file removed');

			// Default formats and alt on the second collection, which declares neither.
			[$code, $json] = $draft(['image' => fude_upload($tmp, 1300, 400, 'webp')], ['titel' => 'Grippe', 'text' => 'Impfen!'], 'aktuelles');
			same(200, $code, 'webp allowed by default', $json);
			$file = $app()->page('home')->file($json['draft']['file']);
			same([1200, 'Grippe', 'bild'], [$file->width(), $file->alt()->value(), $file->template()], 'Width 1200, alt from the key, template from the blueprint');
			fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']]);
		} finally {
			cleanup($tmp);
		}
	}
}
