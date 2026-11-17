<?php

namespace App\Support;

/**
 * Where a connection may point. Private and loopback addresses stay allowed (watching an internal broker is the
 * point of Warren), but link-local addresses (cloud metadata at 169.254.169.254) and URLs that smuggle a path
 * through a query string or carry credentials are refused.
 */
final class BrokerAddress
{
    private const BLOCKED_HOSTS = ['metadata.google.internal', 'metadata', 'instance-data'];

    /** Null when the URL is acceptable, otherwise why it is not. */
    public static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return 'invalid';
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'extra';
        }

        return self::hostAllowed($parts['host']) ? null : 'blocked';
    }

    public static function hostAllowed(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || in_array($host, self::BLOCKED_HOSTS, true)) {
            return false;
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        foreach ($ips as $ip) {
            if (self::blockedIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(fn (array $r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
    }

    private static function blockedIp(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($ip, '169.254.');
        }

        // fe80::/10 (link-local) and IPv4-mapped link-local
        return (bool) preg_match('/^fe[89ab][0-9a-f]:/i', $ip) || str_starts_with(strtolower($ip), '::ffff:169.254.');
    }
}
