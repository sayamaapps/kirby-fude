<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Blueprints;
use Sayamaapps\Fude\Config;
use Sayamaapps\Fude\Content;
use Sayamaapps\Fude\Drafts;
use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\I18n;
use Sayamaapps\Fude\Images;
use Sayamaapps\Fude\Log;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;
use Sayamaapps\Fude\Validator;

/**
 * The class map in autoload.php matches src/: one entry per file, the file
 * name is the class name, and every class loads through the map.
 */
#[CoversClass(Blueprints::class)]
#[CoversClass(Config::class)]
#[CoversClass(Content::class)]
#[CoversClass(Drafts::class)]
#[CoversClass(FudeError::class)]
#[CoversClass(I18n::class)]
#[CoversClass(Images::class)]
#[CoversClass(Log::class)]
#[CoversClass(Options::class)]
#[CoversClass(Service::class)]
#[CoversClass(Validator::class)]
final class AutoloadTest extends TestCase
{
	public function testEveryEntryLoadsItsClass(): void
	{
		$root = dirname(__DIR__);
		foreach (require $root . '/autoload.php' as $class => $path) {
			$this->assertFileExists($root . '/' . $path);
			$this->assertTrue(class_exists($class), 'class ' . $class . ' loads through the map');
		}
	}

	public function testLegacyNamesAreAliasesOfTheNewClasses(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		foreach (require dirname(__DIR__) . '/autoload.php' as $class => $path) {
			$short = substr($class, strlen('sayamaapps\\fude\\'));
			// DraftContext is new in 2.x and has no 1.x name.
			if ($short === 'draftcontext') {
				continue;
			}
			$this->assertSame(
				strtolower('Sayamaapps\\Fude\\' . $short),
				strtolower((new ReflectionClass('Fude\\' . $short))->getName()),
				'Fude\\' . $short . ' is an alias of the new class'
			);
		}
	}

	public function testMapListsEveryClassFileAndNothingElse(): void
	{
		$root = dirname(__DIR__);
		$map = require $root . '/autoload.php';

		$expected = [];
		foreach (glob($root . '/src/*.php') as $file) {
			$expected[strtolower('sayamaapps\\fude\\' . basename($file, '.php'))] = 'src/' . basename($file);
		}

		$this->assertSame($expected, $map);
	}
}
