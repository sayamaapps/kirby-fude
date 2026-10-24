<?php

namespace Fude;

use Kirby\Text\KirbyTag;

/**
 * Checks the fields of a draft against the declaration — before Kirby's
 * blueprint validation runs on the finished entry (Service::checkBlueprint()).
 *
 * Unknown fields are an error, not silently dropped: the bot must notice
 * when it sends something that does not arrive. Image fields are not text
 * and arrive as uploads; a text value for them is refused.
 */
final class Validator
{
    /**
     * A KirbyTag as KirbyTags::parse() finds it (the same pattern): "(" right
     * before a name of letters, digits, "_" or "-" and ":", in any case, up to
     * the balanced ")". Nested tags lie inside it; an image caption is
     * rendered as KirbyText again.
     */
    private const KIRBYTAG = '!(?=[^\]])(?=\([a-z0-9_-]+:)(\((?:[^()]+|(?1))*+\))!is';

    /** KirbyTags that put a script on the page: Kirby renders (gist: …) as <script src>. */
    private const SCRIPT_TAGS = ['gist'];

    /**
     * @param array<string, mixed> $fields the submitted fields
     * @return array<string, string> trimmed, validated text values
     * @throws FudeError
     */
    public static function check(array $collection, array $fields, string $action, ?string $today = null): array
    {
        // `today` is the draft's day (Y-m-d); the clock is the default for direct callers.
        $today ??= date('Y-m-d');
        $declared = $collection['fields'];
        $allowed = array_keys(array_filter($declared, fn ($f) => $f['type'] !== 'image'));
        $clean = [];
        foreach ($fields as $name => $value) {
            $name = (string) $name;
            if (!isset($declared[$name])) {
                throw FudeError::t('fields.unknown', ['field' => $name, 'allowed' => implode(', ', $allowed)]);
            }
            $rule = $declared[$name];
            if ($rule['type'] === 'image') {
                throw FudeError::t('fields.image', ['field' => $name]);
            }
            if ($value === null) {
                $value = '';
            }
            if ($rule['type'] === 'toggle' && is_bool($value)) {
                // JSON true/false: stored as Kirby's toggle field saves it.
                $value = $value ? 'true' : 'false';
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw FudeError::t('fields.type', ['field' => $name]);
            }
            $value = (string) $value;
            if (!mb_check_encoding($value, 'UTF-8')) {
                // Invalid UTF-8 would vanish in the whitespace pass below and clear the field.
                throw FudeError::t('fields.type', ['field' => $name]);
            }
            $value = trim($value);
            if ($rule['type'] !== 'textarea') {
                // Single-line fields: no line breaks, collapsed whitespace.
                $value = trim((string) preg_replace('/\s+/u', ' ', $value));
            } else {
                $value = str_replace("\r\n", "\n", $value);
            }
            if ($rule['type'] === 'text' && isset($rule['format']) && $value !== '') {
                self::checkFormat($name, $rule['format'], $value);
            }
            if (in_array($rule['type'], ['text', 'textarea', 'select'], true) && $value !== '') {
                self::checkMarkup($name, $value);
            }
            if ($rule['type'] === 'date' && $value !== '') {
                // A real calendar day in the form YYYY-MM-DD (checkdate rejects 2026-02-30).
                $valid = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
                    && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
                if (!$valid) {
                    throw FudeError::t('fields.date', ['field' => $name]);
                }
                // Zero-padded Y-m-d strings compare in date order.
                if (($rule['min'] ?? null) === 'today' && $value < $today) {
                    throw FudeError::t('fields.dateMin', ['field' => $name]);
                }
            }
            if ($rule['type'] === 'time' && $value !== '') {
                // 24 h, "H:MM" or "HH:MM", optional ":SS". Stored as Kirby's time field
                // saves it: its save() formats the parsed time with 'H:i:s' (config/fields/time.php,
                // config/fields/mixins/datetime.php), so 8:30 becomes "08:30:00".
                if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) !== 1) {
                    throw FudeError::t('fields.time', ['field' => $name]);
                }
                $parts = explode(':', $value);
                $value = sprintf('%02d:%02d:%02d', (int) $parts[0], (int) $parts[1], (int) ($parts[2] ?? 0));
            }
            if ($rule['type'] === 'toggle' && $value !== '') {
                // Stored as Kirby's toggle field saves it: "true" or "false" (config/fields/toggle.php, save()).
                $value = strtolower($value);
                if (!in_array($value, ['true', 'false'], true)) {
                    throw FudeError::t('fields.toggle', ['field' => $name]);
                }
            }
            if ($rule['type'] === 'number' && $value !== '') {
                // Decimal comma or point; stored with a point, as Kirby's number field does.
                $value = str_replace(',', '.', $value);
                if (preg_match('/^-?\d{1,15}(\.\d{1,15})?$/D', $value) !== 1) {
                    throw FudeError::t('fields.number', ['field' => $name]);
                }
                if (isset($rule['min']) && (float) $value < $rule['min']) {
                    throw FudeError::t('fields.numberMin', ['field' => $name, 'min' => (string) $rule['min']]);
                }
                if (isset($rule['max']) && (float) $value > $rule['max']) {
                    throw FudeError::t('fields.numberMax', ['field' => $name, 'max' => (string) $rule['max']]);
                }
            }
            $max = $rule['maxlength'] ?? ($rule['type'] === 'textarea' ? 4000 : 500);
            if (mb_strlen($value) > $max) {
                throw FudeError::t('fields.maxlength', ['field' => $name, 'max' => $max]);
            }
            if ($rule['type'] === 'select' && $value !== '' && !in_array($value, $rule['options'], true)) {
                throw FudeError::t('fields.option', ['field' => $name, 'options' => implode(', ', $rule['options'])]);
            }
            if ($rule['type'] === 'page' && $value !== '') {
                // A page by its option value (the title), in any case; stored as the
                // option's own spelling. Service turns it into the page reference.
                $match = null;
                foreach ($rule['options'] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($value)) {
                        $match = $option;
                        break;
                    }
                }
                if ($match === null) {
                    throw FudeError::t('fields.option', ['field' => $name, 'options' => implode(', ', $rule['options'])]);
                }
                $value = $match;
            }
            if ($value === '' && $rule['required']) {
                throw FudeError::t('fields.required', ['field' => $name]);
            }
            $clean[$name] = $value;
        }
        if ($action === 'create') {
            foreach ($declared as $name => $rule) {
                if ($rule['required'] && $rule['type'] !== 'image' && ($clean[$name] ?? '') === '') {
                    throw FudeError::t('fields.required', ['field' => $name]);
                }
            }
        }
        return $clean;
    }

    /**
     * Text is plain text or Markdown. Sites render textareas with KirbyText,
     * which passes raw HTML through, so a tag, a comment, a script scheme or
     * an HTML entity that could hide one is refused. Plain "<" in prose
     * ("Kinder < 6 Jahre") is fine: only a letter, "!", "/" or "?" after it opens a tag.
     */
    private static function checkMarkup(string $name, string $value): void
    {
        $squeezed = (string) preg_replace('/[\s\x00-\x1f]+/', '', $value); // "java\tscript:" is one scheme
        if (preg_match('/<[a-z!\/?]/i', $value) === 1
            || preg_match('/(javascript|vbscript):/i', $squeezed) === 1
            || preg_match('/(?<![a-z])data\s*:/i', $value) === 1
            || str_contains($value, '&#')
            || preg_match('/&colon;/i', $value) === 1) {
            throw FudeError::t('fields.html', ['field' => $name]);
        }
        self::checkKirbyTags($name, $value);
    }

    /**
     * KirbyText renders KirbyTags too. Refused: a tag that loads a script,
     * and a style attribute, which Kirby writes as inline CSS (video tag).
     * The names follow KirbyTag::parse(): lower-cased, aliases resolved.
     * Kirby splits a tag's attributes at "<name>:" with no word boundary,
     * so "style:" anywhere inside a tag is refused.
     */
    private static function checkKirbyTags(string $name, string $value): void
    {
        // Kirby wants the name right between "(" and ":"; refusing spaces there too costs nothing.
        preg_match_all('/\(\s*([a-z0-9_-]+)\s*:/i', $value, $types);
        foreach ($types[1] as $type) {
            $type = strtolower($type);
            if (!isset(KirbyTag::$types[$type])) {
                $type = KirbyTag::$aliases[$type] ?? $type;
            }
            if (in_array($type, self::SCRIPT_TAGS, true)) {
                throw FudeError::t('fields.script', ['field' => $name]);
            }
        }
        if (preg_match_all(self::KIRBYTAG, $value, $tags) === false) {
            // The pattern hit a PCRE limit: refuse rather than let it through unchecked.
            throw FudeError::t('fields.html', ['field' => $name]);
        }
        foreach ($tags[0] as $tag) {
            if (stripos($tag, 'style:') !== false) {
                throw FudeError::t('fields.style', ['field' => $name]);
            }
        }
    }

    /** The format a blueprint type gives a text field: url, email or tel. */
    private static function checkFormat(string $name, string $format, string $value): void
    {
        $valid = match ($format) {
            // Only http(s) links: "javascript:alert(1)" never passes as a URL.
            'url'   => preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            // A leading "+", then digits, spaces and / - ( ), with at least three digits.
            'tel'   => preg_match('/^\+?[\d \/()-]+$/D', $value) === 1 && preg_match_all('/\d/', $value) >= 3,
        };
        if (!$valid) {
            throw FudeError::t('fields.' . $format, ['field' => $name]);
        }
    }
}
