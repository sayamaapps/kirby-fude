<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Data\Data;

/**
 * Reads the published state of a collection and computes the hash that
 * guards against concurrent edits.
 *
 * Only the `latest` version counts as "published" here. The `changes`
 * version is Fude's own draft (or a Panel editor's unsaved work) and never
 * part of the hash.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Content
{
	/**
	 * Index of the row (or the child page) identified by the key value.
	 *
	 * @return int the row index, or for pages the index in rows()
	 * @throws FudeError 404 when missing, 409 when ambiguous
	 */
	public static function find(array $collection, array $rows, string $key): int
	{
		$keyField = $collection['key'];
		$matches = [];
		foreach ($rows as $index => $row) {
			if (self::sameKey($row[$keyField] ?? '', $key)) {
				$matches[] = $index;
			}
		}
		$label = $collection['fields'][$keyField]['label'] ?? $keyField;
		if ($matches === []) {
			throw FudeError::t('collection.notFound', ['field' => $label, 'key' => $key], 404);
		}
		if (count($matches) > 1) {
			throw FudeError::t('collection.ambiguous', ['field' => $label, 'key' => $key], 409);
		}
		return $matches[0];
	}

	/**
	 * Hash of the published state the draft is based on. If it changes
	 * between draft and publish, somebody edited in parallel (409).
	 */
	public static function hash(App $kirby, array $collection, Page|null $target = null): string
	{
		$page = self::page($kirby, $collection);
		if ($collection['kind'] === 'structure') {
			return hash('sha256', self::latestField($page, $collection['field']));
		}
		if ($collection['kind'] === 'page') {
			$target = $page;
		}
		if ($target !== null) {
			$content = $target->version('latest')->read() ?? [];
			unset($content['uuid'], $content['lock']);
			return hash('sha256', (string)json_encode($content, JSON_UNESCAPED_UNICODE));
		}
		return hash('sha256', implode("\n", $page->children()->keys()));
	}

	/** The stored (string) value of a field in the latest version, '' if unset. */
	public static function latestField(Page $page, string $field): string
	{
		$content = $page->version('latest')->read() ?? [];
		$value = $content[$field] ?? '';
		return is_string($value) ? $value : Data::encode($value, 'yaml');
	}

	/** The page that holds a structure collection, or the parent of a pages collection. */
	public static function page(App $kirby, array $collection): Page
	{
		$id = in_array($collection['kind'], ['structure', 'page'], true) ? $collection['page'] : $collection['parent'];
		$page = $kirby->site()->find($id);
		if (!$page instanceof Page) {
			throw FudeError::t('collection.page', ['name' => $collection['name']], 500);
		}
		return $page;
	}

	/**
	 * Published rows of a collection as plain arrays. Structure rows come
	 * from the latest version's YAML; pages become one row per child with
	 * `title` and the declared fields.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function rows(App $kirby, array $collection): array
	{
		$page = self::page($kirby, $collection);
		if ($collection['kind'] === 'structure') {
			return self::structureRows(self::latestField($page, $collection['field']));
		}
		if ($collection['kind'] === 'page') {
			// One row: the declared fields of the page, as published.
			$content = $page->version('latest')->read() ?? [];
			$row = [];
			foreach ($collection['fields'] as $fieldName => $field) {
				$row[$fieldName] = $content[$fieldName] ?? '';
			}
			return [$row];
		}
		$rows = [];
		foreach ($page->children()->listed()->merge($page->children()->unlisted()) as $child) {
			if ($child->intendedTemplate()->name() !== $collection['template']) {
				continue;
			}
			$content = $child->version('latest')->read() ?? [];
			$row = ['title' => (string)($content['title'] ?? $child->title()->value()), '_id' => $child->id()];
			foreach ($collection['fields'] as $fieldName => $field) {
				if ($fieldName === 'title') {
					continue;
				}
				$row[$fieldName] = $content[$fieldName] ?? '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** Case-insensitive, trimmed comparison of key values. */
	public static function sameKey(mixed $a, mixed $b): bool
	{
		return mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
	}

	/** @return list<array<string, mixed>> */
	public static function structureRows(string $yaml): array
	{
		if (trim($yaml) === '') {
			return [];
		}
		try {
			$rows = Data::decode($yaml, 'yaml');
		} catch (\Throwable) {
			return [];
		}
		/** @psalm-suppress RedundantCondition, TypeDoesNotContainType Data::decode() is typed as array, a YAML scalar still yields [] */
		return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
	}
}
