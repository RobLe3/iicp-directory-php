<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use App\Rules\RoutableEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Performs registration and endpoint-rotation dial-back checks.
 */
final class NodeEndpointVerifier
{
    private const LIVENESS_TIMEOUT_S = 5;

    public function isAlive(string $endpoint): bool
    {
        if (config('iicp.registry.skip_liveness_check', false)) {
            return false;
        }

        $target = $this->safeProbeTarget($endpoint);
        if ($target === null) {
            return false;
        }

        try {
            return Http::timeout(self::LIVENESS_TIMEOUT_S)
                ->withoutVerifying()
                ->withOptions($target[1])
                ->get($endpoint.'/iicp/health')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertReachable(string $endpoint): void
    {
        if (config('iicp.registry.skip_liveness_check', false)) {
            return;
        }

        $target = $this->safeProbeTarget($endpoint);
        if ($target === null) {
            throw ValidationException::withMessages([
                'endpoint' => 'IICP-E035: endpoint host resolves to a non-routable / internal '
                    .'address, or is unresolvable.',
            ]);
        }

        try {
            $response = Http::timeout(self::LIVENESS_TIMEOUT_S)
                ->withoutVerifying()
                ->withOptions($target[1])
                ->get($endpoint.'/iicp/health');

            if ($response->failed()) {
                throw ValidationException::withMessages([
                    'endpoint' => 'IICP-E036: endpoint unreachable from directory (HTTP '
                        .$response->status().'). Verify port-forwarding / public_endpoint.',
                ]);
            }
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'endpoint' => 'IICP-E036: endpoint unreachable from directory (cannot reach '
                    .$endpoint.'). Verify port-forwarding / public_endpoint.',
            ]);
        }
    }

    /**
     * Resolve, validate, and pin an endpoint immediately before dialing it.
     *
     * @return array{0:string,1:array<string,mixed>}|null
     */
    private function safeProbeTarget(string $endpoint): ?array
    {
        if (config('app.env') === 'local' || config('app.env') === 'testing') {
            return ['', []];
        }

        $host = $this->probeHost($endpoint);
        if ($host === null) {
            return null;
        }

        $ips = $this->probeIps($host);
        if ($ips === [] || $this->hasBlockedProbeIp($ips)) {
            return null;
        }

        $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($endpoint, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
        $pin = $ips[0];

        return [$pin, ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$pin}"]]]];
    }

    private function probeHost(string $endpoint): ?string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return trim($host, '[]');
    }

    /** @return list<string> */
    private function probeIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = $this->ipv4Records($host);
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            $ips = array_merge($ips, $this->ipv6Records($aaaa));
        }

        return $ips;
    }

    /** @return list<string> */
    private function ipv4Records(string $host): array
    {
        $records = @gethostbynamel($host);

        return is_array($records) ? $records : [];
    }

    /**
     * @param  list<array<string,mixed>>  $records
     * @return list<string>
     */
    private function ipv6Records(array $records): array
    {
        return array_values(array_filter(array_map(
            fn (array $record) => empty($record['ipv6']) ? null : $record['ipv6'],
            $records,
        )));
    }

    /** @param list<string> $ips */
    private function hasBlockedProbeIp(array $ips): bool
    {
        foreach ($ips as $ip) {
            if (RoutableEndpoint::ipIsBlocked($ip)) {
                return true;
            }
        }

        return false;
    }
}
