<?php

namespace Fude;

use Kirby\Cms\App;
use Kirby\Cms\Page;
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
    public const KINDS = ['structure', 'pages', 'page'];
    public const TYPES = ['text', 'textarea', 'select', 'image', 'date', 'number', 'time', 'toggle'];
    /** Field types that may identify a row (the key). */
    public const KEY_TYPES = ['text', 'select', 'date'];
    /** Blueprint types that give a text field a format, checked by the Validator. */
    public const FORMATS = ['url', 'email', 'tel'];
    public const ACTIONS = ['create', 'update', 'delete', 'replace'];
    /** What a collection may do when its declaration names no actions. */
    public const DEFAULT_ACTIONS = ['create', 'update'];
    public const DEFAULT_FORMATS = ['jpg', 'png', 'webp'];
    public const DEFAULT_MAX_WIDTH = 1600;

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
    ];

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
        $entries = Options::get($kirby, 'blueprints', true) === false ? [] : Blueprints::collections($kirby);
        foreach ($declared as $name => $entry) {
            if ($entry === false) {
                unset($entries[$name]);
                continue;
            }
            $entries[$name] = $entry;
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

    /**
     * The label of a blueprint field: a plain text, a translation array, or a
     * translation key (plugin blueprints). Null when there is none.
     */
    public static function label(array $definition): ?string
    {
        $label = $definition['label'] ?? null;
        if (is_array($label)) {
            $label = $label[I18n::language()] ?? $label['en'] ?? reset($label);
        } elseif (is_string($label)) {
            // A translation key (plugin blueprints): Kirby translates field
            // labels only in the Panel form, not in the blueprint.
            $translated = \Kirby\Toolkit\I18n::translate($label, $label, I18n::language());
            $label = is_string($translated) ? $translated : $label;
        }
        return is_string($label) && $label !== '' ? $label : null;
    }

    private static function invalid(string $reason): FudeError
    {
        return FudeError::t('config.invalid', ['reason' => $reason], 500);
    }

    private static function normalise(App $kirby, string $name, array $entry): array
    {
        $kind = $entry['kind'] ?? 'structure';
        if (!in_array($kind, self::KINDS, true)) {
            throw self::invalid("collection \"$name\": unknown kind \"$kind\"");
        }
        $defaultActions = $kind === 'page' ? ['update'] : self::DEFAULT_ACTIONS;
        $actions = array_values(array_unique(array_map('strval', (array) ($entry['actions'] ?? $defaultActions))));
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
            'publishRoles' => array_values(array_map('strval', (array) ($entry['publishRoles'] ?? [Service::ROLE]))),
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
            $rule = is_array($rule) ? $rule : ['type' => (string) $rule];
            $type = $rule['type'] ?? 'text';
            if (!in_array($type, self::TYPES, true)) {
                throw self::invalid("collection \"$name\": field \"$fieldName\" has unknown type \"$type\"");
            }
            if ($type === 'image' && $kind === 'page') {
                throw self::invalid("collection \"$name\": image fields are not supported for kind page");
            }
            $field = [
                'type'        => $type,
                'required'    => ($rule['required'] ?? false) === true,
                'description' => is_string($rule['description'] ?? null) ? $rule['description'] : '',
            ];
            if ($type === 'select') {
                $options = array_values(array_map('strval', (array) ($rule['options'] ?? [])));
                $field['options'] = $options; // may be filled from the blueprint
            }
            if (isset($rule['maxlength'])) {
                if (!is_int($rule['maxlength']) || $rule['maxlength'] < 1) {
                    throw self::invalid("collection \"$name\": field \"$fieldName\" maxlength must be a positive integer");
                }
                $field['maxlength'] = $rule['maxlength'];
            }
            if ($type === 'image') {
                $formats = array_values(array_unique(array_map(fn ($f) => strtolower((string) $f), (array) ($rule['formats'] ?? self::DEFAULT_FORMATS))));
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
            if ($field['type'] === 'image' && $field['alt'] !== null
                && (!isset($collection['fields'][$field['alt']]) || $collection['fields'][$field['alt']]['type'] === 'image')) {
                throw self::invalid("collection \"$name\": field \"$fieldName\" alt must name a declared text field");
            }
        }

        // Cross-check with the blueprint and complete options/maxlength/labels.
        $collection['fields'] = self::mergeBlueprint($collection, self::blueprintFields($kirby, $collection));
        if (isset($entry['touch'])) {
            if (!in_array($kind, ['structure', 'page'], true)) {
                throw self::invalid("collection \"$name\": touch is only for structure and page collections");
            }
            $collection['touch'] = self::touch($kirby, $name, $entry['touch'], $collection);
        }
        return $collection;
    }

    /**
     * `touch`: a page field that every draft sets to today's date. The field
     * is a page field (not one of the structure's rows), so the page
     * blueprint decides whether it can take a date.
     *
     * @return array{field: string, value: string}
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
        return ['field' => $field, 'value' => 'today'];
    }

    /**
     * The blueprint fields behind a collection: the structure field's
     * sub-fields, or the page blueprint's fields for a pages collection.
     *
     * @return array<string, array> field name => blueprint definition
     */
    public static function blueprintFields(App $kirby, array $collection): array
    {
        if ($collection['kind'] === 'page') {
            $page = $kirby->site()->find($collection['page']);
            if (!$page instanceof Page) {
                throw self::invalid("collection \"{$collection['name']}\": page \"{$collection['page']}\" does not exist");
            }
            $fields = [];
            foreach ($page->blueprint()->fields() as $fieldName => $definition) {
                $fields[strtolower((string) $fieldName)] = (array) $definition;
            }
            return $fields;
        }
        if ($collection['kind'] === 'structure') {
            $page = $kirby->site()->find($collection['page']);
            if (!$page instanceof Page) {
                throw self::invalid("collection \"{$collection['name']}\": page \"{$collection['page']}\" does not exist");
            }
            $field = $page->blueprint()->field($collection['field']);
            if (($field['type'] ?? null) !== 'structure') {
                throw self::invalid("collection \"{$collection['name']}\": \"{$collection['field']}\" is not a structure field of \"{$collection['page']}\"");
            }
            $fields = [];
            foreach ((array) ($field['fields'] ?? []) as $subName => $sub) {
                $fields[strtolower((string) $subName)] = (array) $sub;
            }
            return $fields;
        }
        $parent = $kirby->site()->find($collection['parent']);
        if (!$parent instanceof Page) {
            throw self::invalid("collection \"{$collection['name']}\": parent \"{$collection['parent']}\" does not exist");
        }
        // An unsaved page of that template, only to read its blueprint.
        $probe = Page::factory(['slug' => 'fude-probe', 'template' => $collection['template'], 'parent' => $parent, 'isDraft' => true]);
        $fields = [];
        foreach ($probe->blueprint()->fields() as $fieldName => $definition) {
            $fields[strtolower((string) $fieldName)] = (array) $definition;
        }
        $fields['title'] ??= ['type' => 'text', 'required' => true, 'label' => 'Title'];
        return $fields;
    }

    /** @param array<string, array> $blueprint */
    private static function mergeBlueprint(array $collection, array $blueprint): array
    {
        $fields = [];
        foreach ($collection['fields'] as $name => $field) {
            $definition = $blueprint[$name] ?? null;
            if ($definition === null) {
                throw self::invalid("collection \"{$collection['name']}\": field \"$name\" is not in the blueprint");
            }
            $blueprintType = (string) ($definition['type'] ?? '');
            if (!in_array($blueprintType, self::COMPATIBLE[$field['type']], true)) {
                throw self::invalid("collection \"{$collection['name']}\": field \"$name\" is declared as {$field['type']} but the blueprint says $blueprintType");
            }
            $field['label'] = self::label($definition) ?? ucfirst($name);
            if ($field['type'] === 'text' && in_array($blueprintType, self::FORMATS, true)) {
                // The blueprint's own format: Validator checks it before the Panel rules do.
                $field['format'] = $blueprintType;
            }
            if (($definition['required'] ?? false) === true) {
                $field['required'] = true;
            }
            if (is_int($definition['maxlength'] ?? null) && $definition['maxlength'] > 0) {
                $field['maxlength'] = isset($field['maxlength']) ? min($field['maxlength'], $definition['maxlength']) : $definition['maxlength'];
            }
            if ($field['type'] === 'select') {
                $blueprintOptions = self::selectOptions($definition);
                if ($field['options'] === []) {
                    $field['options'] = $blueprintOptions;
                } elseif ($blueprintOptions !== [] && array_diff($field['options'], $blueprintOptions) !== []) {
                    throw self::invalid("collection \"{$collection['name']}\": field \"$name\" declares options the blueprint does not have");
                }
                if ($field['options'] === []) {
                    throw self::invalid("collection \"{$collection['name']}\": field \"$name\" has no options");
                }
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

    /** Static option values of a select/radio/toggles blueprint field. */
    private static function selectOptions(array $definition): array
    {
        $options = $definition['options'] ?? [];
        if (is_array($options) && isset($options['type'])) {
            // Dynamic options (query, api) are not resolved here: values
            // declared in fude.php are the allowed set then.
            $options = $options['type'] === 'array' ? ($options['options'] ?? []) : [];
        }
        if (!is_array($options)) {
            return [];
        }
        $values = [];
        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $values[] = (string) ($value['value'] ?? $key);
            } elseif (array_is_list($options)) {
                $values[] = (string) $value;
            } else {
                $values[] = (string) $key;
            }
        }
        return $values;
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
                if (isset($field['maxlength'])) {
                    $public['maxlength'] = $field['maxlength'];
                }
                if ($field['type'] === 'select') {
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
                'fields'      => (object) $fields,
                'entries'     => self::entries($kirby, $collection),
            ];
            if (isset($collection['maxEntries'])) {
                $entry['maxEntries'] = $collection['maxEntries'];
            }
            // What a draft does beyond the fields: the bot says so in its preview.
            if (isset($collection['touch'])) {
                $entry['touch'] = $collection['touch']['field'];
            }
            if (isset($collection['insert'])) {
                $entry['insert'] = $collection['insert'];
            }
            $out[$name] = $entry;
        }
        return $out;
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
                } elseif (is_bool($value)) {
                    // A toggle as Kirby saves it: "true" or "false".
                    $entry[$fieldName] = $value ? 'true' : 'false';
                } else {
                    $entry[$fieldName] = is_scalar($value) ? (string) $value : '';
                }
            }
            $entries[] = $entry;
        }
        return $entries;
    }
}
