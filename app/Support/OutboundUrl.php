<?php

namespace App\Support;

/**
 * Decides whether the crawler may fetch a URL.
 *
 * The crawler is the one part of the app that makes HTTP requests to addresses
 * chosen from outside the code — seed URLs entered in the admin UI, and links
 * discovered inside the pages it fetches. That is the shape of a server-side
 * request forgery: the request leaves from inside the network, so it reaches
 * everything a browser on the internet cannot — the cloud instance metadata
 * service on 169.254.169.254 (which hands out role credentials to anything
 * that asks), the database, Redis, the queue dashboard, and any other service
 * bound to localhost or a private range.
 *
 * The host is resolved before the fetch rather than pattern-matched, because
 * "internal" is a property of the address a name resolves to, not of the name.
 * `localtest.me` and countless other public names resolve to 127.0.0.1, and an
 * attacker who controls a DNS zone can point any hostname anywhere.
 */
final class OutboundUrl
{
    /**
     * CIDR blocks the crawler must never reach: loopback, RFC 1918 private
     * space, link-local (including the cloud metadata address), carrier-grade
     * NAT, and the IPv6 equivalents.
     *
     * @var array<int, string>
     */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',        // "this host on this network"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, incl. 169.254.169.254 metadata
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '::1/128',          // IPv6 loopback
        'fc00::/7',         // IPv6 unique local
        'fe80::/10',        // IPv6 link-local
    ];

    /**
     * Whether the crawler may fetch this URL.
     */
    public static function isFetchable(string $url): bool
    {
        $parsed = parse_url($url);

        // The scheme check stands whatever the configuration says: file:// is
        // never a legitimate crawl target, and refusing it costs no lookup.
        if (! in_array(strtolower($parsed['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        // Address checking needs live DNS, which faked HTTP in tests cannot
        // stand in for. The switch exists so the suite can point the crawler at
        // hostnames that do not resolve; see the config comment.
        if (! config('saligan.crawler.block_private_addresses', true)) {
            return true;
        }

        $host = $parsed['host'] ?? '';

        if ($host === '') {
            return false;
        }

        $addresses = self::resolve($host);

        if ($addresses === []) {
            return false;
        }

        // Every address the name resolves to must be routable. A name with one
        // public and one internal address would otherwise be a coin flip
        // decided by whichever record the resolver hands the HTTP client.
        foreach ($addresses as $address) {
            if (self::isBlocked($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every IP address a host resolves to. A literal address resolves to
     * itself; a name is looked up over both A and AAAA records.
     *
     * @return array<int, string>
     */
    private static function resolve(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * Whether an IP address falls inside one of the blocked ranges.
     */
    private static function isBlocked(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            [$subnet, $bits] = explode('/', $range);

            $packedSubnet = @inet_pton($subnet);

            // Only compare addresses of the same family: a 4-byte value and a
            // 16-byte value share no prefix worth testing.
            if ($packedSubnet === false || strlen($packedSubnet) !== strlen($packed)) {
                continue;
            }

            if (self::sharesPrefix($packed, $packedSubnet, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether two packed addresses agree on their first `$bits` bits.
     */
    private static function sharesPrefix(string $a, string $b, int $bits): bool
    {
        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($a, $b, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($a[$wholeBytes]) & $mask) === (ord($b[$wholeBytes]) & $mask);
    }
}
