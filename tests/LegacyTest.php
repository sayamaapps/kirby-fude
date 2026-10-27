<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;

/**
 * The 1.x names keep working until 2.0: fude_option() reads the old key
 * through Options::get(), fude_not_found() answers Kirby's own 404 page, and
 * the 1.x exception name matches the thrown error in catch and instanceof.
 */
#[CoversClass(Service::class)]
#[CoversClass(Options::class)]
#[CoversClass(FudeError::class)]
#[RunTestsInSeparateProcesses]
final class LegacyTest extends TestCase
{
	/** The 1.x class names of the 1.x release, without the namespace. */
	private const ONE_X_CLASSES = [
		'Blueprints', 'Config', 'Content', 'Drafts', 'FudeError', 'I18n',
		'Images', 'Log', 'Options', 'Service', 'Validator',
	];

	/**
	 * `instanceof` and `catch` do not autoload, so every 1.x class name must
	 * exist as soon as legacy.php is loaded. This runs in a fresh process and
	 * names no 1.x class before the check.
	 */
	public function testEveryOneXClassNameExistsBeforeAnyUse(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';
		foreach (self::ONE_X_CLASSES as $short) {
			ok(class_exists('Fude\\' . $short, false), 'Fude\\' . $short . ' exists without autoload');
		}
	}

	public function testFudeNotFoundAnswersWithA404(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app();
			same(404, fude_not_found()->code(), '1.x 404 function');
			same(404, Service::notFound()->code(), 'Service::notFound()');
			same(404, Service::respondNow('nope')->code(), 'Unknown route through the entry point');
		} finally {
			cleanup($tmp);
		}
	}

	public function testFudeOptionReadsTheOldKeyWithADefault(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app(options: ['fude.maxCollections' => 7]);
			same(7, fude_option('maxCollections', 30), 'Old key read by the 1.x function');
			same('dflt', fude_option('nonexistent', 'dflt'), 'Default when neither key is set');
		} finally {
			cleanup($tmp);
		}
	}

	/**
	 * A 1.x name answers `instanceof` for an object of the new class, and
	 * `catch` for the thrown error, with no earlier use of the 1.x name.
	 */
	public function testInstanceofAndCatchWorkWithOneXNamesBeforeFirstUse(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';
		// FudeError takes a message and is checked by catch below.
		foreach (array_diff(self::ONE_X_CLASSES, ['FudeError']) as $short) {
			$new = 'Sayamaapps\\Fude\\' . $short;
			$old = 'Fude\\' . $short;
			$object = new $new();
			ok($object instanceof $old, $old . ' answers instanceof', $object);
		}

		$caught = null;
		try {
			throw new FudeError('Conflict', 409);
		} catch (\Fude\FudeError $e) {
			$caught = $e;
		}
		same(409, $caught?->status, 'catch Fude\\FudeError keeps the status');
	}

	/**
	 * `catch` and `instanceof` do not autoload. The 1.x name must therefore be
	 * an alias before the first throw, not one created on first use.
	 */
	public function testTheOneXExceptionNameCatchesTheError(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$app();
			$caught = null;
			try {
				throw new FudeError('Conflict', 409);
			} catch (\Fude\FudeError $e) {
				$caught = $e;
			}
			ok($caught instanceof \Fude\FudeError, 'instanceof Fude\FudeError', $caught);
			same(409, $caught?->status, 'The 1.x name keeps the status');
			same(FudeError::class, $caught !== null ? $caught::class : null, 'Both names are one class');
		} finally {
			cleanup($tmp);
		}
	}
}
