<?php

use Kirby\Form\Form;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Blueprints;
use Sayamaapps\Fude\Config;

const PS_KURSE = <<<'YML'
    title: Kurse
    columns:
      main:
        width: 2/3
        sections:
          entwuerfe:
            label: Entwürfe
            type: pages
            status: draft
            templates: [kurs]
          kurse:
            label: Kurse
            type: pages
            status: published
            templates: [kurs]
            help: "<p>All <b>courses</b></p>"
            fude:
              key: datum
              description:
                en: Courses of the school, found by their date.
                de: Kurse der Schule, gefunden über ihr Datum.
      sidebar:
        width: 1/3
        fields:
          einleitung:
            label: Einleitung
            type: textarea
    YML;

const PS_KURS = <<<'YML'
    title: Kurs
    fields:
      datum:
        label: Datum
        type: date
        required: true
        fude: true
      beginn:
        label: Beginn
        type: time
        fude:
          description:
            en: Start time
            de: Beginn
      ort:
        label: Ort
        type: pages
        multiple: false
        query: site.find('orte').children
        fude: true
      preis:
        label: Preis
        type: number
        min: 0
        fudeAsk: true
        fude: true
      anmeldung:
        label: Anmeldung
        type: url
        fude: true
      hinweis:
        label: Hinweis
        type: text
        help:
          en: "<p>Shown <b>below</b> the date</p>"
          de: Steht unter dem Datum
        fude: true
      bild:
        label: Bild
        type: files
        multiple: false
        uploads:
          template: bild
        fude:
          maxWidth: 800
      text:
        label: Beschreibung
        type: blocks
      schlagworte:
        label: Schlagworte
        type: tags
      intern:
        label: Intern
        type: text
      versteckt:
        label: Versteckt
        type: text
        fude: false
    YML;

const PS_DECLARED = <<<'PHP'
    ['collections' => [
        'kurse-von-hand' => [
            'kind' => 'pages', 'parent' => 'kurse', 'template' => 'kurs', 'key' => 'datum',
            'label' => 'Kurse', 'description' => 'Courses of the school, found by their date.',
            'fields' => [
                'title' => 'text',
                'datum' => 'date',
                'beginn' => ['type' => 'time', 'description' => 'Start time'],
                'ort' => 'page',
                'preis' => 'number',
                'anmeldung' => 'text',
                'hinweis' => ['type' => 'text', 'description' => 'Shown below the date'],
                'bild' => ['type' => 'image', 'maxWidth' => 800],
            ],
        ],
    ]]
    PHP;

