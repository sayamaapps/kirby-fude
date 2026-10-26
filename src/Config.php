<?php

namespace Fude;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\Pages;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Field\FieldOptions;
use Kirby\Form\Form;

/**
 * The declaration of what the bot may change: `site/config/fude.php`.
 *
 *     return [
 *         'collections' => [
 *             'team' => [
 *                 'kind'    => 'structure',       // rows of a structure field on one page
 *                 'page'    => 'standort',              // page id
 *                 'field'   => 'team',            // structure field name in that page's blueprint
 *                 'key'     => 'name',            // field that identifies a row (for updates)
 *                 'label'   => 'Team',
 *                 'description' => 'Who works at the practice.',
 *                 'actions' => ['create', 'update'],
 *                 'fields'  => [
 *                     'name'  => ['type' => 'text', 'required' => true],
 *                     'rolle' => ['type' => 'text'],
 *                     'foto'  => ['type' => 'image', 'maxWidth' => 1200, 'formats' => ['jpg', 'png'], 'template' => 'bild', 'alt' => 'name'],
 *                 ],
 *                 'publishRoles' => ['fude'],
 *             ],
 *             'news' => [
 *                 'kind'     => 'pages',          // child pages of one parent
 *                 'parent'   => 'aktuelles',
 *                 'template' => 'note',
 *                 'status'   => 'listed',         // status after publishing a created page
 *                 'key'      => 'title',
 *                 'fields'   => ['title' => ['type' => 'text', 'required' => true], 'text' => ['type' => 'textarea']],
 *             ],
 *         ],
 *     ];
 *
 * Everything not declared here is unreachable through /fude/*, whatever a
 * message says. Declared fields are additionally checked against the page
 * blueprint (Config::blueprintFields()): a field that the blueprint does not
 * know, or knows with an incompatible type, makes the configuration invalid
 * — loudly, at every request, not silently at publish time.
 */
final class Config
{
	public const ACTIONS = ['create', 'update', 'delete', 'replace'];

	/** Blueprint field types that may back each declared type. */
	private const COMPATIBLE = [
		'text'     => ['text', 'slug', 'tel', 'email', 'url'],
		'textarea' => ['textarea', 'writer', 'text'],
		'select'   => ['select', 'radio', 'toggles'],
		'image'    => ['files'],
		'date'     => ['date'],
		'number'   => ['number'],
		'time'     => ['time'],
		'toggle'   => ['toggle'],
		'page'     => ['pages'],
	];

	/** What a collection may do when its declaration names no actions. */
	public const DEFAULT_ACTIONS = ['create', 'update'];
	public const DEFAULT_FORMATS = ['jpg', 'png', 'webp'];
	public const DEFAULT_MAX_WIDTH = 1600;

	/** Blueprint types that give a text field a format, checked by the Validator. */
	public const FORMATS = ['url', 'email', 'tel'];

	/** Field types that may identify a row (the key). */
	public const KEY_TYPES = ['text', 'select', 'date'];
	public const KINDS = ['structure', 'pages', 'page'];
	public const TYPES = ['text', 'textarea', 'select', 'image', 'date', 'number', 'time', 'toggle', 'page'];

	/** Days an editable touch may default to instead of today (option `default`), Monday first. */
	public const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

	/**
	 * The blueprint fields behind a collection: the structure field's
	 * sub-fields, or the page blueprint's fields for a page or pages collection.
	 *
	 * @return array<string, array> field name => blueprint definition
	 */
	public static function blueprintFields(App $kirby, array $collection): array
	{
		$page = self::model($kirby, $collection);
		if ($collection['kind'] === 'structure') {
			$field = $page->blueprint()->field($collection['field']);
			if (($field['type'] ?? null) !== 'structure') {
				throw self::invalid("collection \"{$collection['name']}\": \"{$collection['field']}\" is not a structure field of \"{$collection['page']}\"");
			}
			$fields = [];
			foreach ((array)($field['fields'] ?? []) as $subName => $sub) {
				$fields[strtolower((string)$subName)] = (array)$sub;
			}
			return $fields;
		}
		$fields = [];
		foreach ($page->blueprint()->fields() as $fieldName => $definition) {
			$fields[strtolower((string)$fieldName)] = (array)$definition;
		}
		if ($collection['kind'] === 'pages') {
			$fields['title'] ??= ['type' => 'text', 'required' => true, 'label' => 'Title'];
		}
		return $fields;
	}

