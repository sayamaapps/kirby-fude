<?php

namespace Sayamaapps\Fude;

/**
 * Strings of the route responses, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `fude.<key>` (config/translations.php). Fude
 * reads its own tables: the messages go back to a person in a chat, and a
 * missing Kirby translation must never silently turn a German reply English.
 *
 * The language is set once per request (Options::language()) and defaults to
 * English. Missing keys fall back to English, then to the key.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class I18n
{
	/** Languages Fude ships. English is the default and the fallback. */
	public const LANGUAGES = ['en', 'de'];

	private static string $language = 'en';

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	/** The language of the replies, `en` or `de`. */
	public static function language(): string
	{
		return self::$language;
	}

	/**
	 * The tables as Kirby plugin translations, per language: `fude.<key>`.
	 * Kirby reads them for blueprint labels; the replies use table() instead.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function pluginTranslations(): array
	{
		$translations = [];
		foreach (self::LANGUAGES as $language) {
			foreach (self::table($language) as $key => $text) {
				$translations[$language]['fude.' . $key] = $text;
			}
		}

		return $translations;
	}

	/** Sets the reply language; anything Fude does not ship becomes English. */
	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/** `{name}` placeholders are replaced with the given values, unescaped. */
	public static function t(string $key, array $values = []): string
	{
		$text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}

	/** @return array<string,string> */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}
}
