<?php

use Kirby\Cms\App;
use Kirby\Cms\Helpers;
use Kirby\Http\Response;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Aliases and
 * function wrappers only; no logic here.
 *
 * A `Fude\` class name becomes an alias when it is first used. An eager
 * class_alias() would load every class on every request.
 */
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'Fude\\')) {
		$target = 'Sayamaapps\\Fude\\' . substr($class, strlen('Fude\\'));
		if (class_exists($target)) {
			class_alias($target, $class);
		}
	}
});

if (Helpers::hasOverride('fude_option') === false) {
	/** Reads `fude.<key>`. Deprecated: use Sayamaapps\Fude\Options::get(). */
	function fude_option(string $key, mixed $default = null): mixed
	{
		Helpers::deprecated(
			'fude_option() is deprecated, use Sayamaapps\Fude\Options::get()',
			'fude-function'
		);

		return Options::get(App::instance(), $key, $default);
	}
}

if (Helpers::hasOverride('fude_not_found') === false) {
	/** Kirby's 404 page. Deprecated: use Sayamaapps\Fude\Service::notFound(). */
	function fude_not_found(): Response
	{
		Helpers::deprecated(
			'fude_not_found() is deprecated, use Sayamaapps\Fude\Service::notFound()',
			'fude-function'
		);

		return Service::notFound();
	}
}
