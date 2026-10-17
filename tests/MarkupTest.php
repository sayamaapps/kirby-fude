<?php
/**
 * Text is plain text or Markdown, never HTML: sites render textareas with
 * KirbyText, which passes raw HTML through. A tag, a comment, a script scheme
 * or an entity that could hide one is refused, while Markdown, KirbyTags with
 * https links and a plain "<" in prose pass. Blueprint url, email and tel
 * fields are checked by their format before the Panel's own rules see them.
 */
require __DIR__ . '/support.php';

use Fude\FudeError;
use Fude\Validator;

$failed = false;
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
    // Plain text, Markdown and KirbyTags with https links pass; "<" in prose is no tag.
    foreach (['Kinder < 6 Jahre', 'a <= b', 'Preis: 5 € > 3 €', '**fett** und _kursiv_', '[Praxis](https://example.com/kontakt)', '(link: https://example.com text: Mehr)', 'Mail: info@example.com', 'metadata: ok', 'Daten: 3 Stück', 'Zeile eins\nZeile zwei'] as $good) {
        same(str_replace('\n', "\n", $good), $check('textarea', str_replace('\n', "\n", $good)), 'Accepted: ' . $good);
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
} catch (Throwable $e) {
    fwrite(STDERR, 'Markup: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    $failed = true;
} finally {
    cleanup($tmp);
}
global $n;
echo "Markup: $n checks\n";
exit($failed ? 1 : 0);
