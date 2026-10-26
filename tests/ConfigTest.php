<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * site/config/fude.php: every mistake in the declaration is loud (500 with
 * the reason) — a field the blueprint does not know, an incompatible type,
 * a key that is not a text field, unknown kinds, actions, formats.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ConfigTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			$configs = 0;
			$with = function (string $php) use ($tmp, $app, &$configs) {
				$file = 'site/config/fude-' . (++$configs) . '.php';
				file_put_contents($tmp . '/' . $file, "<?php\nreturn " . $php . ";\n");
				[$code, $json] = fude_call($app, 'schema', options: ['fude.config' => $file]);
				return [$code, $json['error'] ?? null];
			};
			$team = fn (string $fields, string $extra = '') => "['collections' => ['team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', $extra 'fields' => [$fields]]]]";

			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: no collections: mark fields with `fude: true` in a blueprint or declare them in site/config/fude.php'], $with('[]'), 'No collections');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: no collections: mark fields with `fude: true` in a blueprint or declare them in site/config/fude.php'], $with("['collections' => []]"), 'Empty collections');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "geheim" is not in the blueprint'], $with($team("'name' => 'text', 'geheim' => 'text'")), 'Field not in the blueprint');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "foto" is declared as text but the blueprint says files'], $with($team("'name' => 'text', 'foto' => 'text'")), 'Incompatible type');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "abteilung" is declared as text but the blueprint says select'], $with($team("'name' => 'text', 'abteilung' => 'text'")), 'Select declared as text');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "abteilung" declares options the blueprint does not have'], $with($team("'name' => 'text', 'abteilung' => ['type' => 'select', 'options' => ['aerzte', 'chef']]")), 'Options outside the blueprint');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": "key" must name a declared text, select or date field'], $with($team("'name' => 'text'", "'key' => 'foto',")), 'Key must be text, select or date');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": "key" must name a declared text, select or date field'], $with($team("'abteilung' => 'select', 'foto' => 'image'")), 'No text field, no key');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": unknown kind "blocks"'], $with("['collections' => ['team' => ['kind' => 'blocks', 'fields' => ['name' => 'text']]]]"), 'Unknown kind');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": unknown action "archive"'], $with($team("'name' => 'text'", "'actions' => ['create', 'archive'],")), 'Unknown action');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "foto" format "bmp" (jpg, png, webp, gif)'], $with($team("'name' => 'text', 'foto' => ['type' => 'image', 'formats' => ['bmp']]")), 'Unknown image format');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "foto" maxWidth must be 16–8000'], $with($team("'name' => 'text', 'foto' => ['type' => 'image', 'maxWidth' => '1200']")), 'maxWidth must be an integer');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "foto" alt must name a declared text field'], $with($team("'name' => 'text', 'foto' => ['type' => 'image', 'alt' => 'rolle']")), 'alt must be declared');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": field "name" has unknown type "money"'], $with($team("'name' => 'money'")), 'Unknown type');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": "field" missing'], $with("['collections' => ['team' => ['page' => 'standort', 'fields' => ['name' => 'text']]]]"), 'Structure needs a field');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": page "nirgends" does not exist'], $with("['collections' => ['team' => ['page' => 'nirgends', 'field' => 'team', 'fields' => ['name' => 'text']]]]"), 'Page must exist');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "team": "kurzname" is not a structure field of "standort"'], $with("['collections' => ['team' => ['page' => 'standort', 'field' => 'kurzname', 'fields' => ['name' => 'text']]]]"), 'Field must be a structure');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "news": pages collections need a "title" field'], $with("['collections' => ['news' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'fields' => ['text' => 'textarea']]]]"), 'Pages need title');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection "news": status must be listed or unlisted'], $with("['collections' => ['news' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'status' => 'draft', 'fields' => ['title' => 'text']]]]"), 'Status');
			same([500, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: collection name "Team Standort" (a-z, 0-9, -, _)'], $with("['collections' => ['Team Standort' => ['page' => 'standort', 'field' => 'team', 'fields' => ['name' => 'text']]]]"), 'Collection name');
			[$code, $error] = $with("['collections' => ");
			same([500, true], [$code, str_starts_with((string)$error, 'The Fude configuration (blueprint markers, site/config/fude.php) is invalid: syntax error')], 'PHP error in the file is reported, not fatal');
			// The smallest valid declaration: text fields only, key = first text field.
			[$code, $error] = $with($team("'name' => 'text', 'rolle' => ['type' => 'text', 'maxlength' => 10]"));
			same([200, null], [$code, $error], 'Minimal declaration');
			$kirby = $app(options: ['fude.config' => 'site/config/fude-' . $configs . '.php']);
			$c = Fude\Config::load($kirby);
			same(['name', ['create', 'update'], ['fude'], 'Team', 10, true], [$c['team']['key'], $c['team']['actions'], $c['team']['publishRoles'], $c['team']['label'], $c['team']['fields']['rolle']['maxlength'], $c['team']['fields']['name']['required']], 'Defaults and the blueprint required flag');
			// Absolute config path.
			[$code] = fude_call($app, 'schema', options: ['fude.config' => $tmp . '/site/config/fude.php']);
			same(200, $code, 'Absolute path');
			// The key field is required even when the declaration does not say so.
			file_put_contents($tmp . '/site/config/fude-key.php', "<?php\nreturn ['collections' => ['team' => ['kind' => 'structure', 'page' => 'standort', 'field' => 'team', 'key' => 'rolle', 'fields' => ['name' => 'text', 'rolle' => 'text']]]];\n");
			$c = Fude\Config::load($app(options: ['fude.config' => 'site/config/fude-key.php']));
			same([true, 'rolle'], [$c['team']['fields']['rolle']['required'], $c['team']['key']], 'Key field forced to required');
			// German locale: the config error itself is translated, the reason not.
			$file = 'site/config/fude-de.php';
			file_put_contents($tmp . '/' . $file, "<?php\nreturn ['collections' => ['team' => ['kind' => 'x', 'fields' => []]]];\n");
			[$code, $json] = fude_call($app, 'schema', options: ['fude.config' => $file, 'locale' => 'de_DE.utf8']);
			same('Die Fude-Konfiguration (Markierungen in den Blueprints, site/config/fude.php) ist ungültig: collection "team": unknown kind "x"', $json['error'], 'German wrapper');
		} finally {
			cleanup($tmp);
		}
	}
}
