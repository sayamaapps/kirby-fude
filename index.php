<?php

use Kirby\Cms\App;
use Kirby\Filesystem\F;

/**
 * Plugin registration for Fude: change declared website content by chat
 * message, off by default.
 *
 * Kirby loads this file. It registers the class map, the legacy names and the
 * definitions in config/. The logic lives in src/.
 */
F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

App::plugin(
	name: 'sayamaapps/fude',
	extends: [
		'blueprints'   => require __DIR__ . '/config/blueprints.php',
		'options'      => require __DIR__ . '/config/options.php',
		'translations' => require __DIR__ . '/config/translations.php',
		'routes'       => require __DIR__ . '/config/routes.php',
	],
	root: __DIR__
);
