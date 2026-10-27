<?php

use Kirby\Cms\App;
use Kirby\Cms\Helpers;
use Kirby\Http\Response;
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
 * Every 1.x class name is aliased here, eagerly. `catch` and `instanceof`
 * do not autoload, so a lazy alias would not exist before the first use of
 * the old name. The list is the 1.x class set; DraftContext is new in 2.x
 * and has no 1.x name.
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
