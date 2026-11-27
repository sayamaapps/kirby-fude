<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\ModelWithContent;

/**
 * The one place where Kirby 5 and Kirby 6 differ for Fude: how a blueprint
 * reads. Everything else in the plugin sees the blueprint as Kirby 5 shows it.
 *
 * Kirby 6 renames the field types `files`, `pages` and `users` to
 * `filepicker`, `pagepicker` and `userpicker`, and turns the `pages` and
 * `files` sections into the fields `pagelist` and `filelist`, listed among
 * the blueprint's fields. Here the picker types get their Kirby 5 names back,
 * the list fields leave the fields and come back as sections of type `pages`
 * and `files`. Under Kirby 5 nothing changes.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Compat
{
	/** Kirby 6 sections that are fields now, with their Kirby 5 section type. */
	private const LISTS = ['pagelist' => 'pages', 'filelist' => 'files'];

	/** Kirby 6 field types with their Kirby 5 name. */
	private const PICKERS = ['filepicker' => 'files', 'pagepicker' => 'pages', 'userpicker' => 'users'];

	/** A field definition with Kirby 5 type names, its sub-fields included. */
	private static function definition(array $definition): array
	{
		$type = $definition['type'] ?? null;
		if (is_string($type) && isset(self::PICKERS[$type])) {
			$definition['type'] = self::PICKERS[$type];
		}
		if (is_array($definition['fields'] ?? null)) {
			foreach ($definition['fields'] as $name => $sub) {
				if (is_array($sub)) {
					$definition['fields'][$name] = self::definition($sub);
				}
			}
		}
		return $definition;
	}

	/** One blueprint field by name, as Kirby 5 shows it; null when there is none. */
	public static function field(ModelWithContent $model, string $name): array|null
	{
		$field = $model->blueprint()->field($name);
		if (!is_array($field) || self::listType($field) !== null) {
			return null;
		}
		return self::definition($field);
	}

	/**
	 * The blueprint fields of a model, as Kirby 5 shows them: without the
	 * sections, with Kirby 5 type names down into structure sub-fields.
	 *
	 * @return array<string|int, array>
	 */
	public static function fields(ModelWithContent $model): array
	{
		$fields = [];
		foreach ($model->blueprint()->fields() as $name => $definition) {
			$definition = (array)$definition;
			if (self::listType($definition) === null) {
				$fields[$name] = self::definition($definition);
			}
		}
		return $fields;
	}

	/** The Kirby 5 section type of a Kirby 6 list field, null for any other field. */
	private static function listType(array $definition): string|null
	{
		$type = $definition['type'] ?? null;
		return is_string($type) ? (self::LISTS[$type] ?? null) : null;
	}

	/**
	 * The sections of a model's blueprint (tabs, columns, sections as Kirby
	 * normalises them), as name => definition. Kirby 6 has no sections
	 * left: its list fields stand in for the `pages` and `files` sections.
	 *
	 * @return array<string, array>
	 */
	public static function sections(ModelWithContent $model): array
	{
		$sections = [];
		foreach ((array)($model->blueprint()->toArray()['tabs'] ?? []) as $tab) {
			foreach ((array)(is_array($tab) ? ($tab['columns'] ?? []) : []) as $column) {
				if (!is_array($column)) {
					continue;
				}
				foreach ((array)($column['sections'] ?? []) as $name => $section) {
					if (is_array($section)) {
						$sections[(string)$name] = $section;
					}
				}
				foreach ((array)($column['fields'] ?? []) as $name => $field) {
					$type = is_array($field) ? self::listType($field) : null;
					if ($type !== null) {
						$sections[(string)$name] = ['type' => $type] + $field;
					}
				}
			}
		}
		return $sections;
	}
}
