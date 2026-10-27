<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\I18n;

/**
 * Plugin translations come as sayamaapps.fude.<key> and as the 1.x alias
 * fude.<key>, with the same text, for every language Fude ships.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class TranslationKeysTest extends TestCase
{
	public function testBothKeyNamesCarryTheSameText(): void
	{
		$translations = I18n::pluginTranslations();
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			same(count($table) * 2, count($translations[$language]), "Two keys per string ($language)");
			foreach ($table as $key => $text) {
				same($text, $translations[$language]["sayamaapps.fude.$key"] ?? null, "New key $key ($language)");
				same($text, $translations[$language]["fude.$key"] ?? null, "1.x alias $key ($language)");
			}
		}
	}

	public function testKirbyReadsTheNewKeysInBothLanguages(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app();
			same(I18n::table('en')['draft.internal'], \Kirby\Toolkit\I18n::translate('sayamaapps.fude.draft.internal', 'MISSING', 'en'), 'English via Kirby');
			same(I18n::table('de')['draft.internal'], \Kirby\Toolkit\I18n::translate('sayamaapps.fude.draft.internal', 'MISSING', 'de'), 'German via Kirby');
		} finally {
			cleanup($tmp);
		}
	}
}
