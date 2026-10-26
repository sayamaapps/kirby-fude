<?php

use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\Validator;
use Kirby\Text\KirbyTag;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Text is plain text or Markdown, never HTML: sites render textareas with
 * KirbyText, which passes raw HTML through. A tag, a comment, a script scheme
 * or an entity that could hide one is refused, while Markdown, KirbyTags with
 * https links and a plain "<" in prose pass. KirbyText renders KirbyTags too:
 * the gist tag (a script) and a style attribute (inline CSS) are refused.
 * Blueprint url, email and tel fields are checked by their format before the
 * Panel's own rules see them.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class MarkupTest extends TestCase
{
	public function testBehaviour(): void
	{
		try {
			[$tmp, $app] = fude_fixture();
			file_put_contents($tmp . '/site/blueprints/pages/standort.yml', <<<'YML'
            title: Standort
            fields:
              website:
                label: Web
                type: url
              telefon:
                label: Telefon
                type: tel
              mail:
                label: Mail
                type: email
              text:
                label: Text
                type: textarea
            YML);
			$options = fude_declare($tmp, 'markup', <<<'PHP'
            ['collections' => [
                'profil' => ['kind' => 'page', 'page' => 'standort', 'fields' => ['website' => 'text', 'telefon' => 'text', 'mail' => 'text', 'text' => 'textarea']],
            ]]
            PHP);

			// C1: markup in text, textarea and select values.
			$check = fn (string $type, string $value) => Validator::check(['fields' => ['t' => ['type' => $type, 'required' => false, 'options' => ['a', 'b'], 'maxlength' => 4000]]], ['t' => $value], 'update')['t'];
			$html = 'Field "t" takes plain text or Markdown, no HTML and no script links.';
			foreach (['<b>fett</b>', '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '</p>', '<?php echo 1; ?>', '<!-- versteckt -->', '<!DOCTYPE html>', 'Text <a href="x">Link</a>'] as $bad) {
				throws(fn () => $check('textarea', $bad), $html, 'Tag or comment refused: ' . $bad, FudeError::class);
			}
			throws(fn () => $check('text', '<h1>Titel</h1>'), $html, 'Tag in a text field', FudeError::class);
			throws(fn () => $check('select', '<b>a</b>'), $html, 'Tag in a select value', FudeError::class);
			foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'java script: alert(1)', 'vbscript:msgbox(1)', 'data:text/html,x', 'data :x', 'Text javascript:x'] as $bad) {
				throws(fn () => $check('textarea', $bad), $html, 'Script scheme refused: ' . $bad, FudeError::class);
			}
			foreach (['java&#x73;cript:alert(1)', '&#60;b&#62;', 'x&colon;y', 'Text &#x3C;'] as $bad) {
				throws(fn () => $check('textarea', $bad), $html, 'Entity refused: ' . $bad, FudeError::class);
			}
			// KirbyTags: the gist tag renders a <script src>, in any case, nested in a
			// caption, and whatever its URL. Spaces around the name are refused too.
			$script = 'Field "t" takes no KirbyTag that loads a script, such as (gist: …).';
			$gists = [
				'(gist: https://gist.github.com/example-user/abc)',
				'(GIST: https://gist.github.com/example-user/abc)',
				'(Gist:https://gist.github.com/example-user/abc)',
				'( gist : https://gist.github.com/example-user/abc)',
				'(gist: https://other.example\@gist.github.com/abc)',
				'(gist: https://gist.github.com/example-user/abc file: a.js)',
				'Vorher (gist: https://gist.github.com/example-user/abc) nachher',
				'(image: https://example.com/bild.jpg caption: (gist: https://gist.github.com/example-user/abc))',
			];
			foreach ($gists as $bad) {
				throws(fn () => $check('textarea', $bad), $script, 'gist refused: ' . $bad, FudeError::class);
				throws(fn () => $check('text', $bad), $script, 'gist refused in a text field: ' . $bad, FudeError::class);
			}
			throws(fn () => $check('textarea', "(gist:\nhttps://gist.github.com/example-user/abc)"), $script, 'gist with a line break refused', FudeError::class);
			// An alias resolves as in KirbyTag::parse().
			KirbyTag::$aliases['schnipsel'] = 'gist';
			try {
				throws(fn () => $check('textarea', '(schnipsel: https://gist.github.com/example-user/abc)'), $script, 'Alias of gist refused', FudeError::class);
			} finally {
				unset(KirbyTag::$aliases['schnipsel']);
			}

			// A style attribute becomes inline CSS (video tag). Kirby splits a tag at
			// "style:" in any case and with no space before it, also in a nested tag.
			$style = 'Field "t" takes no style: attribute in a KirbyTag.';
			$styles = [
				'(video: https://example.com/clip.mp4 style: position: fixed)',
				'(video: https://example.com/clip.mp4 STYLE: position: fixed)',
				'(video: https://example.com/clip.mp4style: position: fixed)',
				'(image: https://example.com/bild.jpg style: position: fixed)',
				'(image: https://example.com/bild.jpg caption: (video: https://example.com/clip.mp4 style: position: fixed))',
				'(link: https://example.com text: Mehr style: color: red)',
			];
			foreach ($styles as $bad) {
				throws(fn () => $check('textarea', $bad), $style, 'style refused: ' . $bad, FudeError::class);
				throws(fn () => $check('text', $bad), $style, 'style refused in a text field: ' . $bad, FudeError::class);
			}
			throws(fn () => $check('textarea', "(video: https://example.com/clip.mp4\nstyle: position: fixed)"), $style, 'style on its own line refused', FudeError::class);

			// Plain text, Markdown and KirbyTags with https links pass; "<" in prose is no tag.
			$goods = ['Kinder < 6 Jahre', 'a <= b', 'Preis: 5 € > 3 €', '**fett** und _kursiv_', '[Praxis](https://example.com/kontakt)', '(link: https://example.com text: Mehr)', 'Mail: info@example.com', 'metadata: ok', 'Daten: 3 Stück', 'Zeile eins\nZeile zwei'];
			// The other KirbyTags pass as before, and "gist:" or "style:" outside a tag is prose.
			array_push(
				$goods,
				'(link: https://example.com text: Mehr class: button target: _blank)',
				'(email: info@example.com text: Schreiben Sie uns)',
				'(tel: +49 1234 567 text: Anrufen)',
				'(image: https://example.com/bild.jpg alt: Die Praxis caption: Unser Empfang)',
				'(file: preisliste.pdf text: Preisliste)',
				'(video: https://www.youtube.com/watch?v=abcdefghijk caption: Rundgang)',
				'(date: Y)',
				'Lifestyle: neu im Programm',
				'Gist: kurz gesagt',
				'(siehe Gist: unten)',
				'(Hinweis: Freestyle ab 18 Uhr)',
			);
			foreach ($goods as $good) {
				$good = str_replace('\n', "\n", $good);
				same($good, $check('textarea', $good), 'Accepted: ' . $good);
				// What passes renders with KirbyText as the sites do, without a script or inline CSS.
				$rendered = $app()->kirbytext($good);
				ok(preg_match('/<script|style=/i', $rendered) === 0, 'No script or style rendered: ' . $good, $rendered);
			}
			same('Kinder < 6 Jahre', $check('text', 'Kinder < 6 Jahre'), 'Plain < in a single-line field');

			// C2: blueprint formats, end to end over HTTP (a page collection on the Panel's blueprint).
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['website' => 'javascript:alert(1)']], options: $options);
			same([422, 'Field "website" must be a web address starting with https://.'], [$code, $json['error']], 'javascript: in a url field refused');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['website' => 'example.com']], options: $options);
			same(422, $code, 'url without scheme refused');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['mail' => 'keine Adresse']], options: $options);
			same([422, 'Field "mail" must be an e-mail address.'], [$code, $json['error']], 'Refused e-mail');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['telefon' => '12']], options: $options);
			same([422, 'Field "telefon" must be a phone number: digits, spaces, +, -, / and brackets, at least three digits.'], [$code, $json['error']], 'Too short tel');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['text' => '<script>alert(1)</script>']], options: $options);
			same([422, str_replace('"t"', '"text"', $html)], [$code, $json['error']], 'HTML over HTTP refused');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['text' => '(gist: https://gist.github.com/example-user/abc)']], options: $options);
			same([422, str_replace('"t"', '"text"', $script)], [$code, $json['error']], 'gist over HTTP refused');
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'profil', 'action' => 'update', 'fields' => ['text' => '(video: https://example.com/clip.mp4 style: position: fixed)']], options: $options);
			same([422, str_replace('"t"', '"text"', $style)], [$code, $json['error']], 'style over HTTP refused');
			same(false, $app()->page('standort')->version('changes')->exists(), 'Refused drafts write nothing');

			// The formats of the blueprint, as the validator reads them.
			$fmt = fn (string $format, string $value) => Validator::check(['fields' => ['f' => ['type' => 'text', 'required' => false, 'options' => [], 'format' => $format]]], ['f' => $value], 'update')['f'];
			$fmtRefused = fn (string $format, string $value, string $message) => throws(fn () => $fmt($format, $value), $message, "$format refused: $value", FudeError::class);
			same('https://example.com/kontakt', $fmt('url', 'https://example.com/kontakt'), 'https url');
			same('http://example.com', $fmt('url', 'http://example.com'), 'http url');
			same('', $fmt('url', ''), 'Empty url is not set');
			$urlMessage = 'Field "f" must be a web address starting with https://.';
			foreach (['javascript:alert(1)', 'example.com', 'https://', 'ftp://example.com', 'https://exa mple.com'] as $bad) {
				$fmtRefused('url', $bad, $urlMessage);
			}
			same('info@example.com', $fmt('email', 'info@example.com'), 'email');
			$mailMessage = 'Field "f" must be an e-mail address.';
			foreach (['info@', 'info at example.com', '@example.com', 'a@b@c.de'] as $bad) {
				$fmtRefused('email', $bad, $mailMessage);
			}
			throws(fn () => $check('text', "Jo\xffrg"), 'Field "t" must be a text.', 'Invalid UTF-8 is refused, not blanked', FudeError::class);
			same('+49 1234 567', $fmt('tel', '+49 1234 567'), 'tel with plus and spaces');
			same('01234/567-0', $fmt('tel', '01234/567-0'), 'tel with slash and dash');
			same('(01234) 567', $fmt('tel', '(01234) 567'), 'tel with brackets');
			$telMessage = 'Field "f" must be a phone number: digits, spaces, +, -, / and brackets, at least three digits.';
			foreach (['12', 'abc', '+49+1 234', '01234 567 x12', '+(49)'] as $bad) {
				$fmtRefused('tel', $bad, $telMessage);
			}

			// The format is exported for the bot.
			$schema = fude_call($app, 'schema', options: $options)[1]['collections']['profil']['fields'];
			same(['url', 'tel', 'email', 'none'], [$schema['website']['format'] ?? null, $schema['telefon']['format'] ?? null, $schema['mail']['format'] ?? null, $schema['text']['format'] ?? 'none'], 'Schema exports the format');
		} finally {
			cleanup($tmp);
		}
		global $n;
	}
}
