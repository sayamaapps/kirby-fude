<?php

namespace Sayamaapps\Fude;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Collections derived from markers in the page blueprints, so a site does
 * not have to repeat them in site/config/fude.php:
 *
 *     fields:
 *       kurzname:
 *         type: text
 *         fude: true                     # a page field: one collection per page
 *       team:
 *         type: structure
 *         fude: {key: name, actions: [create, update]}   # a structure: one collection
 *         fields:
 *           name: { type: text }
 *           foto: { type: files, fude: {maxWidth: 800} }
 *           intern: { type: text, fude: false }          # excluded
 *
 * and on a `pages` section, for child pages: the section's page is the
 * parent, its template the template, and the marked fields of that
 * template's blueprint are the fields (one collection, not one per child):
 *
 *     sections:
 *       events:
 *         type: pages
 *         templates: [event]
 *         fude: {key: startdate}
 *
 * Returns raw declaration entries in the shape of site/config/fude.php;
 * Config::load() merges them with the file and normalises every entry, so
 * the validation stays in one place. Blueprint types that Fude cannot map
 * are skipped inside structures and rejected when marked.
 */
class Blueprints
{
	private const DEFAULT_ACTIONS = ['create', 'update', 'delete'];
	private const FIELD_OPTIONS = ['description', 'maxWidth', 'alt', 'formats', 'template'];
	private const IMAGE_OPTIONS = ['maxWidth', 'alt', 'formats', 'template'];
	public const MARKER = 'fude';
	private const PAGE_OPTIONS = ['description'];

	/** A child page is deleted only where the marker says so, as in fude.php. */
	private const SECTION_ACTIONS = ['create', 'update'];
	private const SECTION_OPTIONS = ['name', 'label', 'description', 'key', 'actions', 'status', 'template', 'readImages'];
	private const STRUCTURE_OPTIONS = ['name', 'label', 'description', 'key', 'actions', 'insert', 'maxEntries', 'touch', 'readImages'];

	/** Blueprint field types and the Fude type each one maps to. */
	private const TYPES = [
		'text' => 'text', 'slug' => 'text', 'tel' => 'text', 'email' => 'text', 'url' => 'text',
		'textarea' => 'textarea', 'writer' => 'textarea',
		'select' => 'select', 'radio' => 'select', 'toggles' => 'select',
		'files' => 'image', 'pages' => 'page',
		'date' => 'date', 'number' => 'number', 'time' => 'time', 'toggle' => 'toggle',
	];

	/** @param array<string, array> $collections */
	private static function add(array &$collections, array $collection): void
	{
		$name = $collection['name'];
		if (isset($collections[$name])) {
			throw self::invalid("two marked fields would both become the collection \"$name\": set fude: {name: …} on the structure field");
		}
		$collections[$name] = $collection;
	}

	/** @param array<string, mixed> $options */
	private static function checkOptions(array $options, array $allowed, string $where): void
	{
		foreach (array_keys($options) as $option) {
			if (!in_array($option, $allowed, true)) {
				throw self::invalid("$where: unknown fude option \"$option\"");
			}
		}
	}

	/**
	 * @return array<string, array> collection name => raw declaration entry
	 * @throws FudeError 500 for a marker that cannot be honoured
	 */
	public static function collections(App $kirby): array
	{
		$site = $kirby->site();
		foreach ($site->blueprint()->fields() as $name => $definition) {
			if ((self::marker((array)$definition) ?? false) !== false) {
				throw self::invalid("site fields are not supported (field \"$name\" in site.txt carries a fude marker)");
			}
		}
		foreach (self::sections($site->blueprint()->toArray()) as $name => $section) {
			if ((self::marker($section) ?? false) !== false) {
				throw self::invalid("site sections are not supported (section \"$name\" in site.yml carries a fude marker)");
			}
		}

		// Marked pages sections first. The template a section names is
		// described by that collection: its pages get no collection of their
		// own, so 30 events stay one collection and not 30.
		$collections = [];
		$claimed = [];
		foreach ($site->index() as $page) {
			foreach (self::sections($page->blueprint()->toArray()) as $sectionName => $section) {
				$marker = self::marker($section);
				if ($marker === null || $marker === false) {
					continue;
				}
				$collection = self::pages($page, $sectionName, $section, $marker);
				foreach ($collections as $other) {
					if ($other['parent'] === $collection['parent'] && $other['template'] === $collection['template']) {
						throw self::invalid('page "' . $page->id() . '": two marked sections would both reach the template "' . $collection['template'] . '"; mark one');
					}
				}
				self::add($collections, $collection);
				$claimed[$collection['template']] = true;
			}
		}

		foreach ($site->index() as $page) {
			if (isset($claimed[$page->intendedTemplate()->name()])) {
				continue;
			}
			$pageFields = [];
			foreach ($page->blueprint()->fields() as $fieldName => $definition) {
				// Kirby keeps the blueprint's spelling ("leitsatzHervor"); content keys are lower case.
				$fieldName = strtolower((string)$fieldName);
				$definition = (array)$definition;
				$marker = self::marker($definition);
				if ($marker === null || $marker === false) {
					continue;
				}
				$where = 'page "' . $page->id() . '", field "' . $fieldName . '"';
				if (($definition['type'] ?? null) === 'structure') {
					self::add($collections, self::structure($page, (string)$fieldName, $definition, $marker));
					continue;
				}
				self::checkOptions($marker, self::PAGE_OPTIONS, $where);
				$pageFields[(string)$fieldName] = [
					'type'        => self::fudeType($where, (string)($definition['type'] ?? ''), false),
					'description' => self::translated($marker['description'] ?? null, $where, 'description') ?? Config::helpText($definition),
				];
			}
			if ($pageFields !== []) {
				$title = (string)$page->title()->value();
				$collection = [
					'name'        => str_replace('/', '-', $page->id()),
					'kind'        => 'page',
					'page'        => $page->id(),
					'label'       => $title !== '' ? $title : $page->id(),
					'description' => 'Page "' . ($title !== '' ? $title : $page->id()) . '"',
					'actions'     => ['update'],
					'fields'      => $pageFields,
				];
				self::add($collections, $collection);
			}
		}
		return $collections;
	}