/**
 * A marker on a `pages` section of a parent blueprint: one pages collection
 * of the parent's children with the section's template, its fields from the
 * marked fields of the child blueprint. The children get no collection of
 * their own, so maxCollections holds however many there are. Covers the
 * derived shape, the same schema as the equivalent fude.php declaration, the
 * published entries with all field values (a new course "like the last one,
 * new date"), draft → publish, the fude.php rules (a declaration for the same
 * parent and template wins, false removes), translated texts, and the Panel
 * loading such blueprints. The pages "kurse" and "orte" live in this file only.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class PagesSectionTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			foreach (['orte/1_atelier', 'orte/2_saal', 'kurse'] as $dir) {
				mkdir($tmp . '/content/' . $dir, 0700, true);
			}
			file_put_contents($tmp . '/content/orte/orte.txt', "Title: Orte\n");
			file_put_contents($tmp . '/content/orte/1_atelier/default.txt', "Title: Atelier Musterstadt\n\n----\n\nUuid: atelieruuid00001\n");
			file_put_contents($tmp . '/content/orte/2_saal/default.txt', "Title: Saal\n\n----\n\nUuid: saaluuid000000001\n");
			file_put_contents($tmp . '/content/kurse/kurse.txt', "Title: Kurse\n\n----\n\nEinleitung: Unsere Kurse.\n");
			// Six published courses, more than maxCollections allows below: each would be a
			// collection of its own if the child markers made page collections.
			for ($i = 1; $i <= 6; $i++) {
				mkdir($tmp . "/content/kurse/$i" . "_kurs-$i", 0700, true);
				file_put_contents($tmp . "/content/kurse/$i" . "_kurs-$i/kurs.txt", implode("\n\n----\n\n", [
					'Title: Aquarell am Morgen',
					'Datum: 2026-0' . $i . '-15',
					'Beginn: 10:00:00',
					'Ort:' . "\n\n" . '- page://atelieruuid00001',
					'Preis: 45.5',
					'Anmeldung: https://example.com/anmeldung',
					'Hinweis: Material inklusive',
					'Text: [{"type":"text","content":{"text":"Lang"}}]',
					'Intern: geheim',
				]) . "\n");
			}
			// A draft course is never an entry.
			mkdir($tmp . '/content/kurse/_drafts/entwurf', 0700, true);
			file_put_contents($tmp . '/content/kurse/_drafts/entwurf/kurs.txt', "Title: Entwurf\n\n----\n\nDatum: 2026-12-01\n");
			file_put_contents($tmp . '/site/blueprints/pages/kurse.yml', PS_KURSE);
			file_put_contents($tmp . '/site/blueprints/pages/kurs.yml', PS_KURS);
			file_put_contents($tmp . '/site/templates/kurs.php', '<?php echo "KURS:", $page->title(), "|", $page->datum(), "|", $page->ort()->toPage()?->title();');

			$none = fude_declare($tmp, 'none', "['collections' => []]");

			// (a) One collection for the section; none for the six children, none for the
			// unmarked drafts section. maxCollections 1 holds.
			same(['kurse-kurse'], array_keys(Blueprints::collections($app())), 'One collection from the marked section, none per child');
			$c = Config::load($app(options: $none + ['fude.maxCollections' => 1]))['kurse-kurse'];
			same(
				['pages', 'kurse', 'kurs', 'listed', 'datum', ['create', 'update'], 'Kurse', 'Courses of the school, found by their date.'],
				[$c['kind'], $c['parent'], $c['template'], $c['status'], $c['key'], $c['actions'], $c['label'], $c['description']],
				'Collection head from section and marker'
			);
			same(['title', 'datum', 'beginn', 'ort', 'preis', 'anmeldung', 'hinweis', 'bild'], array_keys($c['fields']), 'Title plus the marked child fields; unknown types, unmarked and fude: false stay out');
			same(['text', 'date', 'time', 'page', 'number', 'text', 'text', 'image'], array_values(array_map(fn ($f) => $f['type'], $c['fields'])), 'Child field types');
			same([true, true, false], [$c['fields']['title']['required'], $c['fields']['datum']['required'], $c['fields']['beginn']['required']], 'Title and key required');
			same([true, 'url', 800, 'bild'], [$c['fields']['preis']['ask'], $c['fields']['anmeldung']['format'], $c['fields']['bild']['maxWidth'], $c['fields']['bild']['template']], 'fudeAsk, format and image options from the child blueprint');
			same(['Start time', 'Shown below the date'], [$c['fields']['beginn']['description'], $c['fields']['hinweis']['description']], 'Translated marker description and help text, in the reply language');

			// (b) The schema is the one of the same collection declared in fude.php.
			[$code, $derived] = fude_call($app, 'schema', options: $none);
			same(200, $code, 'Schema with a marked section', $derived['error'] ?? null);
			[$code, $declared] = fude_call($app, 'schema', options: fude_declare($tmp, 'declared', PS_DECLARED) + ['fude.blueprints' => false]);
			same(200, $code, 'Schema of the declaration', $declared['error'] ?? null);
			same($declared['collections']['kurse-von-hand'], $derived['collections']['kurse-kurse'], 'Derived and declared collection: the same schema');

			// (c) The entries carry every declared value of the published children, so a new
			// course "like the last one, new date" is a create with those values.
			$entries = $derived['collections']['kurse-kurse']['entries'];
			same(6, count($entries), 'Six published courses, no draft');
			same(['title' => 'Aquarell am Morgen', 'datum' => '2026-06-15', 'beginn' => '10:00:00', 'ort' => 'Atelier Musterstadt', 'preis' => '45.5',
				'anmeldung' => 'https://example.com/anmeldung', 'hinweis' => 'Material inklusive', 'bild' => false], $entries[5], 'Entry with all declared values');
			$again = ['datum' => '2026-11-15'] + array_diff_key($entries[5], ['bild' => true]);
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'kurse-kurse', 'action' => 'create', 'fields' => $again], options: $none);
			same(200, $code, 'Create with the last course\'s values and a new date', $json);
			$child = $app()->page($json['draft']['page']);
			same(
				[true, 'kurs', '2026-11-15', '- page://atelieruuid00001', '45.5'],
				[$child->isDraft(), $child->intendedTemplate()->name(), $child->datum()->value(), $child->version('latest')->read()['ort'] ?? null, $child->preis()->value()],
				'Draft child with the copied values'
			);
			[$code, $j] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $none);
			same(200, $code, 'Published', $j);
			same(['listed', 'KURS:Aquarell am Morgen|2026-11-15|Atelier Musterstadt'], [$app()->page($json['draft']['page'])->status(), $app()->page($json['draft']['page'])->render()], 'Listed and rendered');
			same(7, count(fude_call($app, 'schema', options: $none)[1]['collections']['kurse-kurse']['entries']), 'Seven entries now');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'kurse-kurse', 'action' => 'create', 'fields' => $again], options: $none);
			same(409, $code, 'The same date again: the key exists');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'kurse-kurse', 'action' => 'update', 'key' => '2026-11-15', 'fields' => ['ort' => 'Saal']], options: $none);
			same(200, $code, 'Update by the date key', $json);
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']], options: $none)[0], 'Discarded');
			[$code, $j] = fude_call($app, 'draft', ['collection' => 'kurse-kurse', 'action' => 'delete', 'key' => '2026-11-15'], options: $none);
			same(422, $code, 'No delete unless the marker allows it');

			// (d) fude.php: a pages collection for the same parent and template stands in for
			// the derived one; false removes it; the children still get no collection.
			$c = Config::load($app(options: fude_declare($tmp, 'declared', PS_DECLARED)));
			same(['kurse-von-hand'], array_keys($c), 'A declaration for the same parent and template replaces the derived collection');
			$c = Config::load($app(options: fude_declare($tmp, 'off', "['collections' => ['kurse-kurse' => false, 'news' => ['kind' => 'pages', 'parent' => 'aktuelles', 'template' => 'note', 'fields' => ['title' => 'text']]]]")));
			same(['news'], array_keys($c), 'false removes the derived collection; the children stay without one');
			$c = Config::load($app(options: fude_declare($tmp, 'rename', "['collections' => ['kurse-kurse' => ['kind' => 'pages', 'parent' => 'kurse', 'template' => 'kurs', 'actions' => ['create', 'update', 'delete'], 'fields' => ['title' => 'text', 'datum' => 'date']]]]")));
			same([['title', 'datum'], ['create', 'update', 'delete']], [array_keys($c['kurse-kurse']['fields']), $c['kurse-kurse']['actions']], 'Same name: replaced completely');

			// (e) German replies: translated description and help.
			[$code, $json] = fude_call($app, 'schema', options: $none + ['fude.language' => 'de']);
			$c = $json['collections']['kurse-kurse'];
			same(
				['Kurse der Schule, gefunden über ihr Datum.', 'Beginn', 'Steht unter dem Datum'],
				[$c['description'], $c['fields']['beginn']['description'], $c['fields']['hinweis']['description']],
				'German texts'
			);
			Sayamaapps\Fude\I18n::setLanguage('en');

			// (f) Panel: the marked section and the child blueprint still load.
			$kirby = $app();
			$kirby->impersonate('kirby');
			$kurse = $kirby->page('kurse');
			same('datum', $kurse->blueprint()->toArray()['tabs']['main']['columns']['main']['sections']['kurse']['fude']['key'] ?? null, 'Marker survives in the section');
			$section = $kurse->blueprint()->section('kurse');
			same([7, 1], [$section?->total(), $kurse->blueprint()->section('entwuerfe')?->total()], 'Panel sections list the courses and the draft');
			ok(Form::for($kurse)->fields()->has('einleitung'), 'Panel form loads the parent');
			ok(Form::for($kurse->children()->first())->fields()->has('datum'), 'Panel form loads a child');
		} finally {
			cleanup($tmp);
		}
	}
}
