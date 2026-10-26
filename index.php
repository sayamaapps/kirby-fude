<?php

use Kirby\Cms\App;
use Kirby\Http\Response;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/FudeError.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Blueprints.php';
require_once __DIR__ . '/src/Content.php';
require_once __DIR__ . '/src/Validator.php';
require_once __DIR__ . '/src/Images.php';
require_once __DIR__ . '/src/Drafts.php';
require_once __DIR__ . '/src/Log.php';
require_once __DIR__ . '/src/Service.php';

use Fude\I18n;
use Fude\Options;
use Fude\Service;

/**
 * Fude — change declared website content by chat message, off by default.
 *
 * The site declares in site/config/fude.php which collections (a structure
 * field on a page, or child pages of a parent) a bot may change and which
 * fields of them. The routes under /fude/* turn a request into a Kirby 5
 * `changes` version, hand back a preview link, and publish only on a second
 * call with the draft's confirmation token. The chat side (the chat
 * adapters) lives in a separate private operations repository.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under sayamaapps.fude.active
 * otherwise).
 */

/** Reads `fude.<key>`. */
function fude_option(string $key, mixed $default = null): mixed
{
	return Options::get(App::instance(), $key, $default);
}

/** Kirby's real 404 — no format of its own that would reveal the module. */
function fude_not_found(): Response
{
	// Without content/error/, errorPage() returns null; then an empty 404 instead of a 500.
	$page = App::instance()->site()->errorPage();
	return new Response($page?->render() ?? '', 'text/html', 404);
}

$fudeTranslations = [];
foreach (I18n::LANGUAGES as $fudeLanguage) {
	foreach (I18n::table($fudeLanguage) as $fudeKey => $fudeText) {
		$fudeTranslations[$fudeLanguage]['fude.' . $fudeKey] = $fudeText;
	}
}
unset($fudeLanguage, $fudeKey, $fudeText);

App::plugin('sayamaapps/fude', [
	/**
	 * The role of the bot account — only with the module switched on,
	 * otherwise it would appear in every site's Panel role selection.
	 *
	 * option() already works here (optionsFromConfig() runs before
	 * extensionsFromPlugins()). Kirby loads this file only ONCE per PHP
	 * process, though: several App instances in one test see the role as
	 * the first one created it. On a server that is one request.
	 */
	'blueprints' => fude_option('active', false) === true
		? ['users/' . Service::ROLE => __DIR__ . '/blueprints/fude.yml']
		: [],

	// The reply strings, also usable as t('fude.<key>').
	'translations' => $fudeTranslations,

	'routes' => function () {
		$now = fn () => new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
		return [
			['pattern' => 'fude/schema',  'method' => 'GET',  'action' => fn () => Service::respond(App::instance(), $now(), 'schema')],
			['pattern' => 'fude/draft',   'method' => 'POST', 'action' => fn () => Service::respond(App::instance(), $now(), 'draft')],
			['pattern' => 'fude/publish', 'method' => 'POST', 'action' => fn () => Service::respond(App::instance(), $now(), 'publish')],
			['pattern' => 'fude/discard', 'method' => 'POST', 'action' => fn () => Service::respond(App::instance(), $now(), 'discard')],
			// Anything else under /fude/, and GET on the POST routes: the real 404.
			['pattern' => 'fude/(:all?)', 'method' => 'ALL', 'action' => fn () => fude_not_found()],
		];
	},
]);
unset($fudeTranslations);
