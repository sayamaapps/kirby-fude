<?php
/**
 * Shared fixture for the route tests: a throwaway Kirby site with two
 * structure collections (team on a location page, news items on the home
 * page) and one pages collection (notes under a parent), the plugin
 * symlinked into site/plugins/, a bot account and an admin account.
 *
 * Loaded by tests/bootstrap.php. Kirby loads index.php only once per
 * process, so the FIRST app of a process decides whether the role `fude`
 * exists — the fixture always builds it with fude.active on.
 */

use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Assert;
use Sayamaapps\Fude\I18n;
use Sayamaapps\Fude\Service;

$base = dirname(__DIR__);

// Kirby logs failed logins via error_log (auth.debug); keep that out of the
// test output and make it checkable.
$GLOBALS['fudeLogFile'] = sys_get_temp_dir() . '/fude-test-log-' . bin2hex(random_bytes(6)) . '.txt';
ini_set('error_log', $GLOBALS['fudeLogFile']);
register_shutdown_function(static function (): void {
	if (is_file($GLOBALS['fudeLogFile'])) {
		unlink($GLOBALS['fudeLogFile']);
	}
});

/** Asserts that two values are identical (===), with a label. */
function same(mixed $expected, mixed $actual, string $what): void
{
	Assert::assertSame($expected, $actual, $what);
}

/** Asserts that a condition holds, with a label. */
function ok(bool $condition, string $what, mixed $detail = null): void
{
	Assert::assertTrue($condition, $detail === null ? $what : $what . ': ' . var_export($detail, true));
}

/** Asserts that $f throws $class with a message containing $pattern. */
function throws(callable $f, string $pattern, string $what, string $class = InvalidArgumentException::class): void
{
	try {
		$f();
	} catch (Throwable $e) {
		Assert::assertInstanceOf($class, $e, $what);
		Assert::assertStringContainsString($pattern, $e->getMessage(), $what);

		return;
	}
	Assert::fail($what . ': no exception');
}

/** Removes a fixture site (symlinks are removed, not followed). */
function cleanup(string $path): void
{
	Dir::remove($path);
}

const FUDE_BOT = 'fude@example.invalid';
const FUDE_PW = 'fude-password-very-long-123';
const FUDE_ADMIN = 'admin@example.invalid';
const FUDE_ADMIN_PW = 'admin-password-very-long-123';

/**
 * Builds the site and returns [$tmp, $app] where $app(array $request = [],
 * array $options = []) creates a fresh Kirby instance on it.
 */
