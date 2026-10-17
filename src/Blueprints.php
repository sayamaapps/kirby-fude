<?php

namespace Fude;

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
 * Returns raw declaration entries in the shape of site/config/fude.php;
 * Config::load() merges them with the file and normalises every entry, so
 * the validation stays in one place. Blueprint types that Fude cannot map
 * are skipped inside structures and rejected when marked.
 */
final class Blueprints
{
    public const MARKER = 'fude';

    /** Blueprint field types and the Fude type each one maps to. */
    private const TYPES = [
        'text' => 'text', 'slug' => 'text', 'tel' => 'text', 'email' => 'text', 'url' => 'text',
        'textarea' => 'textarea', 'writer' => 'textarea',
        'select' => 'select', 'radio' => 'select', 'toggles' => 'select',
        'files' => 'image',
        'date' => 'date', 'number' => 'number', 'time' => 'time', 'toggle' => 'toggle',
    ];
    private const PAGE_OPTIONS = ['description'];
    private const STRUCTURE_OPTIONS = ['name', 'label', 'description', 'key', 'actions', 'insert', 'maxEntries', 'touch'];
    private const FIELD_OPTIONS = ['description', 'maxWidth', 'alt', 'formats', 'template'];
    private const IMAGE_OPTIONS = ['maxWidth', 'alt', 'formats', 'template'];
    private const DEFAULT_ACTIONS = ['create', 'update', 'delete'];

    /**
     * @return array<string, array> collection name => raw declaration entry
     * @throws FudeError 500 for a marker that cannot be honoured
     */
    public static function collections(App $kirby): array
    {
        $site = $kirby->site();
        foreach ($site->blueprint()->fields() as $name => $definition) {
            if ((self::marker((array) $definition) ?? false) !== false) {
                throw self::invalid("site fields are not supported (field \"$name\" in site.txt carries a fude marker)");
            }
        }

        $collections = [];
        foreach ($site->index() as $page) {
            $pageFields = [];
            foreach ($page->blueprint()->fields() as $fieldName => $definition) {
                // Kirby keeps the blueprint's spelling ("leitsatzHervor"); content keys are lower case.
                $fieldName = strtolower((string) $fieldName);
                $definition = (array) $definition;
                $marker = self::marker($definition);
                if ($marker === null || $marker === false) {
                    continue;
                }
                $where = 'page "' . $page->id() . '", field "' . $fieldName . '"';
                if (($definition['type'] ?? null) === 'structure') {
                    self::add($collections, self::structure($page, (string) $fieldName, $definition, $marker));
                    continue;
                }
                self::checkOptions($marker, self::PAGE_OPTIONS, $where);
                $pageFields[(string) $fieldName] = [
                    'type'        => self::fudeType($where, (string) ($definition['type'] ?? ''), false),
                    'description' => self::text($marker['description'] ?? null, $where, 'description') ?? self::help($definition),
                ];
            }
            if ($pageFields !== []) {
                $title = (string) $page->title()->value();
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
     * The marker of a blueprint field: null when absent (or null), false for
     * an explicit `fude: false`, otherwise its options (`true` is `[]`).
     *
     * @return array|false|null
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

    /** @return array<string, mixed> one structure collection, its sub-fields mapped */
    private static function structure(Page $page, string $field, array $definition, array $marker): array
    {
        $where = 'page "' . $page->id() . '", field "' . $field . '"';
        self::checkOptions($marker, self::STRUCTURE_OPTIONS, $where);

        $fields = [];
        foreach ((array) ($definition['fields'] ?? []) as $subName => $sub) {
            $subName = strtolower((string) $subName);
            $sub = (array) $sub;
            $subWhere = $where . ', sub-field "' . $subName . '"';
            $subMarker = self::marker($sub);
            if ($subMarker === false) {
                continue;
            }
            $subType = (string) ($sub['type'] ?? '');
            if (!isset(self::TYPES[$subType])) {
                if ($subMarker !== null) {
                    throw self::invalid("$subWhere: type \"$subType\" is not supported by fude");
                }
                continue; // unmapped and unmarked: not part of the declaration
            }
            $type = self::fudeType($subWhere, $subType, true);
            $subMarker ??= [];
            self::checkOptions($subMarker, self::FIELD_OPTIONS, $subWhere);
            $rule = [
                'type'        => $type,
                'description' => self::text($subMarker['description'] ?? null, $subWhere, 'description') ?? self::help($sub),
            ];
            if ($type !== 'image') {
                $only = array_intersect(self::IMAGE_OPTIONS, array_keys($subMarker));
                if ($only !== []) {
                    throw self::invalid("$subWhere: " . implode(', ', $only) . ' is only for files fields');
                }
            }
            foreach (self::IMAGE_OPTIONS as $option) {
                if (array_key_exists($option, $subMarker)) {
                    $rule[$option] = $subMarker[$option];
                }
            }
            $fields[$subName] = $rule;
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
        $label = self::text($marker['label'] ?? null, $where, 'label') ?? Config::label($definition) ?? $field;

        $collection = [
            'name'        => $name,
            'kind'        => 'structure',
            'page'        => $page->id(),
            'field'       => $field,
            'label'       => $label,
            'description' => self::text($marker['description'] ?? null, $where, 'description') ?? self::help($definition),
            'actions'     => $marker['actions'] ?? self::DEFAULT_ACTIONS,
            'key'         => $key,
            'fields'      => $fields,
        ];
        // Checked by Config like the same options in site/config/fude.php.
        foreach (['insert', 'maxEntries', 'touch'] as $option) {
            if (array_key_exists($option, $marker)) {
                $collection[$option] = $marker[$option];
            }
        }
        return $collection;
    }

    /**
     * The Fude type of a blueprint type. Files are images only inside a
     * structure: a page collection has no images.
     */
    private static function fudeType(string $where, string $blueprintType, bool $inStructure): string
    {
        $type = self::TYPES[$blueprintType] ?? null;
        if ($type === null) {
            throw self::invalid("$where: type \"$blueprintType\" is not supported by fude");
        }
        if ($type === 'image' && !$inStructure) {
            throw self::invalid("$where: files fields are only supported inside a structure");
        }
        return $type;
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

    private static function text(mixed $value, string $where, string $option): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw self::invalid("$where: fude option \"$option\" must be a text");
    }

    /** The help text of a field, as plain text on one line. */
    private static function help(array $definition): string
    {
        $help = $definition['help'] ?? '';
        return is_string($help) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags($help))) : '';
    }

    /** @param array<string, array> $collections */
    private static function add(array &$collections, array $collection): void
    {
        $name = $collection['name'];
        if (isset($collections[$name])) {
            throw self::invalid("two marked fields would both become the collection \"$name\": set fude: {name: …} on the structure field");
        }
        $collections[$name] = $collection;
    }

    private static function invalid(string $reason): FudeError
    {
        return FudeError::t('config.invalid', ['reason' => $reason], 500);
    }
}
