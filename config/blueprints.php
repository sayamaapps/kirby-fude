<?php

use Kirby\Cms\App;
use Sayamaapps\Fude\Options;
use Sayamaapps\Fude\Service;

// The role of the bot account, only with the module switched on: otherwise it
// would appear in every site's Panel role selection. Kirby loads index.php only
// ONCE per PHP process, so several App instances in one test see the role as
// the first one created it. On a server that is one request.
return Options::get(App::instance(), 'active', false) === true
	? ['users/' . Service::ROLE => dirname(__DIR__) . '/blueprints/fude.yml']
	: [];
