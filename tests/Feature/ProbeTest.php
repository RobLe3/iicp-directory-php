<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Http\Controllers\ProbeController;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    // 422 on private/loopback addresses (SSRF protection)
    public function test_probe_rejects_loopback(): void
    {
        $response = $this->getJson('/api/v1/probe?host=127.0.0.1&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_localhost_name(): void
    {
        $response = $this->getJson('/api/v1/probe?host=localhost&port=9484');
        $response->assertStatus(422);
    }

    public function test_probe_rejects_private_10_block(): void
    {
        $response = $this->getJson('/api/v1/probe?host=10.0.0.1&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_private_192_168_block(): void
    {
        $response = $this->getJson('/api/v1/probe?host=192.168.1.1&port=9484');
        $response->assertStatus(422);
    }

    // Validation — missing required fields
    public function test_probe_requires_host(): void
    {
        $response = $this->getJson('/api/v1/probe?port=9484');
        $response->assertStatus(422);
    }

    public function test_probe_requires_port(): void
    {
        $response = $this->getJson('/api/v1/probe?host=1.2.3.4');
        $response->assertStatus(422);
    }

    public function test_probe_rejects_reserved_port(): void
    {
        $response = $this->getJson('/api/v1/probe?host=1.2.3.4&port=80');
        $response->assertStatus(422);
    }

    public function test_probe_returns_unreachable_for_dead_host(): void
    {
        // 192.0.2.0/24 is TEST-NET-1 (RFC 5737) — routable but unassigned
        $response = $this->getJson('/api/v1/probe?host=192.0.2.1&port=9484');
        $response->assertStatus(200);
        $this->assertFalse($response->json('reachable'));
        $this->assertNotNull($response->json('error'));
        $this->assertNull($response->json('latency_ms'));
    }

    // Response shape validation
    public function test_probe_response_has_required_fields(): void
    {
        $response = $this->getJson('/api/v1/probe?host=192.0.2.1&port=9484');
        $response->assertJsonStructure(['reachable', 'latency_ms', 'error']);
    }

    // DNS rebinding hardening (#227) — IP pinning
    public function test_probe_rejects_unresolvable_hostname(): void
    {
        // A hostname that cannot resolve returns 422 with unresolvable_host
        $response = $this->getJson('/api/v1/probe?host=this-host-does-not-exist.invalid&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('unresolvable_host', $response->json('error'));
    }

    public function test_probe_rejects_localhost_hostname_resolution(): void
    {
        // gethostbyname('localhost') → '127.0.0.1' which is in BLOCKED_CIDR
        $response = $this->getJson('/api/v1/probe?host=localhost&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_link_local(): void
    {
        $response = $this->getJson('/api/v1/probe?host=169.254.1.1&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_172_16_block(): void
    {
        $response = $this->getJson('/api/v1/probe?host=172.20.0.1&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    // IPv6 SSRF protection — internal ranges must be rejected (the probe now
    // accepts IPv6 so CGNAT nodes advertising an IPv6 GUA can be verified).
    public function test_probe_rejects_ipv6_loopback(): void
    {
        $response = $this->getJson('/api/v1/probe?host='.urlencode('::1').'&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_ipv6_link_local(): void
    {
        $response = $this->getJson('/api/v1/probe?host='.urlencode('fe80::1').'&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    public function test_probe_rejects_ipv6_unique_local(): void
    {
        $response = $this->getJson('/api/v1/probe?host='.urlencode('fd00::1').'&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    // Critical: an IPv4-mapped IPv6 literal must not bypass the IPv4 blocklist.
    public function test_probe_rejects_ipv4_mapped_ipv6(): void
    {
        $response = $this->getJson('/api/v1/probe?host='.urlencode('::ffff:10.0.0.1').'&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }

    // A routable IPv6 GUA must pass the SSRF guard (200, not 422) — reachability
    // itself depends on the test host's IPv6 stack, so only the guard is asserted.
    public function test_probe_accepts_ipv6_gua_past_ssrf_guard(): void
    {
        $response = $this->getJson('/api/v1/probe?host='.urlencode('2001:db8::1').'&port=9484');
        $response->assertStatus(200);
        $response->assertJsonStructure(['reachable', 'latency_ms', 'error']);
    }

    // #276 — fail-closed: exception in resolveToIp/isBlockedIp must return 422 not 500
    public function test_probe_exception_in_resolution_returns_422_not_500(): void
    {
        // Bind a controller variant that throws from resolveToIp to simulate opcache fault
        $this->app->bind(
            ProbeController::class,
            function () {
                return new class extends ProbeController
                {
                    protected function resolveToIp(string $host): ?string
                    {
                        throw new \RuntimeException('Simulated PHP-FPM opcache stale-class fault');
                    }
                };
            }
        );

        $response = $this->getJson('/api/v1/probe?host=1.2.3.4&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
        $this->assertFalse($response->json('reachable'));
    }

    public function test_probe_exception_in_blocked_ip_check_returns_422_not_500(): void
    {
        $this->app->bind(
            ProbeController::class,
            function () {
                return new class extends ProbeController
                {
                    protected function isBlockedIp(string $ip): bool
                    {
                        throw new \RuntimeException('Simulated exception in CIDR check');
                    }
                };
            }
        );

        $response = $this->getJson('/api/v1/probe?host=1.2.3.4&port=9484');
        $response->assertStatus(422);
        $this->assertEquals('private_address', $response->json('error'));
    }
}