	/**
	 * A blueprint field's `when`, from the bot's side: true when the field is
	 * always shown, false when never, otherwise the conditions on fields of
	 * the collection, as stored values (`{"online": "true"}`). A condition on
	 * a field the collection does not declare is read from that field's
	 * blueprint default: the bot cannot change it, and a new child page gets
	 * it. Kirby skips its own check of a hidden field (Form\Mixin\When).
	 *
	 * @param array<string, array> $blueprint
	 * @return bool|array<string, string>
	 */
	private static function condition(array $collection, array $blueprint, array $definition): bool|array
	{
		$when = $definition['when'] ?? null;
		if (!is_array($when) || $when === []) {
			return true;
		}
		$depends = [];
		foreach ($when as $other => $expected) {
			$other = strtolower((string)$other);
			$expected = self::stored($expected);
			if (isset($collection['fields'][$other])) {
				$depends[$other] = $expected;
				continue;
			}
			$default = self::stored($blueprint[$other]['default'] ?? '');
			if ($default === '' && ($blueprint[$other]['type'] ?? null) === 'toggle') {
				$default = 'false'; // a toggle without a default is off
			}
			if ($default !== $expected) {
				return false;
			}
		}
		return $depends === [] ? true : $depends;
	}

	/**
	 * The published entries of a collection, reduced to the declared text
	 * fields. Images appear as `true`/`false` (present or not).
	 */
	public static function entries(App $kirby, array $collection): array
	{
		$entries = [];
		foreach (Content::rows($kirby, $collection) as $row) {
			$entry = [];
			foreach ($collection['fields'] as $fieldName => $field) {
				$value = $row[$fieldName] ?? null;
				if ($field['type'] === 'image') {
					$entry[$fieldName] = $value !== null && $value !== '' && $value !== [];
				} elseif ($field['type'] === 'page') {
					$entry[$fieldName] = self::pageValue($kirby, $field, $value);
				} elseif (is_bool($value)) {
					// A toggle as Kirby saves it: "true" or "false".
					$entry[$fieldName] = $value ? 'true' : 'false';
				} else {
					$entry[$fieldName] = is_scalar($value) ? (string)$value : '';
				}
			}
			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * The help text of a blueprint field, as plain text on one line. A
	 * translation array or a translation key is read in the reply language,
	 * like a label. Empty when there is no help.
	 */
	public static function helpText(array $definition): string
	{
		$help = $definition['help'] ?? '';
		if (is_array($help) && $help !== []) {
			$help = $help[I18n::language()] ?? $help['en'] ?? reset($help);
		}
		if (!is_string($help)) {
			return '';
		}
		return trim((string)preg_replace('/\s+/u', ' ', strip_tags(self::translateKey($help))));
	}

	private static function invalid(string $reason): FudeError
	{
		return FudeError::t('config.invalid', ['reason' => $reason], 500);
	}

	/** Whether a select's options come from a query, read the way Kirby reads them. */
	private static function isQuery(array $definition): bool
	{
		return (FieldOptions::polyfill($definition)['options']['type'] ?? null) === 'query';
	}

	/**
	 * The label of a blueprint field: a plain text, a translation array, or a
	 * translation key (plugin blueprints). Null when there is none.
	 */
	public static function label(array $definition): string|null
	{
		$label = $definition['label'] ?? null;
		if (is_array($label)) {
			$label = $label[I18n::language()] ?? $label['en'] ?? reset($label);
		} elseif (is_string($label)) {
			// A translation key (plugin blueprints): Kirby translates field
			// labels only in the Panel form, not in the blueprint.
			$label = self::translateKey($label);
		}
		return is_string($label) && $label !== '' ? $label : null;
	}

	/**
	 * Loads and normalises the configuration. Throws FudeError (500) when
	 * the file is missing or invalid.
	 *
	 * @return array<string, array> collection name => normalised collection
	 */
	public static function load(App $kirby): array
	{
		$file = Options::configFile($kirby);
		$declared = [];
		if (is_file($file)) {
			try {
				$raw = require $file;
			} catch (\Throwable $e) {
				throw FudeError::t('config.invalid', ['reason' => $e->getMessage()], 500);
			}
			if (!is_array($raw)) {
				throw self::invalid('no "collections"');
			}
			if (isset($raw['collections']) && !is_array($raw['collections'])) {
				throw self::invalid('"collections" must be an array');
			}
			$declared = $raw['collections'] ?? [];
		}

		// Derived from the blueprint markers first; fude.php overrides by name:
		// false removes a derived collection, an array replaces it, others are added.
		$derived = Options::get($kirby, 'blueprints', true) === false ? [] : Blueprints::collections($kirby);
		$entries = $derived;
		foreach ($declared as $name => $entry) {
			if ($entry === false) {
				unset($entries[$name]);
				continue;
			}
			$entries[$name] = $entry;
			// A pages collection declared for the parent and template of a
			// marked pages section stands in for it under its own name, so a
			// site that declared the children before keeps one collection.
			if (is_array($entry) && ($entry['kind'] ?? null) === 'pages') {
				foreach ($derived as $derivedName => $collection) {
					if ($derivedName !== $name && !array_key_exists($derivedName, $declared) && $collection['kind'] === 'pages' &&
						$collection['parent'] === ($entry['parent'] ?? null) && $collection['template'] === ($entry['template'] ?? null)) {
						unset($entries[$derivedName]);
					}
				}
			}
		}
		if ($entries === [] && !is_file($file)) {
			throw FudeError::t('config.missing', [], 500);
		}
		if ($entries === []) {
			throw self::invalid('no collections: mark fields with `fude: true` in a blueprint or declare them in site/config/fude.php');
		}
		$max = Options::get($kirby, 'maxCollections', 30);
		if (!is_int($max) || $max < 1) {
			throw self::invalid('fude.maxCollections must be a positive integer');
		}
		if (count($entries) > $max) {
			throw self::invalid(count($entries) . ' collections, at most ' . $max . ' (fude.maxCollections; the bot reads them all)');
		}

		$collections = [];
		foreach ($entries as $name => $entry) {
			if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $name)) {
				throw self::invalid('collection name "' . $name . '" (a-z, 0-9, -, _)');
			}
			$collections[$name] = self::normalise($kirby, $name, is_array($entry) ? $entry : []);
		}
		return $collections;
	}

