<?php

use Kirby\Cms\App;
use Kirby\Cms\Language;
use Kirby\Cms\ModelWithContent;
use Kirby\Content\PlainTextStorage;
use Kirby\Content\VersionId;

/**
 * Test plugin, installed by fude_fixture(seam: true): the content storage of
 * every model runs the option `test.storage` before and after each write and
 * delete, if a test sets it. The callback gets ($when, $operation, $version,
 * $model) with $when "before" or "after", $operation "write" or "delete" and
 * $version "latest" or "changes". It can break the site, wait or throw.
 * Without the option the storage behaves like Kirby's own.
 */
class FudeSeamStorage extends PlainTextStorage
{
	public function delete(VersionId $versionId, Language $language): void
	{
		$this->seam('before', 'delete', $versionId);
		parent::delete($versionId, $language);
		$this->seam('after', 'delete', $versionId);
	}

	private function seam(string $when, string $operation, VersionId $versionId): void
	{
		$callback = $this->model->kirby()->option('test.storage');
		if (is_callable($callback)) {
			$callback($when, $operation, (string)$versionId, $this->model);
		}
	}

	protected function write(VersionId $versionId, Language $language, array $fields): void
	{
		$this->seam('before', 'write', $versionId);
		parent::write($versionId, $language, $fields);
		$this->seam('after', 'write', $versionId);
	}
}

App::plugin('test/storage-seam', [
	'components' => [
		'storage' => fn (App $kirby, ModelWithContent $model) => new FudeSeamStorage($model),
	],
]);