function fude_fixture(array $extraOptions = []): array
{
	global $base;
	$tmp = sys_get_temp_dir() . '/fude-' . bin2hex(random_bytes(6));
	foreach (['public', 'site/plugins', 'site/templates', 'site/config', 'site/accounts', 'site/blueprints/pages', 'site/blueprints/files',
		'content/home', 'content/standort', 'content/aktuelles', 'content/error', 'storage'] as $dir) {
		mkdir($tmp . '/' . $dir, 0700, true);
	}
	symlink($base, $tmp . '/site/plugins/fude');
	file_put_contents($tmp . '/content/site.txt', "Title: Praxis Muster\n");
	file_put_contents($tmp . '/content/home/home.txt', "Title: Startseite\n\n----\n\nLeitsatz: Willkommen\n\n----\n\nMeldungen: \n");
	file_put_contents($tmp . '/content/standort/standort.txt', <<<TXT
    Title: Standort

    ----

    Kurzname: Standort

    ----

    Team:

    - name: "Jörg Muster"
      rolle: "Facharzt für Allgemeinmedizin"
      abteilung: aerzte
    - name: "Veronika Beispiel"
      abteilung: empfang

    ----

    Einleitung: Unser Haus in Musterstadt.
    TXT);
	file_put_contents($tmp . '/content/aktuelles/aktuelles.txt', "Title: Aktuelles\n");
	file_put_contents($tmp . '/content/error/error.txt', "Title: Error\n");

	file_put_contents($tmp . '/site/blueprints/pages/default.yml', "title: Page\nfields:\n  text:\n    type: textarea\n");
	file_put_contents($tmp . '/site/blueprints/pages/home.yml', <<<YML
    title: Startseite
    fields:
      leitsatz:
        label: Große Überschrift
        type: text
        required: true
      meldungen:
        label: Aktuelles
        type: structure
        fields:
          titel:
            label: Überschrift
            type: text
            minlength: 3
            maxlength: 80
          text:
            label: Text
            type: textarea
            required: true
            maxlength: 400
          bild:
            label: Bild
            type: files
            multiple: false
            query: page.images
            uploads:
              template: bild
    YML);
	file_put_contents($tmp . '/site/blueprints/pages/standort.yml', <<<YML
    title: Standort
    fields:
      kurzname:
        label: Kurzname
        type: text
        required: true
      team:
        label: Wer hier arbeitet
        type: structure
        fields:
          name:
            label: Name
            type: text
            required: true
          rolle:
            label: Rolle
            type: text
          abteilung:
            label: Abteilung
            type: select
            options:
              aerzte: Ärzte
              empfang: Empfang
              labor: Labor
          foto:
            label: Portrait
            type: files
            multiple: false
            query: page.images
            uploads:
              template: bild
          intern:
            label: Interne Notiz
            type: text
          preis:
            label: fixture.preis.label
            type: number
            min: 0
            max: 999
      einleitung:
        label: Einleitung
        type: textarea
      datum:
        label: Stand
        type: date
    YML);
	file_put_contents($tmp . '/site/blueprints/pages/aktuelles.yml', "title: Aktuelles\nsections:\n  notes:\n    type: pages\n    templates:\n      - note\n");
	file_put_contents($tmp . '/site/blueprints/pages/note.yml', <<<YML
    title: Meldung
    fields:
      text:
        label: Text
        type: textarea
        required: true
        maxlength: 300
      kategorie:
        label: Kategorie
        type: select
        options:
          - praxis
          - urlaub
      bild:
        label: Bild
        type: files
        multiple: false
        uploads:
          template: bild
    YML);
	file_put_contents($tmp . '/site/blueprints/files/bild.yml', "title: Bild\naccept:\n  mime: image/jpeg, image/png, image/webp\nfields:\n  alt:\n    label: Bildbeschreibung\n    type: text\n    required: true\n");
	file_put_contents($tmp . '/site/templates/default.php', '<?php echo "Fixture ", $page->title();');
	file_put_contents($tmp . '/site/templates/standort.php', '<?php echo "TEAM:"; foreach ($page->team()->toStructure() as $r) { echo $r->name(), "|", $r->rolle(), "|", $r->abteilung(), "|", $r->foto()->toFile()?->filename(), ";"; }');
	file_put_contents($tmp . '/site/templates/home.php', '<?php echo "NEWS:"; foreach ($page->meldungen()->toStructure() as $r) { echo $r->titel(), "|", $r->text(), "|", $r->bild()->toFile()?->filename(), ";"; }');
	file_put_contents($tmp . '/site/templates/note.php', '<?php echo "NOTE:", $page->title(), "|", $page->text(), "|", $page->kategorie(), "|", $page->bild()->toFile()?->filename();');
	file_put_contents($tmp . '/site/templates/error.php', '<?php echo "Fixture 404";');
	file_put_contents($tmp . '/site/config/fude.php', <<<'PHP'
    <?php
    return [
        'collections' => [
            'team' => [
                'kind' => 'structure',
                'page' => 'standort',
                'field' => 'team',
                'key' => 'name',
                'label' => 'Team',
                'description' => 'People who work at the practice.',
                'fields' => [
                    'name' => ['type' => 'text', 'required' => true, 'description' => 'Full name'],
                    'rolle' => ['type' => 'text', 'maxlength' => 60],
                    'abteilung' => ['type' => 'select'],
                    'foto' => ['type' => 'image', 'maxWidth' => 800, 'formats' => ['jpg', 'png'], 'alt' => 'name'],
                ],
            ],
            'aktuelles' => [
                'kind' => 'structure',
                'page' => 'home',
                'field' => 'meldungen',
                'key' => 'titel',
                'fields' => [
                    'titel' => ['type' => 'text', 'required' => true],
                    'text' => ['type' => 'textarea'],
                    'bild' => ['type' => 'image', 'maxWidth' => 1200],
                ],
            ],
            'ohnebild' => [
                'kind' => 'structure',
                'page' => 'home',
                'field' => 'meldungen',
                'key' => 'titel',
                'actions' => ['create'],
                'fields' => [
                    'titel' => ['type' => 'text', 'required' => true],
                    'text' => ['type' => 'textarea', 'required' => true],
                ],
            ],
            'news' => [
                'kind' => 'pages',
                'parent' => 'aktuelles',
                'template' => 'note',
                'status' => 'listed',
                'key' => 'title',
                'actions' => ['create', 'update'],
                'fields' => [
                    'title' => ['type' => 'text', 'required' => true],
                    'text' => ['type' => 'textarea'],
                    'kategorie' => ['type' => 'select', 'options' => ['praxis', 'urlaub']],
                    'bild' => ['type' => 'image'],
                ],
            ],
        ],
    ];
    PHP);

	$baseOptions = [
		'url' => 'http://example.invalid',
		'content.salt' => 'fixture-salt',
		'fude.active' => true,
		'fude.withoutHttps' => true,
	] + $extraOptions;
	// A plugin whose blueprint label is a translation key.
	@mkdir($tmp . '/site/plugins/labels', 0775, true);
	file_put_contents($tmp . '/site/plugins/labels/index.php', "<?php\nKirby::plugin('test/labels', ['translations' => ['en' => ['fixture.preis.label' => 'Price'], 'de' => ['fixture.preis.label' => 'Preis']]]);\n");
	// The optional `server` key of $request is the connection ($_SERVER as seen
	// by Kirby: HTTPS, SERVER_PORT, X-Forwarded-Proto); never the test runner's own.
	$app = function (array $request = [], array $options = []) use ($tmp, $baseOptions): Kirby\Cms\App {
		$server = $request['server'] ?? [];
		unset($request['server']);
		return new Kirby\Cms\App([
			'roots' => [
				'index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content',
				'site' => $tmp . '/site', 'storage' => $tmp . '/storage', 'accounts' => $tmp . '/site/accounts',
			],
			'request' => $request,
			'server' => $server,
			'options' => array_merge($baseOptions, $options),
		]);
	};
	$kirby = $app();
	$kirby->impersonate('kirby');
	$kirby->users()->create(['email' => FUDE_BOT, 'role' => Service::ROLE, 'password' => FUDE_PW]);
	$kirby->users()->create(['email' => FUDE_ADMIN, 'role' => 'admin', 'password' => FUDE_ADMIN_PW]);
	I18n::setLanguage('en');
	return [$tmp, $app];
}