	/** @param array<string, array> $blueprint */
	private static function mergeBlueprint(App $kirby, array $collection, array $blueprint): array
	{
		$fields = [];
		foreach ($collection['fields'] as $name => $field) {
			$definition = $blueprint[$name] ?? null;
			if ($definition === null) {
				throw self::invalid("collection \"{$collection['name']}\": field \"$name\" is not in the blueprint");
			}
			$blueprintType = (string)($definition['type'] ?? '');
			if (!in_array($blueprintType, self::COMPATIBLE[$field['type']], true)) {
				throw self::invalid("collection \"{$collection['name']}\": field \"$name\" is declared as {$field['type']} but the blueprint says $blueprintType");
			}
			$field['label'] = self::label($definition) ?? ucfirst($name);
			if ($field['type'] === 'text' && in_array($blueprintType, self::FORMATS, true)) {
				// The blueprint's own format: Validator checks it before the Panel rules do.
				$field['format'] = $blueprintType;
			}
			// A new child page gets the blueprint's defaults (Page::create), so a
			// required field with a default needs no value from the bot there.
			$defaulted = $collection['kind'] === 'pages' && ($definition['default'] ?? '') !== '' && ($definition['default'] ?? []) !== [];
			// A blueprint `when` makes a required field required only while it is shown.
			$condition = self::condition($collection, $blueprint, $definition);
			if (($definition['required'] ?? false) === true && !$defaulted && !$field['required']) {
				if ($condition === true) {
					$field['required'] = true;
				} elseif (is_array($condition)) {
					$field['requiredWhen'] = $condition;
				}
			}
			// `fudeAsk: true` at a blueprint field: the bot asks for it before the
			// first draft and takes "don't know". A required field needs no asking.
			if (isset($definition['fudeAsk']) && !is_bool($definition['fudeAsk'])) {
				throw self::invalid("collection \"{$collection['name']}\": field \"$name\" fudeAsk must be true or false");
			}
			$ask = $field['ask'] || ($definition['fudeAsk'] ?? false) === true;
			$field['ask'] = $ask && $condition !== false && !$field['required'] && !isset($field['requiredWhen']);
			if ($field['ask'] && is_array($condition)) {
				$field['askWhen'] = $condition;
			}
			$shown = self::shownWhen($blueprint, $definition);
			if ($shown !== []) {
				$field['shownWhen'] = $shown;
			}
			if (is_int($definition['maxlength'] ?? null) && $definition['maxlength'] > 0) {
				$field['maxlength'] = isset($field['maxlength']) ? min($field['maxlength'], $definition['maxlength']) : $definition['maxlength'];
			}
			if ($field['type'] === 'select') {
				if (self::isQuery($definition)) {
					$blueprintOptions = self::queryValues($kirby, $collection, $name, $definition);
					if ($blueprintOptions === []) {
						// A query that yields nothing (a site without separate areas): the field is
						// left out, so the schema does not list it. A field that cannot be left out fails.
						if ($field['required'] || $name === $collection['key']) {
							throw self::invalid("collection \"{$collection['name']}\": field \"$name\" has no options");
						}
						if ($field['options'] !== []) {
							throw self::invalid("collection \"{$collection['name']}\": field \"$name\" declares options the blueprint does not have");
						}
						continue;
					}
				} else {
					$blueprintOptions = self::selectOptions($definition);
				}
				if ($field['options'] === []) {
					$field['options'] = $blueprintOptions;
				} elseif ($blueprintOptions !== [] && array_diff($field['options'], $blueprintOptions) !== []) {
					throw self::invalid("collection \"{$collection['name']}\": field \"$name\" declares options the blueprint does not have");
				}
				if ($field['options'] === []) {
					throw self::invalid("collection \"{$collection['name']}\": field \"$name\" has no options");
				}
			}
			if ($field['type'] === 'page') {
				$targets = self::pageTargets($kirby, $collection, $name, $definition);
				if ($targets === []) {
					// No page to choose yet (no venue created so far): the field is left
					// out, required or not, so content never breaks the configuration.
					// Kirby's own check still refuses a draft that needs the field.
					continue;
				}
				$field['options'] = array_keys($targets);
				$field['pages'] = $targets;
				$field['store'] = strtolower((string)($definition['store'] ?? 'uuid')) === 'id' ? 'id' : 'uuid';
			}
			if ($field['type'] === 'number') {
				// The tighter bound of declaration and blueprint wins, as with maxlength.
				$min = $definition['min'] ?? null;
				if (is_int($min) || is_float($min)) {
					$field['min'] = isset($field['min']) ? max($field['min'], $min) : $min;
				}
				$max = $definition['max'] ?? null;
				if (is_int($max) || is_float($max)) {
					$field['max'] = isset($field['max']) ? min($field['max'], $max) : $max;
				}
				if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
					throw self::invalid("collection \"{$collection['name']}\": field \"$name\" min is above max (with the blueprint)");
				}
			}
			if ($field['type'] === 'image') {
				$uploads = $definition['uploads'] ?? null;
				$template = is_array($uploads) ? ($uploads['template'] ?? null) : (is_string($uploads) ? $uploads : null);
				$field['template'] ??= is_string($template) ? $template : null;
			}
			$fields[$name] = $field;
		}
		return $fields;
	}

	/**
	 * The page a collection's blueprint and queries belong to: the page of a
	 * page or structure collection. For a pages collection, an unsaved probe
	 * page of the template: only its blueprint is read from it, and its
	 * content is empty, so a query over it finds nothing.
	 */
	private static function model(App $kirby, array $collection): Page
	{
		if ($collection['kind'] === 'pages') {
			$parent = $kirby->site()->find($collection['parent']);
			if (!$parent instanceof Page) {
				throw self::invalid("collection \"{$collection['name']}\": parent \"{$collection['parent']}\" does not exist");
			}
			return Page::factory(['slug' => 'fude-probe', 'template' => $collection['template'], 'parent' => $parent, 'isDraft' => true]);
		}
		$page = $kirby->site()->find($collection['page']);
		if (!$page instanceof Page) {
			throw self::invalid("collection \"{$collection['name']}\": page \"{$collection['page']}\" does not exist");
		}
		return $page;
	}

	private static function normalise(App $kirby, string $name, array $entry): array
	{
		$kind = $entry['kind'] ?? 'structure';
		if (!in_array($kind, self::KINDS, true)) {
			throw self::invalid("collection \"$name\": unknown kind \"$kind\"");
		}
		$defaultActions = $kind === 'page' ? ['update'] : self::DEFAULT_ACTIONS;
		$actions = array_values(array_unique(array_map('strval', (array)($entry['actions'] ?? $defaultActions))));
		foreach ($actions as $action) {
			if (!in_array($action, self::ACTIONS, true)) {
				throw self::invalid("collection \"$name\": unknown action \"$action\"");
			}
		}
		if ($actions === []) {
			throw self::invalid("collection \"$name\": no actions");
		}
		if ($kind === 'page' && $actions !== ['update']) {
			throw self::invalid("collection \"$name\": kind page allows only the action update");
		}
		if (in_array('replace', $actions, true) && $kind !== 'structure') {
			throw self::invalid("collection \"$name\": replace is only for structure collections");
		}
		if (!is_array($entry['fields'] ?? null) || $entry['fields'] === []) {
			throw self::invalid("collection \"$name\": no fields");
		}

		$collection = [
			'name'         => $name,
			'kind'         => $kind,
			'label'        => is_string($entry['label'] ?? null) ? $entry['label'] : ucfirst($name),
			'description'  => is_string($entry['description'] ?? null) ? $entry['description'] : '',
			'actions'      => $actions,
			'publishRoles' => array_values(array_map('strval', (array)($entry['publishRoles'] ?? [Service::ROLE]))),
			'fields'       => [],
		];
		if (isset($entry['maxEntries'])) {
			if ($kind !== 'structure') {
				throw self::invalid("collection \"$name\": maxEntries is only for structure collections");
			}
			if (!is_int($entry['maxEntries']) || $entry['maxEntries'] < 1) {
				throw self::invalid("collection \"$name\": maxEntries must be a positive integer");
			}
			if ($entry['maxEntries'] > Service::MAX_REPLACE_ENTRIES) {
				throw self::invalid("collection \"$name\": maxEntries must be at most " . Service::MAX_REPLACE_ENTRIES);
			}
			$collection['maxEntries'] = $entry['maxEntries'];
		}
		if (isset($entry['insert'])) {
			if ($kind !== 'structure') {
				throw self::invalid("collection \"$name\": insert is only for structure collections");
			}
			if (!in_array($entry['insert'], ['top', 'bottom'], true)) {
				throw self::invalid("collection \"$name\": insert must be \"top\" or \"bottom\"");
			}
			$collection['insert'] = $entry['insert'];
		}
		// A declaration for the chat side: the content of an attached photo may
		// be read by the model. The plugin itself does nothing else with it.
		$readImages = $entry['readImages'] ?? false;
		if (!is_bool($readImages)) {
			throw self::invalid("collection \"$name\": readImages must be true or false");
		}
		$collection['readImages'] = $readImages;

		if ($kind === 'page') {
			// The collection is one page; the page id is all it needs.
			if (!is_string($entry['page'] ?? null) || $entry['page'] === '') {
				throw self::invalid("collection \"$name\": \"page\" missing");
			}
			if (isset($entry['key'])) {
				throw self::invalid("collection \"$name\": kind page has no key");
			}
			$collection['page'] = $entry['page'];
		} elseif ($kind === 'structure') {
			foreach (['page', 'field'] as $required) {
				if (!is_string($entry[$required] ?? null) || $entry[$required] === '') {
					throw self::invalid("collection \"$name\": \"$required\" missing");
				}
			}
			$collection['page'] = $entry['page'];
			$collection['field'] = strtolower($entry['field']);
		} else {
			foreach (['parent', 'template'] as $required) {
				if (!is_string($entry[$required] ?? null) || $entry[$required] === '') {
					throw self::invalid("collection \"$name\": \"$required\" missing");
				}
			}
			$collection['parent'] = $entry['parent'];
			$collection['template'] = $entry['template'];
			$status = $entry['status'] ?? 'listed';
			if (!in_array($status, ['listed', 'unlisted'], true)) {
				throw self::invalid("collection \"$name\": status must be listed or unlisted");
			}
			$collection['status'] = $status;
		}

		foreach ($entry['fields'] as $fieldName => $rule) {
			if (!is_string($fieldName) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $fieldName)) {
				throw self::invalid("collection \"$name\": field name \"$fieldName\" (a-z, 0-9, _)");
			}
			$rule = is_array($rule) ? $rule : ['type' => (string)$rule];
			$type = $rule['type'] ?? 'text';
			if (!in_array($type, self::TYPES, true)) {
				throw self::invalid("collection \"$name\": field \"$fieldName\" has unknown type \"$type\"");
			}
			if ($type === 'image' && $kind === 'page') {
				throw self::invalid("collection \"$name\": image fields are not supported for kind page");
			}
			if (isset($rule['ask']) && !is_bool($rule['ask'])) {
				throw self::invalid("collection \"$name\": field \"$fieldName\" ask must be true or false");
			}
			$field = [
				'type'        => $type,
				'required'    => ($rule['required'] ?? false) === true,
				'ask'         => ($rule['ask'] ?? false) === true,
				'description' => is_string($rule['description'] ?? null) ? $rule['description'] : '',
			];
			if ($type === 'select') {
				$options = array_values(array_map('strval', (array)($rule['options'] ?? [])));
				$field['options'] = $options; // may be filled from the blueprint
			}
			if (isset($rule['maxlength'])) {
				if (!is_int($rule['maxlength']) || $rule['maxlength'] < 1) {
					throw self::invalid("collection \"$name\": field \"$fieldName\" maxlength must be a positive integer");
				}
				$field['maxlength'] = $rule['maxlength'];
			}
			if ($type === 'image') {
				$formats = array_values(array_unique(array_map(fn ($f) => strtolower((string)$f), (array)($rule['formats'] ?? self::DEFAULT_FORMATS))));
				foreach ($formats as $format) {
					if (!in_array($format, Images::FORMATS, true)) {
						throw self::invalid("collection \"$name\": field \"$fieldName\" format \"$format\" (" . implode(', ', Images::FORMATS) . ')');
					}
				}
				$maxWidth = $rule['maxWidth'] ?? self::DEFAULT_MAX_WIDTH;
				if (!is_int($maxWidth) || $maxWidth < 16 || $maxWidth > 8000) {
					throw self::invalid("collection \"$name\": field \"$fieldName\" maxWidth must be 16–8000");
				}
				$field += [
					'formats'  => $formats,
					'maxWidth' => $maxWidth,
					'template' => is_string($rule['template'] ?? null) && $rule['template'] !== '' ? $rule['template'] : null,
					'alt'      => is_string($rule['alt'] ?? null) ? $rule['alt'] : null,
				];
			}
			if ($type === 'number') {
				foreach (['min', 'max'] as $bound) {
					if (!isset($rule[$bound])) {
						continue;
					}
					if (!is_int($rule[$bound]) && !is_float($rule[$bound])) {
						throw self::invalid("collection \"$name\": field \"$fieldName\" $bound must be a number");
					}
					$field[$bound] = $rule[$bound];
				}
				if (isset($field['min'], $field['max']) && $field['min'] > $field['max']) {
					throw self::invalid("collection \"$name\": field \"$fieldName\" min is above max");
				}
			} elseif (isset($rule['min'])) {
				if ($type !== 'date') {
					throw self::invalid("collection \"$name\": field \"$fieldName\" min is only for date and number fields");
				}
				if ($rule['min'] !== 'today') {
					throw self::invalid("collection \"$name\": field \"$fieldName\" min must be \"today\"");
				}
				$field['min'] = 'today';
			}
			if (isset($rule['max']) && $type !== 'number') {
				throw self::invalid("collection \"$name\": field \"$fieldName\" max is only for number fields");
			}
			$collection['fields'][$fieldName] = $field;
		}

		if ($kind === 'pages' && !isset($collection['fields']['title'])) {
			throw self::invalid("collection \"$name\": pages collections need a \"title\" field");
		}
		if ($kind === 'page') {
			$collection['key'] = null; // one page, no entries to tell apart
		} else {
			$key = $entry['key'] ?? null;
			if ($key === null) {
				// The first declared text field identifies an entry.
				foreach ($collection['fields'] as $fieldName => $field) {
					if ($field['type'] === 'text') {
						$key = $fieldName;
						break;
					}
				}
			}
			// A select or a date identifies a row too (opening hours by weekday, holidays by day).
			if (!is_string($key) || !isset($collection['fields'][$key]) || !in_array($collection['fields'][$key]['type'], self::KEY_TYPES, true)) {
				throw self::invalid("collection \"$name\": \"key\" must name a declared text, select or date field");
			}
			$collection['key'] = $key;
			// The key identifies an entry: without it there is no row to create
			// or find. Forced here, so the blueprint merge can only add to it.
			$collection['fields'][$key]['required'] = true;
		}
		foreach ($collection['fields'] as $fieldName => $field) {
			if ($field['type'] === 'image' && $field['alt'] !== null &&
				(!isset($collection['fields'][$field['alt']]) || $collection['fields'][$field['alt']]['type'] === 'image')) {
				throw self::invalid("collection \"$name\": field \"$fieldName\" alt must name a declared text field");
			}
		}

		// Cross-check with the blueprint and complete options/maxlength/labels.
		$collection['fields'] = self::mergeBlueprint($kirby, $collection, self::blueprintFields($kirby, $collection));
		if (isset($entry['touch'])) {
			if (!in_array($kind, ['structure', 'page'], true)) {
				throw self::invalid("collection \"$name\": touch is only for structure and page collections");
			}
			$collection['touch'] = self::touch($kirby, $name, $entry['touch'], $collection);
		}
		return $collection;
	}

	/**
	 * The pages a `pages` blueprint field may point to, as option value =>
	 * page id. Only a field that holds one page (`multiple: false` or
	 * `max: 1`) and names its pages with a `query` qualifies: the query runs
	 * against the collection's model as in Kirby's page picker (a site or a
	 * page stands for its children). The value is the page title, made
	 * unique with the page id where two titles are the same; that is what the
	 * bot shows and sends, never a UUID.
	 *
	 * @return array<string, string>
	 */
	private static function pageTargets(App $kirby, array $collection, string $name, array $definition): array
	{
		$where = "collection \"{$collection['name']}\": field \"$name\"";
		if (($definition['multiple'] ?? true) !== false && ($definition['max'] ?? null) !== 1) {
			throw self::invalid("$where holds several pages; Fude sets one (multiple: false or max: 1 in the blueprint)");
		}
		$query = $definition['query'] ?? null;
		if (!is_string($query) || trim($query) === '') {
			throw self::invalid("$where needs a query in the blueprint that names the pages to choose from");
		}
		try {
			$result = self::model($kirby, $collection)->query($query);
		} catch (\Throwable $e) {
			throw self::invalid("$where query failed: " . $e->getMessage());
		}
		$pages = match (true) {
			$result instanceof Site, $result instanceof Page => $result->children(),
			$result instanceof Pages => $result,
			default => throw self::invalid("$where query must return pages, not " . get_debug_type($result)),
		};
		$titles = [];
		foreach ($pages as $page) {
			$title = trim((string)preg_replace('/\s+/u', ' ', (string)$page->title()->value()));
			$titles[$page->id()] = $title !== '' ? $title : $page->id();
		}
		$counts = array_count_values(array_map('mb_strtolower', $titles));
		$targets = [];
		foreach ($titles as $id => $title) {
			$value = $counts[mb_strtolower($title)] > 1 ? "$title ($id)" : $title;
			$targets[$value] = $id;
		}
		return $targets;
	}

	/**
	 * A stored page reference (`- page://…` or the page id, as a list in a
	 * structure row) as the option value the bot knows: the title. A page
	 * outside the options shows its title too; a reference to nothing is ''.
	 */
	private static function pageValue(App $kirby, array $field, mixed $value): string
	{
		$list = is_array($value) ? $value : Data::decode((string)$value, 'yaml', false);
		$reference = $list[0] ?? null;
		if (!is_string($reference) || trim($reference) === '') {
			return '';
		}
		$page = $kirby->page(trim($reference));
		if (!$page instanceof Page) {
			return '';
		}
		$option = array_search($page->id(), $field['pages'], true);
		return is_string($option) ? $option : trim((string)$page->title()->value());
	}

	/**
	 * The values of a `type: query` select, resolved with Kirby's options API
	 * against the collection's model, as Kirby's own select field does it
	 * (config/fields/select.php). Resolved here at config load, so on every
	 * request. Kirby's query() returns null when a query throws; Kirby then
	 * reports "Invalid query result data: NULL", which is what the error shows.
	 *
	 * @return list<string>
	 */
	private static function queryValues(App $kirby, array $collection, string $name, array $definition): array
	{
		$model = self::model($kirby, $collection);
		try {
			$options = FieldOptions::factory(FieldOptions::polyfill($definition)['options'], false)->render($model);
		} catch (\Throwable $e) {
			throw self::invalid("collection \"{$collection['name']}\": field \"$name\" query failed: " . $e->getMessage());
		}
		return array_map(fn (array $option) => (string)$option['value'], $options);
	}

	/**
	 * What a reader may know: the declaration as JSON plus the current
	 * entries (key and text fields only — never files, never other fields).
	 */
	public static function schema(App $kirby, array $collections): array
	{
		$out = [];
		foreach ($collections as $name => $collection) {
			$fields = [];
			foreach ($collection['fields'] as $fieldName => $field) {
				$public = [
					'type'        => $field['type'],
					'label'       => $field['label'],
					'required'    => $field['required'],
					'description' => $field['description'],
				];
				// Only when set, so a schema without them reads as before.
				foreach (['requiredWhen', 'askWhen'] as $condition) {
					if (isset($field[$condition])) {
						$public[$condition] = $field[$condition];
					}
				}
				if ($field['ask']) {
					$public['ask'] = true;
				}
				if (isset($field['maxlength'])) {
					$public['maxlength'] = $field['maxlength'];
				}
				if (in_array($field['type'], ['select', 'page'], true)) {
					$public['options'] = $field['options'];
				}
				if (isset($field['format'])) {
					$public['format'] = $field['format']; // url, email or tel: the bot writes that form
				}
				if ($field['type'] === 'image') {
					$public['maxWidth'] = $field['maxWidth'];
					$public['formats'] = $field['formats'];
				}
				if (isset($field['min'])) {
					$public['min'] = $field['min'];
				}
				if (isset($field['max'])) {
					$public['max'] = $field['max'];
				}
				$fields[$fieldName] = $public;
			}
			$entry = [
				'kind'        => $collection['kind'],
				'label'       => $collection['label'],
				'description' => $collection['description'],
				'actions'     => $collection['actions'],
				'key'         => $collection['key'],
				'fields'      => (object)$fields,
				'entries'     => self::entries($kirby, $collection),
			];
			if (isset($collection['maxEntries'])) {
				$entry['maxEntries'] = $collection['maxEntries'];
			}
			// What a draft does beyond the fields: the bot says so in its preview.
			if (isset($collection['touch'])) {
				$entry['touch'] = $collection['touch']['field'];
			}
			// A date the bot may choose (draft parameter `touch`), with its published value.
			if (($collection['touch']['editable'] ?? false) === true) {
				$entry['touchDate'] = [
					'label'       => $collection['touch']['label'],
					'description' => $collection['touch']['description'],
					'min'         => 'today',
					'default'     => $collection['touch']['default'] ?? 'today',
					'current'     => self::touchCurrent($kirby, $collection),
				];
			}
			if (isset($collection['insert'])) {
				$entry['insert'] = $collection['insert'];
			}
			// Declares that the model may read the content of attached photos (see README).
			if ($collection['readImages']) {
				$entry['readImages'] = true;
			}
			$out[$name] = $entry;
		}
		return $out;
	}

	/**
	 * Static option values of a select/radio/toggles blueprint field. Options of
	 * type api are not resolved (values declared in fude.php are the allowed
	 * set then); queries are resolved by queryValues().
	 */
	private static function selectOptions(array $definition): array
	{
		$options = $definition['options'] ?? [];
		if (is_array($options) && isset($options['type'])) {
			$options = $options['type'] === 'array' ? ($options['options'] ?? []) : [];
		}
		if (!is_array($options)) {
			return [];
		}
		$values = [];
		foreach ($options as $key => $value) {
			if (is_array($value)) {
				$values[] = (string)($value['value'] ?? $key);
			} elseif (array_is_list($options)) {
				$values[] = (string)$value;
			} else {
				$values[] = (string)$key;
			}
		}
		return $values;
	}

	/**
	 * A blueprint field's `when`, for checking a draft against the entry it
	 * changes: per field the value it must have, the value an entry without
	 * one has (the blueprint default; a toggle without one is off), whether
	 * it is a toggle and its label. Empty when the field is always shown.
	 *
	 * @param array<string, array> $blueprint
	 * @return array<string, array{value: string, default: string, toggle: bool, label: string}>
	 */
	private static function shownWhen(array $blueprint, array $definition): array
	{
		$when = $definition['when'] ?? null;
		if (!is_array($when)) {
			return [];
		}
		$shown = [];
		foreach ($when as $other => $expected) {
			$other = strtolower((string)$other);
			$toggle = ($blueprint[$other]['type'] ?? null) === 'toggle';
			$default = self::stored($blueprint[$other]['default'] ?? '');
			$shown[$other] = [
				'value'   => self::stored($expected),
				'default' => $default === '' && $toggle ? 'false' : $default,
				'toggle'  => $toggle,
				'label'   => self::label($blueprint[$other] ?? []) ?? ucfirst($other),
			];
		}
		return $shown;
	}

	/** A blueprint value as the content stores it: toggles as "true"/"false". */
	private static function stored(mixed $value): string
	{
		return is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string)$value : '');
	}

	/**
	 * `touch`: a page field that every draft sets to today's date. The field
	 * is a page field (not one of the structure's rows), so the page
	 * blueprint decides whether it can take a date.
	 *
	 * With `editable: true` a draft may name another day instead (not
	 * before today), for a date such as "show until". `label` and
	 * `description` tell the bot what the date means; they default to the
	 * field's label and help text. `default` (editable only) is the day a
	 * draft without a date gets: `today`, or a weekday such as `saturday`
	 * for the next one, today included.
	 *
	 * @return array{field: string, value: string, editable?: true, label?: string, description?: string, default?: string}
	 */
	private static function touch(App $kirby, string $name, mixed $touch, array $collection): array
	{
		$field = is_array($touch) ? ($touch['field'] ?? null) : null;
		if (!is_string($field) || $field === '') {
			throw self::invalid("collection \"$name\": touch needs a \"field\" and the value \"today\"");
		}
		if (($touch['value'] ?? null) !== 'today') {
			throw self::invalid("collection \"$name\": touch value must be \"today\"");
		}
		$page = $kirby->site()->find($collection['page']);
		$definition = $page instanceof Page ? ($page->blueprint()->fields()[$field] ?? null) : null;
		if (!is_array($definition)) {
			throw self::invalid("collection \"$name\": touch field \"$field\" is not in the blueprint of \"{$collection['page']}\"");
		}
		if ($field === ($collection['field'] ?? null)) {
			throw self::invalid("collection \"$name\": touch field \"$field\" is the structure field itself");
		}
		if (!in_array($definition['type'] ?? null, ['date', 'text'], true)) {
			throw self::invalid("collection \"$name\": touch field \"$field\" must be a date or text field");
		}
		$editable = $touch['editable'] ?? false;
		if (!is_bool($editable)) {
			throw self::invalid("collection \"$name\": touch editable must be true or false");
		}
		foreach (['label', 'description'] as $option) {
			if (isset($touch[$option]) && !is_string($touch[$option])) {
				throw self::invalid("collection \"$name\": touch $option must be a text");
			}
		}
		$default = $touch['default'] ?? 'today';
		if (!is_string($default) || !in_array($default, ['today', ...self::WEEKDAYS], true)) {
			throw self::invalid("collection \"$name\": touch default must be \"today\" or a weekday (monday … sunday)");
		}
		if (!$editable) {
			if ($default !== 'today') {
				throw self::invalid("collection \"$name\": touch default needs editable: true");
			}
			return ['field' => $field, 'value' => 'today'];
		}
		return [
			'field'       => $field,
			'value'       => 'today',
			'editable'    => true,
			'label'       => $touch['label'] ?? self::label($definition) ?? $field,
			'description' => $touch['description'] ?? self::helpText($definition),
			// Only a weekday is kept: with today the touch keeps the shape it always had.
			...($default !== 'today' ? ['default' => $default] : []),
		];
	}

	/** The published value of a collection's touch field, as text ('' when empty). */
	private static function touchCurrent(App $kirby, array $collection): string
	{
		$page = $kirby->site()->find($collection['page']);
		$content = $page instanceof Page ? ($page->version('latest')->read() ?? []) : [];
		// Content keys are lower case: a field giltAm is stored as giltam.
		$value = $content[strtolower($collection['touch']['field'])] ?? null;
		return is_scalar($value) ? trim((string)$value) : '';
	}

	/**
	 * A text of a blueprint that may be a translation key (plugin blueprints,
	 * such as `higawari.panel.date.help`): it reads as its text in the reply
	 * language. Any other text, and a key without a text, stays as written.
	 */
	public static function translateKey(string $text): string
	{
		$translated = \Kirby\Toolkit\I18n::translate($text, $text, I18n::language());
		return is_string($translated) ? $translated : $text;
	}
}
