<?php

namespace Sayamaapps\Fude;

use DateTimeZone;
use Kirby\Cms\App;

/**
 * Reads Fude's options: `sayamaapps.fude.<key>`, falling back to the 1.x
 * name `sayamaapps.fude.<key>`.
 *
 * `null` counts as "not set", like Kirby's own option(), so a key set to
 * null falls back to the default. The flat form is the one to use
 * (`'sayamaapps.fude.active' => true`). The nested form
 * (`'fude' => ['active' => true]`) works for the 1.x prefix only: Kirby
 * resolves `sayamaapps.fude.*` through the plugin's own options block first,
 * which shadows a nested `sayamaapps` array.
 *
 * Defaults are given here and at the call sites. The `options` block of the
 * plugin (config/options.php) lists the keys with null: a default there would
 * hide the 1.x spelling.
 *
 * @package   Kirby Fude
 * @author    sayamaapps
 * @link      https://github.com/sayamaapps/kirby-fude
 * @copyright sayamaapps
 * @license   MIT
 */
class Options
{
	public const DEFAULT_TIMEZONE = 'Europe/Berlin';

	/** Option prefix since 1.10: `sayamaapps.fude.<key>`. */
	public const OPTION_PREFIX = 'sayamaapps.fude.';

	/**
	 * Option prefix of 1.x (`sayamaapps.fude.<key>`). Still read as a fallback until 2.0.
	 *
	 * @deprecated 1.10 use OPTION_PREFIX; removal planned for 2.0
	 */
	public const PREFIX = 'fude.';

	/** The declaration of what the bot may change: `site/config/fude.php`. */
	public static function configFile(App $kirby): string
	{
		$file = self::get($kirby, 'config');
		if (is_string($file) && $file !== '') {
			return str_starts_with($file, '/') ? $file : $kirby->root('base') . '/' . $file;
		}
		return $kirby->root('config') . '/fude.php';
	}

	/** Drafts, the log and resized uploads live in `<storage>/fude/`. */
	public static function directory(App $kirby): string
	{
		return $kirby->root('storage') . '/fude';
	}

