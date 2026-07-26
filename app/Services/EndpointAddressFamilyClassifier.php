<?php

namespace App\Services;

/**
 * Classifies advertised endpoint hosts without DNS or network access.
 *
 * Hostnames intentionally remain unresolved: discovery is a deterministic
 * control-plane projection and must not gain resolver I/O during scoring.
 */
class EndpointAddressFamilyClassifier
{
    public static function classify(?string $endpoint, ?string $transportEndpoint): string
    {
        $primary = self::hostFamily($endpoint);
        $transport = self::hostFamily($transportEndpoint);

        if ($primary === 'unknown' && $transport === 'unknown') {
            return 'unknown';
        }
        if ($primary === 'hostname' || $transport === 'hostname') {
            return 'hostname';
        }
        if ($primary === $transport) {
            return $primary;
        }
        if ($primary !== 'unknown' && $transport !== 'unknown') {
            return 'dual';
        }

        return $primary !== 'unknown' ? $primary : $transport;
    }

    public static function hostFamily(?string $url): string
    {
        if (! $url) {
            return 'unknown';
        }
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return 'unknown';
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'ipv4';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'ipv6';
        }

        return 'hostname';
    }
}