	/**
	 * The rule of a field that joins a structure or pages collection: its
	 * Fude type, description and image options.
	 *
	 * @return array<string, mixed>
	 */
	private static function field(string $where, string $blueprintType, array $definition, array $marker): array
	{
		$type = self::fudeType($where, $blueprintType, true);
		self::checkOptions($marker, self::FIELD_OPTIONS, $where);
		$rule = [
			'type'        => $type,
			'description' => self::translated($marker['description'] ?? null, $where, 'description') ?? Config::helpText($definition),
		];
		if ($type !== 'image') {
			$only = array_intersect(self::IMAGE_OPTIONS, array_keys($marker));
			if ($only !== []) {
				throw self::invalid("$where: " . implode(', ', $only) . ' is only for files fields');
			}
		}
		foreach (self::IMAGE_OPTIONS as $option) {
			if (array_key_exists($option, $marker)) {
				$rule[$option] = $marker[$option];
			}
		}
		return $rule;
	}

	/**
	 * The Fude type of a blueprint type. Files are images only in a
	 * structure or pages collection: a page collection has no images.
	 */
	private static function fudeType(string $where, string $blueprintType, bool $images): string
	{
		$type = self::TYPES[$blueprintType] ?? null;
		if ($type === null) {
			throw self::invalid("$where: type \"$blueprintType\" is not supported by fude");
		}
		if ($type === 'image' && !$images) {
			throw self::invalid("$where: files fields are only supported inside a structure or a marked pages section's template");
		}
		return $type;
	}

	private static function invalid(string $reason): FudeError
	{
		return FudeError::t('config.invalid', ['reason' => $reason], 500);
	}

	/**
	 * The marker of a blueprint field: null when absent (or null), false for
	 * an explicit `fude: false`, otherwise its options (`true` is `[]`).
	 */
	private static function marker(array $definition): array|false|null
	{
		$marker = $definition[self::MARKER] ?? null;
		if ($marker === null || $marker === false) {
			return $marker;
		}
		if ($marker === true) {
			return [];
		}
		if (is_array($marker)) {
			return $marker;
		}
		throw self::invalid('the fude marker must be true, false or an object, not ' . get_debug_type($marker));
	}

	/**
	 * One pages collection from a marked `pages` section: the children of
	 * $page with the section's template, the fields from the marked fields
	 * of that template's blueprint, plus the title.
	 *
	 * @return array<string, mixed>
	 */
	private static function pages(Page $page, string $section, array $definition, array $marker): array
	{
		$where = 'page "' . $page->id() . '", section "' . $section . '"';
		if (($definition['type'] ?? null) !== 'pages') {
			throw self::invalid("$where: the fude marker is only for pages sections and fields");
		}
		self::checkOptions($marker, self::SECTION_OPTIONS, $where);
		foreach (['parent', 'query'] as $option) {
			if (($definition[$option] ?? null) !== null) {
				throw self::invalid("$where: a marked pages section lists the page's own children, without \"$option\"");
			}
		}

		$templates = array_values(array_map('strval', (array)($definition['templates'] ?? $definition['template'] ?? [])));
		$template = self::text($marker['template'] ?? null, $where, 'template');
		if ($template === null) {
			if (count($templates) !== 1) {
				throw self::invalid("$where: the section names " . ($templates === [] ? 'no template' : 'several templates') . '; set fude: {template: …}');
			}
			$template = $templates[0];
		} elseif ($templates !== [] && !in_array($template, $templates, true)) {
			throw self::invalid("$where: template \"$template\" is not one of the section's templates");
		}

		// The child blueprint, read from an unsaved page as Config does it.
		$probe = Page::factory(['slug' => 'fude-probe', 'template' => $template, 'parent' => $page, 'isDraft' => true]);
		$fields = ['title' => ['type' => 'text', 'description' => '']];
		foreach ($probe->blueprint()->fields() as $fieldName => $field) {
			$fieldName = strtolower((string)$fieldName);
			$field = (array)$field;
			$fieldMarker = self::marker($field);
			if ($fieldMarker === null || $fieldMarker === false || $fieldName === 'title') {
				continue;
			}
			$fieldWhere = 'template "' . $template . '", field "' . $fieldName . '"';
			$fields[$fieldName] = self::field($fieldWhere, (string)($field['type'] ?? ''), $field, $fieldMarker);
		}

		$name = self::text($marker['name'] ?? null, $where, 'name')
			?? strtolower(str_replace('/', '-', $page->id()) . '-' . $section);
		$key = self::text($marker['key'] ?? null, $where, 'key');
		$collection = [
			'name'        => $name,
			'kind'        => 'pages',
			'parent'      => $page->id(),
			'template'    => $template,
			'label'       => self::translated($marker['label'] ?? null, $where, 'label') ?? Config::label($definition) ?? $section,
			'description' => self::translated($marker['description'] ?? null, $where, 'description') ?? Config::helpText($definition),
			'actions'     => $marker['actions'] ?? self::SECTION_ACTIONS,
			'key'         => $key === null ? 'title' : strtolower($key),
			'fields'      => $fields,
		];
		// Checked by Config like the same options in site/config/fude.php.
		foreach (['status', 'readImages'] as $option) {
			if (array_key_exists($option, $marker)) {
				$collection[$option] = $marker[$option];
			}
		}
		return $collection;
	}

