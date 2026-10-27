<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

/**
 * The 1.x functions keep working until 2.0: fude_option() reads the old key
 * through Options::get(), fude_not_found() answers Kirby's own 404 page.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class LegacyTest extends TestCase
{
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
}
