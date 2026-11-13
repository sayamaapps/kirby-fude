<?php

use Kirby\Cms\App;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

/**
 * F6: Fude's catch-all `fude/(:all?)` answers unknown paths under /fude/
 * with Kirby's real 404, but it does not hide a route that another plugin
 * or the site config registers under /fude/. Kirby takes the first matching
 * route; plugin routes come in plugin load order (folder order), and the
 * routes of the site config after all plugin routes. Only when no other
 * route matches does the 404 come, exactly as before.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class RouteFallbackTest extends TestCase
{
	public function testAnUnknownPathIsStillTheReal404(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			App::plugin('test/later', extends: ['routes' => [
				['pattern' => 'fude/extra', 'method' => 'GET', 'action' => fn () => 'LATER GET'],
			]]);
			$expected = Service::notFound();
			same([404, 'Fixture 404'], [$expected->code(), $expected->body()], 'The real 404 is the error page');
			foreach ([['GET', 'fude/unknown'], ['GET', 'fude'], ['POST', 'fude/extra'], ['GET', 'fude/draft'], ['GET', 'fude/extra/more'], ['DELETE', 'fude/schema']] as [$method, $path]) {
				$response = $app(['method' => $method, 'url' => 'http://example.invalid/' . $path])->render();
				same(
					[$expected->code(), $expected->body(), $expected->type()],
					[$response->code(), $response->body(), $response->type()],
					$method . ' ' . $path . ' answers as before'
				);
			}
		} finally {
			cleanup($tmp);
		}
	}

	public function testARouteOfAPluginLoadedAfterFudeIsReachable(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			// Registered after Fude (the fixture loaded Fude with the first app): its routes come after Fude's.
			App::plugin('test/later', extends: ['routes' => [
				['pattern' => 'fude/extra', 'method' => 'GET', 'action' => fn () => 'LATER GET'],
				['pattern' => 'fude/extra/(:any)', 'method' => 'POST', 'action' => fn (string $id) => 'LATER POST ' . $id],
			]]);
			$render = function (string $method, string $path, array $options = []) use ($app): array {
				$response = $app(['method' => $method, 'url' => 'http://example.invalid/' . $path], $options)->render();
				return [$response->code(), $response->body()];
			};

			same([200, 'LATER GET'], $render('GET', 'fude/extra'), 'A later plugin\'s route under fude/ answers');
			same([200, 'LATER POST 42'], $render('POST', 'fude/extra/42'), 'with its pattern and its method');
			same([200, 'LATER GET'], $render('GET', 'fude/extra', ['fude.active' => false]), 'also with Fude switched off');

			// The routes of the site config come after every plugin's, so after Fude's catch-all too.
			$config = ['routes' => [['pattern' => 'fude/early', 'method' => 'GET', 'action' => fn () => 'CONFIG']]];
			same([200, 'CONFIG'], $render('GET', 'fude/early', $config), 'A config route under fude/ answers');

			// Fude's own routes stay Fude's.
			[$code, $json] = fude_call($app, 'schema');
			same([200, true], [$code, $json['ok']], 'The schema route is Fude\'s');
		} finally {
			cleanup($tmp);
		}
	}
}
