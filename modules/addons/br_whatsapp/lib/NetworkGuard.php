<?php
/**
 * Safety check for outgoing connections: the module only talks to Meta's fixed host,
 * and only if that host resolves to a public internet address.
 * Loopback, private, link-local and cloud-metadata addresses are always refused
 * (protects against a tampered DNS or hosts file sending our access token somewhere internal).
 */

declare(strict_types=1);

namespace BinaryRacks\WhatsApp;

if (!defined('WHMCS')) {
    exit;
}

final class NetworkGuard
{
    /** The only host the module may connect to. Fixed in code; there is no setting to change it. */
    public const ALLOWED_HOSTS = ['graph.facebook.com'];

    /** Returns true if an IP address is a normal public internet address. */
    public static function isPublicIp(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE blocks 10/8, 172.16/12, 192.168/16, fc00::/7;
        // FILTER_FLAG_NO_RES_RANGE blocks 0/8, 127/8 (loopback), 169.254/16 (link-local and cloud metadata), ::1 and more.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Extra blocks PHP doesn't cover: carrier-grade NAT (100.64/10) and IPv6 link-local (fe80::/10).
        if (str_contains($ip, '.')) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        } elseif (preg_match('/^fe[89ab]/i', $ip) === 1) {
            return false;
        }
        return true;
    }

    /**
     * Looks up an allowed host and returns one public IPv4 address to connect to.
     * Throws if the host is not allowed or any address it resolves to is not public.
     */
    public static function resolve(string $host): string
    {
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new \RuntimeException('Host not allowed.');
        }
        $ips = gethostbynamel($host);
        if ($ips === false || $ips === []) {
            throw new \RuntimeException('Could not look up the Meta server.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \RuntimeException('The Meta server name points to a blocked address.');
            }
        }
        return $ips[0];
    }
}
