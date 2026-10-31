<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Fude\Service;

/**
 * Access: the active switch (real 404), Basic Auth with the role `fude`
 * only (401/403), HTTPS enforcement, the routes through Kirby's router.
 */
#[CoversClass(Service::class)]
#[RunTestsInSeparateProcesses]
final class AuthTest extends TestCase
{
	public function testBehaviour(): void
	{
		[$tmp, $app] = fude_fixture();
		try {
			// Switch: without fude.active Kirby's real 404, no JSON that would reveal the module.
			[$code, , , $body] = fude_call($app, 'schema', options: ['fude.active' => false]);
			same([404, 'Fixture 404'], [$code, $body], 'Off: real 404');
			same(404, fude_call($app, 'schema', options: ['fude.active' => 'yes'])[0], 'Only true switches on');
			same(401, fude_call($app, 'schema', who: null, options: ['sayamaapps.fude.active' => true])[0], 'New spelling switches on: login challenge, not 404');

			// Login
			[$code, $json, $headers] = fude_call($app, 'schema', who: null);
			same([401, false, true], [$code, $json['ok'], isset($headers['WWW-Authenticate'])], 'Without login: 401 with challenge');
			same(true, str_contains($headers['WWW-Authenticate'] ?? '', 'realm="Fude"'), 'Realm is Fude');
			same(401, fude_call($app, 'schema', pw: 'wrong')[0], 'Wrong password');
			same(401, fude_call($app, 'schema', who: 'nobody@example.invalid', pw: 'whatever-whatever')[0], 'Unknown account');
			same(true, str_contains((string)@file_get_contents($GLOBALS['fudeLogFile']), 'nobody@example.invalid'), 'Failed attempt logged by Kirby');
			[$code, $json] = fude_call($app, 'schema', who: FUDE_ADMIN, pw: FUDE_ADMIN_PW);
			same([403, 'This account may not use this route.'], [$code, $json['error']], 'Admin does not get through');
			same(200, fude_call($app, 'schema', who: FUDE_ADMIN, pw: FUDE_ADMIN_PW, options: ['fude.roles' => ['admin']])[0], 'Roles via configuration');
			same(403, fude_call($app, 'schema', options: ['fude.roles' => ['other']])[0], 'Own role not allowed when reconfigured');

			// HTTPS
			[$code, $json] = fude_call($app, 'schema', options: ['fude.withoutHttps' => false]);
			same([403, 'HTTPS only: this request reached the site over plain HTTP.'], [$code, $json['error']], 'Rejected without HTTPS');
			same(200, fude_call($app, 'schema', options: ['fude.withoutHttps' => false], scheme: 'https')[0], 'Accepted over HTTPS');
			[$code, $json] = fude_call($app, 'schema', options: ['fude.withoutHttps' => false, 'locale' => 'de_DE.utf8']);
			same('Nur über HTTPS: Diese Anfrage kam per einfachem HTTP an.', $json['error'], 'German reply with a German locale');

			// Publishing roles are a second gate, independent of the login roles.
			[$code, $json] = fude_call($app, 'draft', ['collection' => 'team', 'action' => 'create', 'fields' => ['name' => 'Neu Person']]);
			same(200, $code, 'Draft as the bot');
			[$code, $json2] = fude_call($app, 'publish', ['id' => $json['draft']['id'], 'token' => $json['token']], who: FUDE_ADMIN, pw: FUDE_ADMIN_PW, options: ['fude.roles' => ['admin', 'fude']]);
			same([403, 'This account may not publish this collection.'], [$code, $json2['error']], 'Admin may log in but not publish the collection');
			same(200, fude_call($app, 'discard', ['id' => $json['draft']['id'], 'token' => $json['token']])[0], 'Discard works for the bot');

			// Through Kirby's router, not only Service::respond().
			$kirby = $app(['method' => 'GET', 'url' => 'http://example.invalid/fude/schema', 'auth' => 'Basic ' . base64_encode(FUDE_BOT . ':' . FUDE_PW)]);
			$response = $kirby->call('fude/schema', 'GET');
			same([200, true], [$response->code(), json_decode($response->body(), true)['ok']], 'Router: GET /fude/schema');
			$kirby = $app(['method' => 'GET', 'url' => 'http://example.invalid/fude/publish']);
			same(404, $kirby->call('fude/publish', 'GET')->code(), 'Router: GET on a POST route is the real 404');
			$kirby = $app(['method' => 'GET', 'url' => 'http://example.invalid/fude/other']);
			same(404, $kirby->call('fude/other', 'GET')->code(), 'Router: unknown sub-path is the real 404');
			same('no-store', fude_call($app, 'schema')[2]['Cache-Control'] ?? null, 'Not cached');
		} finally {
			cleanup($tmp);
		}
	}
}
