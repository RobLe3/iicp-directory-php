<?php

namespace Tests\Feature;

use App\Rules\RoutableEndpoint;
use App\Services\NodeEndpointVerifier;
use ReflectionMethod;
use Tests\TestCase;

/**
 * #535 — liveness-probe DNS-rebinding / SSRF guard.
 *
 * RoutableEndpoint blocks dangerous *literal* hosts at registration, but the directory's
 * liveness probe (assertLive at register, isEndpointAlive for the IICP-E050 re-register
 * gate) must also reject a DNS name that RESOLVES to an internal address — and pin the
 * connection so a rebind can't redirect the dial. These tests pin both layers.
 */
class LivenessProbeSsrfTest extends TestCase
{
    public function test_internal_reserved_and_cgnat_ips_are_blocked(): void
    {
        $blocked = [
            '127.0.0.1', '127.255.255.254',          // loopback v4
            '10.0.0.1', '172.16.5.5', '192.168.1.1', // RFC1918
            '169.254.169.254',                        // link-local / cloud metadata
            '100.64.0.1', '100.127.255.255',          // CGNAT 100.64/10 (RFC6598)
            '0.0.0.0',                                // unspecified
            '::1', 'fc00::1', 'fe80::1',              // v6 loopback / ULA / link-local
        ];
        foreach ($blocked as $ip) {
            $this->assertTrue(RoutableEndpoint::ipIsBlocked($ip), "{$ip} must be blocked as a probe target");
        }
    }

    public function test_public_ips_are_allowed(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '2001:4860:4860::8888'] as $ip) {
            $this->assertFalse(RoutableEndpoint::ipIsBlocked($ip), "{$ip} is public and must be allowed");
        }
    }

    private function safeProbeTarget(string $endpoint): ?array
    {
        // The guard intentionally no-ops in local/testing (parity with RoutableEndpoint);
        // force production so these tests exercise the real resolve/validate/pin logic.
        config(['app.env' => 'production']);
        $m = new ReflectionMethod(NodeEndpointVerifier::class, 'safeProbeTarget');
        $m->setAccessible(true);

        return $m->invoke(app(NodeEndpointVerifier::class), $endpoint);
    }

    public function test_literal_internal_endpoint_is_refused(): void
    {
        $this->assertNull($this->safeProbeTarget('https://127.0.0.1/'));
        $this->assertNull($this->safeProbeTarget('http://10.0.0.5:9484'));
        $this->assertNull($this->safeProbeTarget('https://169.254.169.254/'));
    }

    public function test_dns_name_resolving_internal_is_refused(): void
    {
        // localhost resolves to 127.0.0.1 via /etc/hosts (no network) — the exact
        // "name that resolves internal" class the literal-host check misses.
        $this->assertNull($this->safeProbeTarget('http://localhost:9484'));
    }

    public function test_public_endpoint_is_allowed_and_pinned(): void
    {
        $target = $this->safeProbeTarget('https://1.1.1.1/');
        $this->assertIsArray($target);
        $this->assertSame('1.1.1.1', $target[0]);
        // Connection is pinned to the validated IP so a rebind can't redirect it.
        $this->assertArrayHasKey('curl', $target[1]);
        $this->assertSame(['1.1.1.1:443:1.1.1.1'], $target[1]['curl'][CURLOPT_RESOLVE]);
    }
}
