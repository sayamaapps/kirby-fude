<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * HTTPS check: decided by the connection, not by Kirby's `url` option.
 * Direct connections, plain HTTP on an https `url`, and a reverse proxy
 * that terminates TLS (only from an address in `fude.trustedProxies`).
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class HttpsTest extends TestCase
{
    public function testBehaviour(): void
    {
        [$tmp, $app] = fude_fixture();
        $https = ['fude.withoutHttps' => false, 'url' => 'https://example.invalid'];
        try {
            // The bug: url is https, the connection is plain HTTP. Must not pass.
            [$code, $json] = fude_call($app, 'schema', options: $https);
            same([403, 'HTTPS only: this request reached the site over plain HTTP.'], [$code, $json['error']], 'https url, plain connection: 403 with the reason');

            // Real TLS on the connection.
            same(200, fude_call($app, 'schema', options: $https, scheme: 'https')[0], 'https url, TLS connection: through');
            same(200, fude_call($app, 'schema', options: $https, server: ['SERVER_PORT' => 443])[0], 'Port 443 counts as TLS');
            same(403, fude_call($app, 'schema', options: $https, server: ['HTTPS' => 'off'])[0], 'HTTPS=off is plain');
            same(403, fude_call($app, 'schema', options: $https, server: ['SERVER_PORT' => 8080, 'HTTPS' => ''])[0], 'Empty HTTPS on another port is plain');

            // Plain http url stays plain (the old result, unchanged).
            same(403, fude_call($app, 'schema', options: ['fude.withoutHttps' => false])[0], 'http url, plain connection: 403');

            // Reverse proxy: X-Forwarded-Proto counts only from a listed address.
            $proxy = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'];
            $listed = $https + ['fude.trustedProxies' => ['127.0.0.1']];
            [$code, $json] = fude_call($app, 'schema', options: $https, server: $proxy);
            same(403, $code, 'Proxy announces https, no list: 403');
            same(true, str_contains($json['error'], 'fude.trustedProxies'), 'Proxy reason names the option in the JSON');
            same(200, fude_call($app, 'schema', options: $listed, server: $proxy)[0], 'Listed proxy with https: through');
            same(200, fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => ' HTTPS '])[0], 'Header value is trimmed and case-insensitive');

            // Listed proxy that says http, or says nothing: refused.
            same(403, fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'])[0], 'Listed proxy with http: 403');
            same(403, fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => '127.0.0.1'])[0], 'Listed proxy without header: 403');
            [$code, $json] = fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http']);
            same('HTTPS only: this request reached the site over plain HTTP.', $json['error'], 'Plain-HTTP reason when the proxy says http');

            // The header from any other sender is ignored, whatever the list says.
            [$code, $json] = fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https']);
            same([403, true], [$code, str_contains($json['error'], 'fude.trustedProxies')], 'Header from a foreign address: 403, option named');
            same(403, fude_call($app, 'schema', options: $listed, server: ['HTTP_X_FORWARDED_PROTO' => 'https'])[0], 'Header without REMOTE_ADDR: 403');
            same(403, fude_call($app, 'schema', options: $listed, server: ['REMOTE_ADDR' => 'nicht-eine-ip', 'HTTP_X_FORWARDED_PROTO' => 'https'])[0], 'Garbage REMOTE_ADDR: 403');
            same(403, fude_call($app, 'schema', options: $https + ['fude.trustedProxies' => []], server: $proxy)[0], 'Empty list: header ignored');

            // CIDR ranges and IPv6, with the boundary inside a byte.
            $range = fn (array $list, string $from) => fude_call($app, 'schema', options: $https + ['fude.trustedProxies' => $list], server: ['REMOTE_ADDR' => $from, 'HTTP_X_FORWARDED_PROTO' => 'https'])[0];
            same(200, $range(['10.0.0.0/8'], '10.20.30.40'), 'IPv4 CIDR: inside');
            same(403, $range(['10.0.0.0/8'], '11.0.0.1'), 'IPv4 CIDR: outside');
            same(200, $range(['192.0.2.0/25'], '192.0.2.127'), 'IPv4 /25: last address of the range');
            same(403, $range(['192.0.2.0/25'], '192.0.2.128'), 'IPv4 /25: first address after it');
            same(200, $range(['203.0.113.7'], '203.0.113.7'), 'Single IPv4 address');
            same(200, $range(['2001:db8:abcd::/48'], '2001:db8:abcd:1::5'), 'IPv6 CIDR: inside');
            same(403, $range(['2001:db8:abcd::/48'], '2001:db8:abce::1'), 'IPv6 CIDR: outside');
            same(200, $range(['::1'], '::1'), 'Single IPv6 address');
            same(200, $range(['127.0.0.1'], '::ffff:127.0.0.1'), 'IPv4-mapped IPv6 connection matches IPv4 entry');
            same(200, $range(['::ffff:10.0.0.0/104'], '10.1.2.3'), 'IPv4-mapped IPv6 range matches IPv4 connection');
            same(403, $range(['10.0.0.0/8', '2001:db8::/32'], '192.168.1.1'), 'Two entries, neither matches');

            // A list that is not a list of addresses fails every route, loud.
            foreach ([['nicht-eine-ip'], ['10.0.0.0/33'], ['2001:db8::/129'], ['10.0.0.0/'], [42], '127.0.0.1'] as $bad) {
                [$code, $json] = fude_call($app, 'schema', options: $https + ['fude.trustedProxies' => $bad], server: $proxy);
                same(500, $code, 'Invalid trustedProxies: 500 ' . json_encode($bad));
            }
            same(true, str_contains($json['error'], 'fude.trustedProxies'), 'Invalid list names the option');

            // The German reply carries the same reason.
            [$code, $json] = fude_call($app, 'schema', options: $https + ['locale' => 'de_DE.utf8'], server: $proxy);
            same(true, str_contains($json['error'], 'fude.trustedProxies'), 'German proxy reason names the option');
        } finally {
            cleanup($tmp);
        }
    }
}