/**
 * One call of a route. $body is the JSON object (POST) or query (GET);
 * $files mocks $_FILES. Returns [status, decoded JSON, headers, raw body].
 */
function fude_call(callable $app, string $route, array|null $body = null, string|null $who = FUDE_BOT, string $pw = FUDE_PW, array $options = [], array $files = [], string $scheme = 'http', array $server = []): array
{
	$method = $route === 'schema' ? 'GET' : 'POST';
	$request = ['method' => $method, 'url' => $scheme . '://example.invalid/fude/' . $route];
	// The connection: https means the server's HTTPS flag, unless $server says otherwise.
	$request['server'] = ($scheme === 'https' ? ['HTTPS' => 'on', 'SERVER_PORT' => 443] : []) + $server;
	if ($method === 'GET') {
		$request['query'] = $body ?? [];
	} else {
		$request['body'] = $body ?? [];
	}
	if ($files !== []) {
		$request['files'] = $files;
	}
	if ($who !== null) {
		$request['auth'] = 'Basic ' . base64_encode("$who:$pw");
	}
	$kirby = $app($request, $options);
	$response = Service::respond($kirby, new DateTimeImmutable('2026-10-09 09:00', new DateTimeZone('Europe/Berlin')), $route);
	return [$response->code(), json_decode($response->body(), true), $response->headers(), $response->body(), $kirby];
}

/** A PNG upload of the given size, as one $_FILES entry. */
function fude_upload(string $tmp, int $width = 1600, int $height = 1000, string $format = 'png', string $name = 'foto'): array
{
	$file = $tmp . '/upload-' . bin2hex(random_bytes(3)) . '.' . $format;
	$image = imagecreatetruecolor($width, $height);
	imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 60, 60));
	match ($format) {
		'png' => imagepng($image, $file),
		'jpg' => imagejpeg($image, $file, 80),
		'gif' => imagegif($image, $file),
		'webp' => imagewebp($image, $file, 80),
	};
	return ['name' => $name . '.' . $format, 'type' => 'application/octet-stream', 'tmp_name' => $file, 'error' => 0, 'size' => filesize($file)];
}

/**
 * Writes its own declaration (site/config/fude-<name>.php) and returns the
 * options that point the next app at it. The shared fixture stays as it is,
 * so the other tests keep seeing the same collections.
 */
function fude_declare(string $tmp, string $name, string $php): array
{
	$file = 'site/config/fude-' . $name . '.php';
	file_put_contents($tmp . '/' . $file, "<?php\nreturn " . $php . ";\n");
	return ['fude.config' => $file];
}

/** Reads the draft log as decoded lines. */
function fude_log(string $tmp): array
{
	$file = $tmp . '/storage/fude/log.jsonl';
	if (!is_file($file)) {
		return [];
	}
	return array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", file_get_contents($file))));
}
