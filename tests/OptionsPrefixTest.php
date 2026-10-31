<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Options;

/**
 * Options are read as sayamaapps.fude.<key>, falling back to the 1.x name
 * fude.<key>, which is read without a runtime notice in debug mode.
 */
#[CoversClass(Options::class)]
#[RunTestsInSeparateProcesses]
final class OptionsPrefixTest extends TestCase
{
	public function testNullCountsAsNotSet(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['sayamaapps.fude.language' => null, 'fude.language' => null]);
		same('en', Options::get($kirby, 'language', 'en'), 'A null value gives the default');
		cleanup($tmp);
	}

	public function testReadingInDebugModePrintsNoDeprecation(): void
	{
		[$tmp, $app] = fude_fixture();
		$messages = [];
		set_error_handler(static function (int $level, string $message) use (&$messages): bool {
			if ($level === E_USER_DEPRECATED) {
				$messages[] = $message;
			}

			return true;
		}, E_USER_DEPRECATED);
		try {
			$kirby = $app(options: ['debug' => true, 'fude.language' => 'de']);
			same('de', Options::get($kirby, 'language'), 'Read in debug mode');
			same([], $messages, 'No deprecation notice in 1.x');
		} finally {
			restore_error_handler();
			cleanup($tmp);
		}
	}

	public function testSetKeyNamesTheSpellingTheSiteUsed(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			same('sayamaapps.fude.language', Options::setKey($app(), 'language'), 'Nothing set: the new name');
			same('fude.language', Options::setKey($app(options: ['fude.language' => 'de']), 'language'), 'Only the 1.x key set: the 1.x name');
			same('sayamaapps.fude.language', Options::setKey($app(options: ['fude.language' => 'de', 'sayamaapps.fude.language' => 'en']), 'language'), 'Both set: the new name');
		} finally {
			cleanup($tmp);
		}
	}

	public function testTheDefaultAppliesWhenNotSet(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['sayamaapps.fude.active' => null]);
		same('fallback', Options::get($kirby, 'nothing', 'fallback'), 'Not set: the default');
		same(null, Options::get($kirby, 'nothing'), 'Not set, no default: null');
		cleanup($tmp);
	}

	public function testTheLegacyWrapperReadsTheNewKey(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app(options: ['sayamaapps.fude.language' => 'de']);
			same('de', fude_option('language'), 'fude_option() reads sayamaapps.fude.language');
		} finally {
			cleanup($tmp);
		}
	}

	public function testTheNewKeyIsRead(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app(options: ['sayamaapps.fude.language' => 'de', 'sayamaapps.fude.active' => true]);
			same('de', Options::get($kirby, 'language'), 'Key sayamaapps.fude.language is read');
			same(true, Options::get($kirby, 'active', false), 'Key sayamaapps.fude.active is read');
		} finally {
			cleanup($tmp);
		}
	}

	public function testTheNewKeyWinsWhenBothAreSet(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app(options: ['fude.language' => 'en', 'sayamaapps.fude.language' => 'de']);
			same('de', Options::get($kirby, 'language'), 'The new key wins over the 1.x key');
		} finally {
			cleanup($tmp);
		}
	}

	public function testTheOldKeyIsRead(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app(options: ['fude.language' => 'de']);
			same('de', Options::get($kirby, 'language'), 'Key fude.language is read');
			same(true, Options::get($kirby, 'active', false), 'Fixture key fude.active is read');
		} finally {
			cleanup($tmp);
		}
	}
}