	/**
	 * Reads one option under the sayamaapps.fude.* spelling, then under the
	 * 1.x fude.* spelling, which is read silently. Returns $default when
	 * neither is set.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::OPTION_PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		return $kirby->option(self::PREFIX . $key) ?? $default;
	}

	/** Whether $address (the connection's REMOTE_ADDR) lies in `sayamaapps.fude.trustedProxies`. */
	public static function isTrustedProxy(App $kirby, mixed $address): bool
	{
		if (!is_string($address) || ($ip = @inet_pton($address)) === false) {
			return false;
		}
		$ip = self::unmapped($ip);
		foreach (self::trustedProxies($kirby) as $entry) {
			$range = self::parseRange($entry);
			if ($range === null) {
				continue;
			}
			[$net, $bits] = $range;
			if (strlen($net) !== strlen($ip)) {
				continue;
			}
			$full = intdiv($bits, 8);
			if (substr($ip, 0, $full) !== substr($net, 0, $full)) {
				continue;
			}
			$rest = $bits % 8;
			if ($rest === 0) {
				return true;
			}
			$mask = (0xff << (8 - $rest)) & 0xff;
			if ((ord($ip[$full]) & $mask) === (ord($net[$full]) & $mask)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Language of the replies: `sayamaapps.fude.language`, otherwise the site's default
	 * language, otherwise the first two letters of Kirby's `locale` option,
	 * otherwise English. Only languages Fude ships are returned.
	 */
	public static function language(App $kirby): string
	{
		$candidates = [self::get($kirby, 'language')];
		try {
			$candidates[] = $kirby->defaultLanguage()?->code();
		} catch (\Throwable) {
			// No languages configured.
		}
		$locale = $kirby->option('locale');
		if (is_array($locale)) {
			$locale = $locale[LC_ALL] ?? reset($locale);
		}
		$candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
				return strtolower($candidate);
			}
		}
		return 'en';
	}

	/**
	 * An address or CIDR range as [packed address, prefix length], or null.
	 * An IPv4-mapped IPv6 range (::ffff:a.b.c.d/n, n >= 96) becomes IPv4.
	 *
	 * @return array{0: string, 1: int}|null
	 */
	private static function parseRange(string $entry): array|null
	{
		[$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
		$net = $address === null ? false : @inet_pton($address);
		if ($net === false) {
			return null;
		}
		$max = strlen($net) * 8;
		if ($bits === null) {
			$prefix = $max;
		} elseif (ctype_digit($bits) && (int)$bits <= $max) {
			$prefix = (int)$bits;
		} else {
			return null;
		}
		if ($net !== self::unmapped($net) && $prefix >= 96) {
			return [substr($net, 12), $prefix - 96];
		}
		return [$net, $prefix];
	}

	/**
	 * The option name as the site spelled it, for messages: the 1.x fude.*
	 * name only when the site sets no sayamaapps.fude.* value for it.
	 */
	public static function setKey(App $kirby, string $key): string
	{
		if ($kirby->option(self::OPTION_PREFIX . $key) === null && $kirby->option(self::PREFIX . $key) !== null) {
			return self::PREFIX . $key;
		}
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * Site identifier in the schema: `sayamaapps.fude.siteId`, otherwise the hostname.
	 */
	public static function siteId(App $kirby): string
	{
		$id = self::get($kirby, 'siteId');
		if (is_string($id) && trim($id) !== '') {
			return trim($id);
		}
		return strtolower((string)parse_url((string)$kirby->url('index'), PHP_URL_HOST));
	}

	/**
	 * The time zone that decides what "today" is (touch, `min: today`):
	 * `sayamaapps.fude.timezone`, otherwise Europe/Berlin. Not PHP's default, which on
	 * a hoster is often UTC — at 00:30 in Berlin that is still yesterday.
	 *
	 * @throws FudeError 500 when the option is not a time zone
	 */
	public static function timezone(App $kirby): DateTimeZone
	{
		$zone = self::get($kirby, 'timezone', self::DEFAULT_TIMEZONE);
		if (!is_string($zone) || $zone === '' || !in_array($zone, DateTimeZone::listIdentifiers(), true)) {
			throw FudeError::t('config.timezone', ['key' => self::setKey($kirby, 'timezone'), 'value' => is_string($zone) ? $zone : get_debug_type($zone)], 500);
		}
		return new DateTimeZone($zone);
	}

	/**
	 * `sayamaapps.fude.trustedProxies`: the reverse proxies in front of PHP that terminate
	 * TLS, as IPv4 or IPv6 addresses and CIDR ranges. Default: none. Only a
	 * request from one of them may pass on its `X-Forwarded-Proto` header.
	 *
	 * @return list<string> the entries, all checked
	 * @throws FudeError 500 when the option is not a list of addresses or ranges
	 */
	public static function trustedProxies(App $kirby): array
	{
		$list = self::get($kirby, 'trustedProxies', []);
		if (!is_array($list) || !array_is_list($list)) {
			throw FudeError::t('config.trustedProxies', ['key' => self::setKey($kirby, 'trustedProxies'), 'value' => get_debug_type($list)], 500);
		}
		foreach ($list as $entry) {
			if (!is_string($entry) || self::parseRange($entry) === null) {
				throw FudeError::t('config.trustedProxies', ['key' => self::setKey($kirby, 'trustedProxies'), 'value' => is_string($entry) ? $entry : get_debug_type($entry)], 500);
			}
		}
		return $list;
	}

	/** The IPv4 address of an IPv4-mapped IPv6 address (::ffff:a.b.c.d), else unchanged. */
	private static function unmapped(string $packed): string
	{
		return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
			? substr($packed, 12)
			: $packed;
	}
}
