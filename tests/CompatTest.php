<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Compat;

/**
 * Under Kirby 5 the Kirby 6 type names mean nothing to Fude: a plugin field
 * type called `filepicker` or `pagelist` is not taken for files or a pages
 * section. Kirby caches blueprints and plugins once per PHP process, so
 * every case runs in a process of its own.
 */
#[CoversClass(Compat::class)]
#[RunTestsInSeparateProcesses]
final class CompatTest extends TestCase
{
	/** @return array<string, array{0: string, 1: string}> */
	public static function cases(): array
	{
		return [
			'filepicker in a structure' => [
				"title: Standort\nfields:\n  team:\n    type: structure\n    fude: true\n    fields:\n      name:\n        type: text\n      bild:\n        type: filepicker\n        fude: true\n",
				'page "standort", field "team", sub-field "bild": type "filepicker" is not supported by fude',
			],
			'pagelist as a page field' => [
				"title: Standort\nfields:\n  liste:\n    type: pagelist\n    fude: true\n",
				'page "standort", field "liste": type "pagelist" is not supported by fude',
			],
		];
	}

	#[DataProvider('cases')]
	public function testKirby5PluginTypesKeepTheirName(string $blueprint, string $error): void
	{
		if (fude_kirby6()) {
			$this->markTestSkipped('Kirby 6 has these field types itself');
		}
		Kirby\Cms\App::plugin('test/fremd', ['fields' => [
			'filepicker' => ['extends' => 'text'],
			'pagelist'   => ['extends' => 'text'],
		]]);
		same(['filepicker', 'pagelist'], [Compat::type('filepicker'), Compat::type('pagelist')], 'No Kirby 6 renaming under Kirby 5');

		[$tmp, $app] = fude_fixture();
		try {
			file_put_contents($tmp . '/site/blueprints/pages/standort.yml', $blueprint);
			$options = fude_declare($tmp, 'none', "['collections' => []]");
			[$code, $json] = fude_call($app, 'schema', options: $options);
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: ' . $error], [$code, $json['error'] ?? null], 'Plugin type rejected under its own name');
		} finally {
			cleanup($tmp);
		}
	}
}
