<?php

use Kirby\Cms\App;
use Sayamaapps\Fude\Service;

// The routes under /fude/*. Anything else under /fude/, and GET on the POST
// routes, answer with the real 404.
return function () {
	return [
		['pattern' => 'fude/schema',  'method' => 'GET',  'action' => fn () => Service::respondNow('schema')],
		['pattern' => 'fude/draft',   'method' => 'POST', 'action' => fn () => Service::respondNow('draft')],
		['pattern' => 'fude/publish', 'method' => 'POST', 'action' => fn () => Service::respondNow('publish')],
		['pattern' => 'fude/discard', 'method' => 'POST', 'action' => fn () => Service::respondNow('discard')],
		['pattern' => 'fude/(:all?)', 'method' => 'ALL', 'action' => fn () => Service::notFound()],
	];
};
