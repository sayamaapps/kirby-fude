<?php

use Kirby\Cms\Helpers;
use Kirby\Http\Response;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;

/**
 * Names of the 1.x API, kept until 2.0 (README, Deprecations).
 *
 * Loaded once by index.php, after the class map is registered. Class aliases
 * and function wrappers only; no logic here.
 */
class_alias(\Sayamaapps\Fude\Blueprints::class, 'Fude\\Blueprints');
class_alias(\Sayamaapps\Fude\Config::class, 'Fude\\Config');
class_alias(\Sayamaapps\Fude\Content::class, 'Fude\\Content');
class_alias(\Sayamaapps\Fude\Drafts::class, 'Fude\\Drafts');
class_alias(\Sayamaapps\Fude\FudeError::class, 'Fude\\FudeError');
class_alias(\Sayamaapps\Fude\I18n::class, 'Fude\\I18n');
class_alias(\Sayamaapps\Fude\Images::class, 'Fude\\Images');
class_alias(\Sayamaapps\Fude\Log::class, 'Fude\\Log');
class_alias(\Sayamaapps\Fude\Options::class, 'Fude\\Options');
class_alias(\Sayamaapps\Fude\Service::class, 'Fude\\Service');
class_alias(\Sayamaapps\Fude\Validator::class, 'Fude\\Validator');

if (Helpers::hasOverride('fude_option') === false) {
	/** Reads `fude.<key>`. Deprecated: use Sayamaapps\Fude\Options::get(). */
	function fude_option(string $key, mixed $default = null): mixed
	{
		Helpers::deprecated(
			'fude_option() is deprecated, use Sayamaapps\Fude\Options::get()',
			'fude-function'
		);

		return Options::get(Kirby\Cms\App::instance(), $key, $default);
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