	/**
	 * The sections of a blueprint (tabs, columns, sections as Kirby
	 * normalises them), as name => definition.
	 *
	 * @return array<string, array>
	 */
	private static function sections(array $blueprint): array
	{
		$sections = [];
		foreach ((array)($blueprint['tabs'] ?? []) as $tab) {
			foreach ((array)(is_array($tab) ? ($tab['columns'] ?? []) : []) as $column) {
				foreach ((array)(is_array($column) ? ($column['sections'] ?? []) : []) as $name => $section) {
					if (is_array($section)) {
						$sections[(string)$name] = $section;
					}
				}
			}
		}
		return $sections;
	}

	/** @return array<string, mixed> one structure collection, its sub-fields mapped */
	private static function structure(Page $page, string $field, array $definition, array $marker): array
	{
		$where = 'page "' . $page->id() . '", field "' . $field . '"';
		self::checkOptions($marker, self::STRUCTURE_OPTIONS, $where);

		$fields = [];
		foreach ((array)($definition['fields'] ?? []) as $subName => $sub) {
			$subName = strtolower((string)$subName);
			$sub = (array)$sub;
			$subWhere = $where . ', sub-field "' . $subName . '"';
			$subMarker = self::marker($sub);
			if ($subMarker === false) {
				continue;
			}
			$subType = (string)($sub['type'] ?? '');
			if (!isset(self::TYPES[$subType])) {
				if ($subMarker !== null) {
					throw self::invalid("$subWhere: type \"$subType\" is not supported by fude");
				}
				continue; // unmapped and unmarked: not part of the declaration
			}
			if ($subType === 'pages' && $subMarker === null) {
				// Only when marked: Fude sets one page out of a query, which an
				// unmarked pages field need not be.
				continue;
			}
			$fields[$subName] = self::field($subWhere, $subType, $sub, $subMarker ?? []);
		}

		$name = self::text($marker['name'] ?? null, $where, 'name')
			?? strtolower(str_replace('/', '-', $page->id()) . '-' . $field);
		$key = self::text($marker['key'] ?? null, $where, 'key');
		if ($key === null) {
			// The first text field identifies a row, as in Config.
			foreach ($fields as $fieldName => $rule) {
				if ($rule['type'] === 'text') {
					$key = $fieldName;
					break;
				}
			}
		}
		$label = self::translated($marker['label'] ?? null, $where, 'label') ?? Config::label($definition) ?? $field;

		$collection = [
			'name'        => $name,
			'kind'        => 'structure',
			'page'        => $page->id(),
			'field'       => $field,
			'label'       => $label,
			'description' => self::translated($marker['description'] ?? null, $where, 'description') ?? Config::helpText($definition),
			'actions'     => $marker['actions'] ?? self::DEFAULT_ACTIONS,
			'key'         => $key,
			'fields'      => $fields,
		];
		// Checked by Config like the same options in site/config/fude.php.
		foreach (['insert', 'maxEntries', 'touch', 'readImages'] as $option) {
			if (array_key_exists($option, $marker)) {
				$collection[$option] = $marker[$option];
			}
		}
		return $collection;
	}

	private static function text(mixed $value, string $where, string $option): string|null
	{
		if ($value === null || is_string($value)) {
			return $value;
		}
		throw self::invalid("$where: fude option \"$option\" must be a text");
	}

	/**
	 * A marker text that may also be a translation array or a translation key
	 * (plugin blueprints): the reply language, else English, else the first.
	 * A key reads as its text in the reply language.
	 */
	private static function translated(mixed $value, string $where, string $option): string|null
	{
		if (is_array($value) && $value !== [] && !array_is_list($value)) {
			$value = $value[I18n::language()] ?? $value['en'] ?? reset($value);
		}
		if (is_string($value)) {
			$value = Config::translateKey($value);
		}
		return self::text($value, $where, $option);
	}
}
