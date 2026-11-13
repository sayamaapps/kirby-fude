<?php

use Sayamaapps\Fude\Service;

// The routes under /fude/*. Anything else under /fude/, and GET on the POST
// routes, answer with the real 404 — unless a route of another plugin or of
// the site config matches the path: then that route answers, whichever
// plugin Kirby loaded first (Service::notFoundOrNext()). The names mark the
// routes as Fude's own.
return function () {
	return [
		['pattern' => 'fude/schema',  'method' => 'GET',  'name' => Service::ROUTE_NAME . 'schema',  'action' => fn () => Service::respondNow('schema')],
		['pattern' => 'fude/draft',   'method' => 'POST', 'name' => Service::ROUTE_NAME . 'draft',   'action' => fn () => Service::respondNow('draft')],
		['pattern' => 'fude/publish', 'method' => 'POST', 'name' => Service::ROUTE_NAME . 'publish', 'action' => fn () => Service::respondNow('publish')],
		['pattern' => 'fude/discard', 'method' => 'POST', 'name' => Service::ROUTE_NAME . 'discard', 'action' => fn () => Service::respondNow('discard')],
		['pattern' => 'fude/(:all?)', 'method' => 'ALL',  'name' => Service::ROUTE_NAME . 'notFound', 'action' => fn (string|null $rest = null) => Service::notFoundOrNext('fude/' . ($rest ?? ''))],
	];
};
