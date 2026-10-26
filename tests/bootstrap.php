<?php

// Test bootstrap: Composer autoloader and Kirby's shared fixture. The plugin's
// classes load when the fixture's App reads the plugin's index.php.
require dirname(__DIR__) . '/vendor/autoload.php';

// Kirby's Whoops error handler stays registered after every App is created, so
// the risky-test check would flag each Kirby test. PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;

require __DIR__ . '/support.php';
