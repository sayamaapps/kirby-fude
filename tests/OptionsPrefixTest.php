<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Options;

/**
 * Options are read as sayamaapps.fude.<key>; the 1.x spelling fude.<key> is
 * read when the new key is not set and announces itself as deprecated in debug
 * mode. The new key wins when both are set.
 */
#[CoversClass(Options::class)]
#[RunTestsInSeparateProcesses]
final class OptionsPrefixTest extends TestCase
{
	public function testReadingTheNewKeyIsNotDeprecated(): void
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
			$kirby = $app(options: ['debug' => true, 'fude.language' => null, 'sayamaapps.fude.language' => 'de']);
			same('de', Options::get($kirby, 'language'), 'New key read in debug mode');
			same([], $messages, 'No deprecation for the new key');
		} finally {
			restore_error_handler();
			cleanup($tmp);
		}
	}

	public function testReadingTheOldKeyIsDeprecatedInDebugMode(): void
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
			same('de', Options::get($kirby, 'language'), 'Old key read in debug mode');
			same(1, count($messages), 'One deprecation for the old key');
			ok(str_contains($messages[0], 'fude.language'), 'The message names the old key', $messages);
			ok(str_contains($messages[0], 'sayamaapps.fude.language'), 'The message names the new key', $messages);
		} finally {
			restore_error_handler();
			cleanup($tmp);
		}
	}

	public function testTheDefaultAppliesWhenNeitherKeyIsSet(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.active' => null]);
		same('fallback', Options::get($kirby, 'nothing', 'fallback'), 'Neither key set: the default');
		same(null, Options::get($kirby, 'nothing'), 'Neither key set, no default: null');
		cleanup($tmp);
	}

	public function testTheNewKeyIsReadWhenTheOldOneIsNull(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.language' => null, 'sayamaapps.fude.language' => 'de']);
		same('de', Options::get($kirby, 'language'), 'Old key null counts as not set');
		cleanup($tmp);
	}

	public function testTheNewKeyWinsOverTheOldOne(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.language' => 'en', 'sayamaapps.fude.language' => 'de']);
		same('de', Options::get($kirby, 'language'), 'New key set: it wins');
		cleanup($tmp);
	}

	public function testTheOldKeyIsStillRead(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.language' => 'de']);
		same('de', Options::get($kirby, 'language'), 'Old key alone is read');
		same(true, Options::get($kirby, 'active', false), 'Old fixture key fude.active is read');
		cleanup($tmp);
	}
}
