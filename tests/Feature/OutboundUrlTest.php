<?php

use App\Support\OutboundUrl;

/**
 * The crawler fetches addresses chosen from outside the code — seed URLs typed
 * into the admin UI and links discovered inside crawled pages. Requests it
 * makes originate inside the network, so without this guard they reach what no
 * outside browser can: the cloud metadata service that hands out role
 * credentials, Redis, the database, and anything else bound to localhost.
 *
 * These run with the guard forced on; the suite otherwise disables it so faked
 * HTTP does not depend on live DNS.
 */
beforeEach(function () {
    config()->set('saligan.crawler.block_private_addresses', true);
});

it('refuses the cloud metadata address', function () {
    // The single most valuable SSRF target on a cloud host: unauthenticated,
    // and it answers with credentials for the instance's IAM role.
    expect(OutboundUrl::isFetchable('http://169.254.169.254/latest/meta-data/iam/security-credentials/'))
        ->toBeFalse();
});

it('refuses loopback and private ranges', function (string $url) {
    expect(OutboundUrl::isFetchable($url))->toBeFalse();
})->with([
    'loopback' => 'http://127.0.0.1:6379/',
    'loopback by name' => 'http://localhost/admin',
    'loopback ipv6' => 'http://[::1]/',
    'private 10/8' => 'https://10.1.2.3/x',
    'private 172.16/12' => 'https://172.16.5.4/',
    'private 192.168/16' => 'https://192.168.1.1/',
    'this-host' => 'http://0.0.0.0/',
    'carrier-grade nat' => 'http://100.64.1.1/',
]);

it('refuses schemes that are not http', function (string $url) {
    // file:// turns a fetcher into a local file reader; gopher:// is the
    // classic gadget for smuggling commands at Redis and memcached.
    expect(OutboundUrl::isFetchable($url))->toBeFalse();
})->with([
    'file' => 'file:///etc/passwd',
    'gopher' => 'gopher://127.0.0.1:11211/',
    'no scheme' => '//169.254.169.254/',
]);

it('refuses a host that does not resolve', function () {
    // Fail closed: an unresolvable name cannot be shown to be safe, and the
    // fetch would fail regardless.
    expect(OutboundUrl::isFetchable('https://nx-'.bin2hex(random_bytes(8)).'.invalid/'))
        ->toBeFalse();
});

it('allows an ordinary public address just outside a blocked range', function () {
    // 172.32.x is public; only 172.16–172.31 is private. A guard that blocked
    // the whole 172/8 would quietly break legitimate crawling.
    expect(OutboundUrl::isFetchable('https://172.32.5.4/'))->toBeTrue();
});
