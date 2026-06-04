<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Issue #325 Layer 1: reject endpoints that cannot possibly be reached by an
 * external client.
 *
 * The directory's job is to advertise nodes to a public client population.
 * Nodes registered with localhost / Docker-internal / *.local / *.test
 * endpoints can never serve a request from outside the operator's own host —
 * they pollute the public registry and break the quickstart end-to-end.
 *
 * This rule is syntactic-only. Active reachability probing (Layer 2) lives in
 * EndpointReachabilityProbe; periodic re-verification (Layer 3) lives in
 * VerifyNodeReachabilityCommand.
 *
 * Dev bypass: when APP_ENV !== 'production', the rule is a no-op. This keeps
 * docker-compose dev stacks working against a local directory.
 *
 * Filed in response to fresh-Opus end-to-end assessment 2026-05-26 which
 * exposed 8/8 nodes in /v1/discover as localhost:* or adapter-* Docker hosts.
 *
 * Spec references: ADR-006 (open registration is unchanged — this rule adds
 * reachability validation, not auth). ADR-001 (directory authoritative on
 * what's discoverable).
 */
class RoutableEndpoint implements ValidationRule
{
    /**
     * Hostnames that are never routable from a public client.
     * Exact match, lowercase.
     */
    private const NEVER_ROUTABLE_HOSTS = [
        'localhost',
        '0.0.0.0',
        '[::]',
        '[::1]',
    ];

    /**
     * Hostname suffixes that are reserved for local / test / dev use
     * (RFC 6762 mDNS, RFC 6761 special-use, RFC 2606 reserved TLDs).
     */
    private const NEVER_ROUTABLE_SUFFIXES = [
        '.localhost',
        '.local',
        '.test',
        '.example',
        '.invalid',
        '.lan',
        '.internal', // GCP / common k8s
    ];

    /**
     * Allowed URI schemes for this validator instance.
     * Default ['http', 'https'] preserves the iter-1365 contract for the
     * `endpoint` field (HTTP control plane).
     * For native IICP `transport_endpoint` (spec v0.7.0), pass ['iicp', 'iicpsec'].
     */
    public function __construct(private array $allowedSchemes = ['http', 'https']) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (config('app.env') === 'local' || config('app.env') === 'testing') {
            return;
        }

        if ($this->isNatted()) {
            $this->validateNattedEndpoint($value, $fail);

            return;
        }

        if (! $this->validateBasicFormat($value, $fail)) {
            return;
        }

        $parts = parse_url($value);
        $host = strtolower($parts['host']);
        $host = $this->normalizeHost($host);

        if ($this->checkNeverRoutableHosts($host, $fail)
            || $this->checkIPv4Ranges($host, $fail)
            || $this->checkIPv6Ranges($host, $fail)
            || $this->checkReservedSuffixes($host, $fail)
            || $this->checkDockerServiceName($host, $fail)) {
            return;
        }
    }

    private function isNatted(): bool
    {
        $req = request();
        $natType = $req?->input('nat_type');
        $transportMethod = $req?->input('transport_method');

        // RT-04 (#378): 'unknown' is not a topology assertion — do not relax the
        // routable-host check for it. An unknown-NAT node goes through the strict
        // public-host validation like any direct node.
        return is_string($natType) && $natType !== '' && $natType !== 'none' && $natType !== 'unknown'
            && is_string($transportMethod) && $transportMethod !== ''
            && $transportMethod !== 'direct';
    }

    private function validateNattedEndpoint(mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('IICP-E035: endpoint must be a non-empty string.');

            return;
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['host'])) {
            $fail('IICP-E035: endpoint is not a valid URL.');

            return;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (! in_array($scheme, $this->allowedSchemes, true)) {
            $allowed = implode(', ', $this->allowedSchemes);
            $fail("IICP-E035: endpoint scheme '{$scheme}' not allowed (expected one of: {$allowed}).");
        }
    }

    private function validateBasicFormat(mixed $value, Closure $fail): bool
    {
        if (! is_string($value) || $value === '') {
            $fail('IICP-E035: endpoint must be a non-empty string.');

            return false;
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['host'])) {
            $fail('IICP-E035: endpoint is not a valid URL.');

            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if (! in_array($scheme, $this->allowedSchemes, true)) {
            $allowed = implode(', ', $this->allowedSchemes);
            $fail("IICP-E035: endpoint scheme '{$scheme}' not allowed (expected one of: {$allowed}).");

            return false;
        }

        return true;
    }

    private function normalizeHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    private function checkNeverRoutableHosts(string $host, Closure $fail): bool
    {
        $bracketed = '['.$host.']';
        if (in_array($host, self::NEVER_ROUTABLE_HOSTS, true)
            || in_array($bracketed, self::NEVER_ROUTABLE_HOSTS, true)) {
            $fail("IICP-E035: endpoint host '{$host}' is not routable from external clients. Use a public DNS name or a routable IP.");

            return true;
        }

        return false;
    }

    private function checkIPv4Ranges(string $host, Closure $fail): bool
    {
        if (preg_match('/^127\./', $host)) {
            $fail("IICP-E035: endpoint host '{$host}' is in 127.0.0.0/8 loopback range.");

            return true;
        }

        if ($host === '::1') {
            $fail("IICP-E035: endpoint host '{$host}' is IPv6 loopback ::1.");

            return true;
        }

        if (preg_match('/^10\./', $host)
            || preg_match('/^192\.168\./', $host)
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
            $fail("IICP-E035: endpoint host '{$host}' is in an RFC1918 private range.");

            return true;
        }

        if (preg_match('/^169\.254\./', $host)) {
            $fail("IICP-E035: endpoint host '{$host}' is in 169.254.0.0/16 link-local range.");

            return true;
        }

        if (preg_match('/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./', $host)) {
            $fail("IICP-E035: endpoint host '{$host}' is in 100.64.0.0/10 CGNAT range — directly-routable inbound is blocked at the ISP layer. Use a tunnel (Cloudflare Tunnel / tailscale funnel) and advertise its public hostname.");

            return true;
        }

        return false;
    }

    private function checkIPv6Ranges(string $host, Closure $fail): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $hostLc = strtolower($host);
        if (preg_match('/^fe[89ab][0-9a-f]?:/i', $hostLc)) {
            $fail("IICP-E035: endpoint host '{$host}' is in IPv6 link-local fe80::/10 range.");

            return true;
        }

        if (preg_match('/^f[cd][0-9a-f]{2}:/i', $hostLc)) {
            $fail("IICP-E035: endpoint host '{$host}' is in IPv6 unique-local fc00::/7 range.");

            return true;
        }

        if (preg_match('/^ff[0-9a-f]{2}:/i', $hostLc)) {
            $fail("IICP-E035: endpoint host '{$host}' is in IPv6 multicast ff00::/8 range.");

            return true;
        }

        if (preg_match('/^2001:db8:/i', $hostLc)) {
            $fail("IICP-E035: endpoint host '{$host}' is in IPv6 documentation 2001:db8::/32 range.");

            return true;
        }

        return false;
    }

    private function checkReservedSuffixes(string $host, Closure $fail): bool
    {
        foreach (self::NEVER_ROUTABLE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                $fail("IICP-E035: endpoint host '{$host}' uses reserved suffix '{$suffix}'.");

                return true;
            }
        }

        return false;
    }

    private function checkDockerServiceName(string $host, Closure $fail): bool
    {
        if (! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $fail("IICP-E035: endpoint host '{$host}' has no domain part — likely a Docker-compose service name. Use a public DNS name.");

            return true;
        }

        return false;
    }
}
