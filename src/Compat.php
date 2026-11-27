<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\ModelWithContent;
use Throwable;

/**
 * The one place where Kirby 5 and Kirby 6 differ for Fude: how a blueprint
 * reads.
 *
 * Kirby 6 renames the field types `files`, `pages` and `users` to
 * `filepicker`, `pagepicker` and `userpicker`, turns the `pages` and `files`
 * sections into the fields `pagelist` and `filelist`, listed among the
 * blueprint's fields, and unwraps `fields` sections into their fields.
 * Under Kirby 6, type() gives a blueprint type its Kirby 5 name for the
 * checks, fields() leaves the list fields out and sections() lists them
 * as the sections they were. Under Kirby 5 a field type of those names
 * (from a plugin) is what it is. Messages keep the blueprint's own type name.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Compat
{
	/** Kirby 6 field types with the Kirby 5 type they replace. */
	private const KIRBY6_TYPES = [
		'filepicker' => 'files', 'pagepicker' => 'pages', 'userpicker' => 'users',
		'pagelist' => 'pages', 'filelist' => 'files',
	];

	/** Kirby 6 fields that are sections in Kirby 5. */
	private const LISTS = ['pagelist', 'filelist'];

	/** One blueprint field by name; null when there is none. */
	public static function field(ModelWithContent $model, string $name): array|null
	{
		$field = $model->blueprint()->field($name);
		return is_array($field) && !self::isList($field) ? $field : null;
	}

	/**
	 * The blueprint fields of a model, without the sections (Kirby 6 lists
	 * them among the fields).
	 *
	 * @return array<string|int, array>
	 */
	public static function fields(ModelWithContent $model): array
	{
		$fields = [];
		foreach ($model->blueprint()->fields() as $name => $definition) {
			$definition = (array)$definition;
			if (!self::isList($definition)) {
				$fields[$name] = $definition;
			}
		}
		return $fields;
	}

	/** Whether Kirby 6 runs: only Kirby 6 has the blueprint normalizer. */
	public static function isKirby6(): bool
	{
		return class_exists('Kirby\\Blueprint\\Normalizer');
	}

	private static function isList(array $definition): bool
	{
		return self::isKirby6() && in_array($definition['type'] ?? null, self::LISTS, true);
	}

	/**
	 * The sections of a model's blueprint (tabs, columns, sections as Kirby
	 * normalises them), as name => definition. Under Kirby 6 the list fields
	 * stand in for the `pages` and `files` sections (their type stays
	 * `pagelist`/`filelist`, see type()), and a marked `fields` section,
	 * which Kirby 6 unwraps, is read from the blueprint file.
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
					if (is_array($field) && self::isList($field)) {
						$sections[(string)$name] = $field;
					}
				}
			}
		}
		if (self::isKirby6()) {
			$sections += self::unwrappedSections($model);
		}
		return $sections;
	}

	/**
	 * The Kirby 5 name of a blueprint type, for comparing with Fude's type
	 * maps. Under Kirby 5 the type is returned as it is.
	 */
	public static function type(mixed $type): string
	{
		$type = is_string($type) ? $type : '';
		return self::isKirby6() ? (self::KIRBY6_TYPES[$type] ?? $type) : $type;
	}

	/** The name a blueprint uses for a Kirby 5 field type, for messages: `files` is `filepicker` under Kirby 6. */
	public static function typeName(string $kirby5Type): string
	{
		return self::isKirby6() ? ((string)array_search($kirby5Type, self::KIRBY6_TYPES, true) ?: $kirby5Type) : $kirby5Type;
	}

	/**
	 * The marked `fields` sections of the blueprint file, which Kirby 6
	 * unwraps and drops the marker of. Sections in other files (`extends`)
	 * are not followed.
	 *
	 * @return array<string, array>
	 */
	private static function unwrappedSections(ModelWithContent $model): array
	{
		$blueprint = $model->blueprint();
		try {
			$props = $blueprint::find($blueprint->name());
		} catch (Throwable) {
			return [];
		}
		// The file itself, its tabs, and the columns of both.
		$groups = [$props];
		foreach (['tabs', 'columns'] as $level) {
			foreach ($groups as $group) {
				foreach ((array)(is_array($group) ? ($group[$level] ?? []) : []) as $child) {
					$groups[] = $child;
				}
			}
		}
		$sections = [];
		foreach ($groups as $group) {
			foreach ((array)(is_array($group) ? ($group['sections'] ?? []) : []) as $name => $section) {
				if (is_array($section) && ($section['type'] ?? $name) === 'fields' && ($section[Blueprints::MARKER] ?? null) !== null) {
					$sections[(string)$name] = ['type' => 'fields', Blueprints::MARKER => $section[Blueprints::MARKER]];
				}
			}
		}
		return $sections;
	}
}
