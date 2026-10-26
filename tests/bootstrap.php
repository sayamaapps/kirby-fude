<?php

// Test bootstrap: Composer autoloader, the plugin's class map (the same as in
// production, see autoload.php) and Kirby's shared fixture.
require dirname(__DIR__) . '/vendor/autoload.php';

Kirby\Filesystem\F::loadClasses(require dirname(__DIR__) . '/autoload.php', dirname(__DIR__));

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;

require __DIR__ . '/support.php';
