<?php

use Kirby\Cms\App;
use Kirby\Cms\Helpers;
use Kirby\Http\Response;
use Sayamaapps\Fude\FudeError;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;

/**
 * Names of the 1.x API, kept until 2.0 (see the README, "Planned for 2.0").
 *
 * Loaded once by index.php, after the class map is registered. Aliases and
 * function wrappers only. They print no runtime notice: Kirby's debug mode
 * turns a deprecation into an exception, which would stop a site that still
 * uses these names. The names are marked `@deprecated` instead.
 *
 * A `Fude\` class name becomes an alias when it is first used. An eager
 * class_alias() would load every class on every request. The one exception
 * is FudeError: `catch` and `instanceof` do not autoload, so the 1.x name of
 * the exception must exist before the first throw.
 */
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Fude\\')) {
		$target = 'Sayamaapps\\Fude\\' . substr($class, strlen('Fude\\'));
		if (class_exists($target)) {
			class_alias($target, $class);
		}
	}
});

if (!class_exists('Fude\\FudeError', false)) {
	class_alias(FudeError::class, 'Fude\\FudeError');
}

if (Helpers::hasOverride('fude_option') === false) {
	/**
	 * Reads `fude.<key>`.
	 *
	 * @deprecated Use Sayamaapps\Fude\Options::get().
	 */
	function fude_option(string $key, mixed $default = null): mixed
	{
		return Options::get(App::instance(), $key, $default);
	}
}

if (Helpers::hasOverride('fude_not_found') === false) {
	/**
	 * Kirby's own 404 page.
	 *
	 * @deprecated Use Sayamaapps\Fude\Service::notFound().
	 */
	function fude_not_found(): Response
	{
		return Service::notFound();
	}
}
