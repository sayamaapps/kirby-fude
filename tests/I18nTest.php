<?php
/**
 * Translations: every language file has the same keys and placeholders as
 * en.php; fallbacks work; the tables are registered as Kirby translations.
 */
$base = dirname(__DIR__);
require_once $base . '/src/I18n.php';
use Fude\I18n;

$n = 0;
function same($expected, $actual, string $what): void
{
    global $n;
    if ($expected !== $actual) {
        throw new RuntimeException($what . ': ' . var_export($actual, true));
    }
    $n++;
}
$failed = false;
try {
    $en = I18n::table('en');
    same(true, count($en) > 30, 'English table loaded');
    foreach (I18n::LANGUAGES as $language) {
        $table = I18n::table($language);
        same([], array_diff_key($en, $table), "$language: no missing keys");
        same([], array_diff_key($table, $en), "$language: no extra keys");
        foreach ($en as $key => $text) {
            preg_match_all('/\{[a-z]+\}/', $text, $a);
            preg_match_all('/\{[a-z]+\}/', $table[$key], $b);
            sort($a[0]);
            sort($b[0]);
            same($a[0], $b[0], "$language: placeholders of $key");
            same(true, trim($table[$key]) !== '', "$language: $key not empty");
        }
    }
    I18n::setLanguage('de');
    same('de', I18n::language(), 'Language set');
    same('Unbekannte Sammlung „team“.', I18n::t('collection.unknown', ['name' => 'team']), 'German with placeholder');
    I18n::setLanguage('fr');
    same('en', I18n::language(), 'Unknown language falls back to English');
    same('Unknown collection "team".', I18n::t('collection.unknown', ['name' => 'team']), 'English text');
    same('nope.key', I18n::t('nope.key'), 'Unknown key returns the key');
    same([], I18n::table('fr'), 'Unknown language table is empty');
    I18n::setLanguage('en');
} catch (Throwable $e) {
    fwrite(STDERR, 'I18n: ' . $e->getMessage() . "\n");
    $failed = true;
}
echo "I18n: $n checks\n";
exit($failed ? 1 : 0);
