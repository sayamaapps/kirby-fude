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
	 * Index of the row (or the child page) identified by the key value: the
	 * text of a single-field key, or the part values of a composite key
	 * (field name => value, every part).
	 *
	 * @param string|array<string, string> $key
	 * @return int the row index, or for pages the index in rows()
	 * @throws FudeError 404 when missing, 409 when ambiguous
	 */
	public static function find(array $collection, array $rows, string|array $key): int
	{
		$wanted = is_array($key) ? $key : [self::keyFields($collection)[0] => $key];
		$matches = [];
		foreach ($rows as $index => $row) {
			if (self::sameParts($wanted, $row)) {
				$matches[] = $index;
			}
		}
		$label = self::keyLabel($collection);
		$text = is_array($key) ? self::keyText($collection, $wanted) : $key;
		if ($matches === []) {
			throw FudeError::t('collection.notFound', ['field' => $label, 'key' => $text], 404);
		}
		if (count($matches) > 1) {
			throw FudeError::t('collection.ambiguous', ['field' => $label, 'key' => $text], 409);
		}
		return $matches[0];
	}

	/**
	 * The field names of the collection's key: one, or several for a
	 * composite key. None for a single page.
	 *
	 * @return list<string>
	 */
	public static function keyFields(array $collection): array
	{
		return array_values((array)($collection['key'] ?? []));
	}

	/** What the replies call the key's fields: the label, or the labels of a composite key joined with " / ". */
	public static function keyLabel(array $collection): string
	{
		$labels = [];
		foreach (self::keyFields($collection) as $field) {
			$labels[] = $collection['fields'][$field]['label'] ?? $field;
		}
		return implode(' / ', $labels);
	}

	/**
	 * The key of a row (or of the part values a request names) as text: the
	 * trimmed value of a single-field key, the values of a composite key
	 * joined with " / ".
	 *
	 * @param array<string, mixed> $row
	 */
	public static function keyText(array $collection, array $row): string
	{
		$values = [];
		foreach (self::keyFields($collection) as $field) {
			$values[] = trim((string)($row[$field] ?? ''));
		}
		return implode(' / ', $values);
	}

	/**
	 * The key a record and the log carry for a row: the text of a single-field
	 * key, an object of field => value for a composite key (the form a
	 * request addresses the entry with).
	 *
	 * @param array<string, mixed> $row
	 * @return string|array<string, string>
	 */
	public static function keyOf(array $collection, array $row): string|array
	{
		$fields = self::keyFields($collection);
		if (count($fields) === 1) {
			return (string)($row[$fields[0]] ?? '');
		}
		$parts = [];
		foreach ($fields as $field) {
			$parts[$field] = (string)($row[$field] ?? '');
		}
		return $parts;
	}

	/** Whether two rows have the same key: every key part the same (case-insensitive, trimmed). */
	public static function sameEntry(array $collection, array $a, array $b): bool
	{
		$parts = [];
		foreach (self::keyFields($collection) as $field) {
			$parts[$field] = $b[$field] ?? '';
		}
		return self::sameParts($parts, $a);
	}

	/**
	 * @param array<string, mixed> $parts field name => key value
	 * @param array<string, mixed> $row
	 */
	private static function sameParts(array $parts, array $row): bool
	{
		foreach ($parts as $field => $value) {
			if (!self::sameKey($row[$field] ?? '', $value)) {
				return false;
			}
		}
		return true;
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
