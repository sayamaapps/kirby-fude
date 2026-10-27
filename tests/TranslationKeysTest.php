<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\I18n;

/**
 * Plugin translations come as fude.<key>, as in 1.x, with the text of the
 * reply tables, for every language Fude ships.
 */
#[CoversClass(I18n::class)]
#[RunTestsInSeparateProcesses]
final class TranslationKeysTest extends TestCase
{
	public function testEveryStringIsRegisteredOnce(): void
	{
		$translations = I18n::pluginTranslations();
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			same(count($table), count($translations[$language]), "One key per string ($language)");
			foreach ($table as $key => $text) {
				same($text, $translations[$language]["fude.$key"] ?? null, "Key $key ($language)");
			}
		}
	}

	public function testKirbyReadsTheKeysInBothLanguages(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app();
			same(I18n::table('en')['draft.internal'], \Kirby\Toolkit\I18n::translate('fude.draft.internal', 'MISSING', 'en'), 'English via Kirby');
			same(I18n::table('de')['draft.internal'], \Kirby\Toolkit\I18n::translate('fude.draft.internal', 'MISSING', 'de'), 'German via Kirby');
		} finally {
			cleanup($tmp);
		}
	}
}
