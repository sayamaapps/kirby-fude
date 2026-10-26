<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GET /fude/schema: the declaration a reader may know, with the current
 * entries reduced to the declared text fields.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class SchemaTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			[$code, $json] = fude_call($app, 'schema');
			same([200, true, 1, 'example.invalid', 'en'], [$code, $json['ok'], $json['format'], $json['site'], $json['language']], 'Format, site, language');
			same(['team', 'aktuelles', 'ohnebild', 'news'], array_keys($json['collections']), 'All collections');
			$team = $json['collections']['team'];
			same(['structure', 'Team', 'People who work at the practice.', ['create', 'update'], 'name'], [$team['kind'], $team['label'], $team['description'], $team['actions'], $team['key']], 'Collection head');
			same(['name', 'rolle', 'abteilung', 'foto'], array_keys($team['fields']), 'Only declared fields — "intern" from the blueprint is not listed');
			same(['type' => 'text', 'label' => 'Name', 'required' => true, 'description' => 'Full name'], $team['fields']['name'], 'Text field');
			same(60, $team['fields']['rolle']['maxlength'], 'maxlength from fude.php');
			same(['aerzte', 'empfang', 'labor'], $team['fields']['abteilung']['options'], 'Select options from the blueprint');
			same(['type' => 'image', 'label' => 'Portrait', 'required' => false, 'description' => '', 'maxWidth' => 800, 'formats' => ['jpg', 'png']], $team['fields']['foto'], 'Image rules, no template or alt source exposed');
			same([
				['name' => 'Jörg Muster', 'rolle' => 'Facharzt für Allgemeinmedizin', 'abteilung' => 'aerzte', 'foto' => false],
				['name' => 'Veronika Beispiel', 'rolle' => '', 'abteilung' => 'empfang', 'foto' => false],
			], $team['entries'], 'Current entries: text fields and image presence');
			same([], $json['collections']['aktuelles']['entries'], 'Empty structure: no entries');
			same(1200, $json['collections']['aktuelles']['fields']['bild']['maxWidth'], 'Image maxWidth from fude.php');
			same(['jpg', 'png', 'webp'], $json['collections']['aktuelles']['fields']['bild']['formats'], 'Default formats');
			same('pages', $json['collections']['news']['kind'], 'Pages collection');
			same([], $json['collections']['news']['entries'], 'No child pages yet');
			same(true, str_contains(fude_call($app, 'schema')[3], '"fields": {'), 'Fields as an object, never a list');
			[$code, $json] = fude_call($app, 'schema', options: ['locale' => 'de_DE.utf8', 'fude.siteId' => 'praxis']);
			same(['de', 'praxis'], [$json['language'], $json['site']], 'Language from the locale, site id from the configuration');
			same(1, count(fude_log($tmp)) >= 1 ? 1 : 0, 'Schema calls are logged');
			same(['route' => 'schema', 'user' => FUDE_BOT, 'status' => 200], array_intersect_key(fude_log($tmp)[0], ['route' => 1, 'user' => 1, 'status' => 1]), 'Log line');
		} finally {
			cleanup($tmp);
		}
	}
}
