<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Options;

/**
 * Options are read as fude.<key>, as in 1.x. The key sayamaapps.fude.<key> is
 * not read in 1.x (it is planned for 2.0), and no runtime notice is printed
 * in debug mode.
 */
#[CoversClass(Options::class)]
#[RunTestsInSeparateProcesses]
final class OptionsPrefixTest extends TestCase
{
	public function testNullCountsAsNotSet(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.language' => null]);
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

	public function testTheDefaultAppliesWhenNotSet(): void
	{
		[$tmp, $app] = fude_fixture();
		$kirby = $app(options: ['fude.active' => null]);
		same('fallback', Options::get($kirby, 'nothing', 'fallback'), 'Not set: the default');
		same(null, Options::get($kirby, 'nothing'), 'Not set, no default: null');
		cleanup($tmp);
	}

	public function testTheNewKeyIsNotRead(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$kirby = $app(options: ['fude.language' => null, 'sayamaapps.fude.language' => 'de']);
			same(null, Options::get($kirby, 'language'), 'sayamaapps.fude.language is not read in 1.x');
			same('en', Options::get($kirby, 'language', 'en'), 'The default applies');
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
