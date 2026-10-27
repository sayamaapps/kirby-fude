<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Blueprints;

/**
 * Markers that cannot be honoured are loud: a site field marker, an unsupported
 * type, files on a page, a name collision, an unknown option, a touch field
 * the page does not have, too many collections, and a marked section that
 * is no plain pages section of one template. Kirby caches blueprints once per
 * PHP process, so every case runs in a process of its own (RunTestsInSeparateProcesses).
 */
const CASES = [
	'site' => [
		'files' => ['site.yml' => "title: Site\nfields:\n  titel:\n    type: text\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: site fields are not supported (field "titel" in site.txt carries a fude marker)',
	],
	'unsupported' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  kurzname:\n    type: tags\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "kurzname": type "tags" is not supported by fude',
	],
	'files-on-page' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  bild:\n    type: files\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "bild": files fields are only supported inside a structure or a marked pages section\'s template',
	],
	'unsupported-sub-field' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: true\n    fields:\n      stichworte:\n        type: tags\n        fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "team", sub-field "stichworte": type "tags" is not supported by fude',
	],
	'collision' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {name: x}\n    fields:\n      n:\n        type: text\n  zeiten:\n    type: structure\n    fude: {name: x}\n    fields:\n      n:\n        type: text\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: two marked fields would both become the collection "x": set fude: {name: …} on the structure field',
	],
	'unknown-option' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {foo: 1}\n    fields:\n      n:\n        type: text\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", field "team": unknown fude option "foo"',
	],
	'touch-missing-field' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  team:\n    type: structure\n    fude: {touch: {field: stand, value: today}}\n    fields:\n      n:\n        type: text\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "standort-team": touch field "stand" is not in the blueprint of "standort"',
	],
	'site-section' => [
		'files' => ['site.yml' => "title: Site\nsections:\n  seiten:\n    type: pages\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: site sections are not supported (section "seiten" in site.yml carries a fude marker)',
	],
	'section-type' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  bilder:\n    type: files\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "bilder": the fude marker is only for pages sections and fields',
	],
	'section-no-template' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "seiten": the section names no template; set fude: {template: …}',
	],
	'section-templates' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    templates: [note, default]\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "seiten": the section names several templates; set fude: {template: …}',
	],
	'section-template-other' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    templates: [note]\n    fude: {template: default}\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "seiten": template "default" is not one of the section\'s templates',
	],
	'section-parent' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    parent: site.find('aktuelles')\n    templates: [note]\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "seiten": a marked pages section lists the page\'s own children, without "parent"',
	],
	'section-option' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    templates: [note]\n    fude: {maxEntries: 3}\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort", section "seiten": unknown fude option "maxEntries"',
	],
	'section-twice' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  neu:\n    type: pages\n    templates: [note]\n    fude: true\n  alt:\n    type: pages\n    templates: [note]\n    fude: true\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: page "standort": two marked sections would both reach the template "note"; mark one',
	],
	'section-child-structure' => [
		'files' => [
			'pages/standort.yml' => "title: Standort\nsections:\n  kurse:\n    type: pages\n    templates: [kurs]\n    fude: true\n",
			'pages/kurs.yml' => "title: Kurs\nfields:\n  zeiten:\n    type: structure\n    fude: true\n    fields:\n      n:\n        type: text\n",
		],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: template "kurs", field "zeiten": type "structure" is not supported by fude',
	],
	'section-status' => [
		'files' => ['pages/standort.yml' => "title: Standort\nsections:\n  seiten:\n    type: pages\n    templates: [note]\n    fude: {status: draft}\n"],
		'options' => [],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "standort-seiten": status must be listed or unlisted',
	],
	'limit' => [
		'files' => ['pages/standort.yml' => "title: Standort\nfields:\n  kurzname:\n    type: text\n    fude: true\n  team:\n    type: structure\n    fude: true\n    fields:\n      n:\n        type: text\n"],
		'options' => ['fude.maxCollections' => 1],
		'error' => 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: 2 collections, at most 1 (fude.maxCollections; the bot reads them all)',
	],
];

/**
 * Markers that cannot be honoured are loud: a site field marker, an unsupported
 * type, files on a page, a name collision, an unknown option, a touch field
 * the page does not have, too many collections, and a marked section that
 * is no plain pages section of one template. Kirby caches blueprints once per PHP process, so every case
 * runs in a child process of its own (this file, with the case name as argument).
 */
#[CoversClass(Blueprints::class)]
#[RunTestsInSeparateProcesses]
final class BlueprintsErrorsTest extends TestCase
{
	/** @return array<string, array{0: string}> */
	public static function cases(): array
	{
		return array_map(fn (string $name): array => [$name], array_keys(CASES));
	}

	#[DataProvider('cases')]
	public function testErrorCase(string $name): void
	{
		$case = CASES[$name];
		[$tmp, $app] = fude_fixture();
		file_put_contents($tmp . '/site/blueprints/pages/standort.yml', "title: Standort\nfields: {}\n");
		foreach ($case['files'] as $path => $yaml) {
			file_put_contents($tmp . '/site/blueprints/' . $path, $yaml);
		}
		$options = fude_declare($tmp, 'none', "['collections' => []]");
		[$code, $json] = fude_call($app, 'schema', options: $case['options'] + $options);
		cleanup($tmp);
		same([500, $case['error']], [$code, $json['error'] ?? null], 'Error case ' . $name);
	}
}
