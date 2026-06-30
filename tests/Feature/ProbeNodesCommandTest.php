<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\TelemetryProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #373 Phase B: directory active per-node reachability probing.
 * Tests the iicp:probe-nodes Artisan command.
 */
class ProbeNodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(string $endpoint, bool $available = true): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => $endpoint,
            'region' => 'eu-central',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 1000,
            'available' => $available,
            'last_seen' => now()->subSeconds(30),
        ]);
    }

    /** Reachable node: probe records passed=true and probe row references node_id. */
    public function test_probe_records_reachable_node(): void
    {
        $node = $this->makeNode('https://probe-ok.example.com');

        Http::fake([
            'https://probe-ok.example.com/iicp/health' => Http::response('', 200),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNotNull($probe, 'Probe row must be created for the node');
        $this->assertTrue($probe->passed, 'Probe must be marked passed for a 200 response');
        $this->assertEquals('DIR-PROBE-NODE-01', $probe->test_id);
    }

    /** Unreachable public node: probe records passed=false (downgrade signal is useful). */
    public function test_probe_records_unreachable_public_node(): void
    {
        $node = $this->makeNode('https://probe-fail.example.com');
        $node->update(['public_reachable' => true]);

        Http::fake([
            'https://probe-fail.example.com/iicp/health' => Http::response('', 503),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNotNull($probe);
        $this->assertFalse($probe->passed, 'Probe must be marked failed for a 5xx response');
    }

    /**
     * Behavior test: relay-only node (public_reachable=false) that fails probe
     * must NOT create a TelemetryProbe — the directory's IPv4-only origin cannot
     * reach IPv6 relay endpoints, so failure is a false negative.
     * This test fails if the no-false-negative guard is removed.
     */
    public function test_relay_only_node_failure_not_recorded(): void
    {
        $node = $this->makeNode('http://[2a0a:a543:df54::1]:9484');
        $node->update(['public_reachable' => false, 'relay_capable' => true]);

        Http::fake([
            'http://[2a0a:a543:df54::1]:9484/iicp/health' => Http::response('', 503),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNull($probe, 'A failed probe for a relay-only node must NOT be recorded (false negative guard)');
    }

    /**
     * #413 — a false→true promote must emit a REACHABILITY_RESTORE event so the
     * node's (re)appearance in discover is auditable (fails without the emit).
     */
    public function test_promote_emits_reachability_restore_event(): void
    {
        $node = $this->makeNode('https://comesback.example.com');
        $node->update(['public_reachable' => false]); // currently hidden from discover

        Http::fake(['https://comesback.example.com/iicp/health' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertTrue((bool) $node->fresh()->public_reachable);
        $event = NodeEvent::where('event_type', 'REACHABILITY_RESTORE')
            ->where('node_id', $node->id)
            ->first();
        $this->assertNotNull($event, 'REACHABILITY_RESTORE event must be written on promote');
        $this->assertSame(false, $event->payload['from']);
        $this->assertSame(true, $event->payload['to']);
        $this->assertSame('directory_active_probe', $event->payload['probe_source']);
    }

    /** Already-public node that stays reachable must NOT re-emit a restore event. */
    public function test_already_public_node_does_not_re_emit_restore(): void
    {
        $node = $this->makeNode('https://stable.example.com');
        $node->update(['public_reachable' => true]);

        Http::fake(['https://stable.example.com/iicp/health' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertSame(0, NodeEvent::where('event_type', 'REACHABILITY_RESTORE')
            ->where('node_id', $node->id)->count(), 'No event when there is no transition');
    }

    /** #536 — an active probe success clears the confirmed-dead endpoint flag. */
    public function test_reachable_dead_flagged_node_clears_endpoint_dead_flag(): void
    {
        $node = $this->makeNode('https://dead-flag-clears.example.com');
        $node->update(['public_reachable' => true, 'endpoint_verified_dead_at' => now()->subMinutes(15)]);

        Http::fake(['https://dead-flag-clears.example.com/iicp/health' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertTrue((bool) $node->fresh()->public_reachable);
        $this->assertNull($node->fresh()->endpoint_verified_dead_at);
        $event = NodeEvent::where('event_type', 'REACHABILITY_RESTORE')
            ->where('node_id', $node->id)
            ->first();
        $this->assertNotNull($event, 'Dead-flag clear must be auditable');
        $this->assertSame(true, $event->payload['endpoint_verified_dead_at_cleared']);
    }

    /** SSRF guard: private endpoints must not be probed and no false-negative probe is filed. */
    public function test_private_endpoint_is_skipped(): void
    {
        $node = $this->makeNode('http://192.168.1.50:8090');

        Http::fake(); // Should NOT be called for private IPs

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        // No probe record: private endpoints are not public_reachable, so a failure is
        // not filed (same no-false-negative guard as relay-only nodes).
        $this->assertNull(
            TelemetryProbe::where('node_id', $node->id)->where('probe_type', 'reachability')->first(),
            'No probe row for private endpoint (SSRF guard + no false-negative rule)',
        );

        Http::assertNothingSent();
    }

    /** Loopback endpoint must not be probed, no false-negative probe filed. */
    public function test_loopback_endpoint_is_skipped(): void
    {
        $node = $this->makeNode('http://127.0.0.1:8090');

        Http::fake();

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertNull(
            TelemetryProbe::where('node_id', $node->id)->where('probe_type', 'reachability')->first(),
            'No probe row for loopback endpoint',
        );
        Http::assertNothingSent();
    }

    /** #559: root 404 must not matter when canonical /iicp/health is healthy. */
    public function test_root_404_but_health_endpoint_200_records_passed(): void
    {
        $node = $this->makeNode('https://probe-health.example.com');

        Http::fake([
            'https://probe-health.example.com' => Http::response('not found', 404),
            'https://probe-health.example.com/iicp/health' => Http::response([
                'status' => 'ok',
                'node_id' => $node->id,
                'available' => true,
            ], 200),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)->first();
        $this->assertNotNull($probe);
        $this->assertTrue($probe->passed, 'GET /iicp/health 200 must record passed=true even when / is 404');
        $this->assertSame('/iicp/health', $probe->metadata['path'] ?? null);
        Http::assertSent(fn ($request) => $request->url() === 'https://probe-health.example.com/iicp/health');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://probe-health.example.com');
    }

    /** Rust/TS/Python health JSON with status=ok and available boolean is accepted. */
    public function test_health_json_shape_is_accepted(): void
    {
        $node = $this->makeNode('https://probe-rust-sdk.example.com');

        Http::fake([
            'https://probe-rust-sdk.example.com/iicp/health' => Http::response([
                'status' => 'ok',
                'node_id' => $node->id,
                'region' => 'eu-central',
                'available' => true,
                'models' => ['qwen2.5:0.5b'],
            ], 200),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)->first();
        $this->assertNotNull($probe);
        $this->assertTrue($probe->passed);
    }

    /**
     * Behavior test (no-IPv6-egress guard, 2026-06-11): an IPv6-literal endpoint
     * cannot be reached from an origin without IPv6 egress, so probing it only
     * records false negatives. With iicp_probe_ipv6_egress=false (default) the
     * node must be skipped entirely — no HTTP call, no TelemetryProbe row —
     * even when it self-attests public_reachable=true.
     */
    public function test_ipv6_literal_skipped_without_egress(): void
    {
        $node = $this->makeNode('http://[2a0a:a543:df54:0:888:9777:7743:8ae]:9484');
        $node->update(['public_reachable' => true]);

        Http::fake();

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertNull(
            TelemetryProbe::where('node_id', $node->id)->where('probe_type', 'reachability')->first(),
            'IPv6-literal endpoint must not be probed or recorded when origin has no IPv6 egress',
        );
        Http::assertNothingSent();
    }

    /** With IICP_PROBE_IPV6_EGRESS=true the IPv6-literal endpoint IS probed and recorded. */
    public function test_ipv6_literal_probed_with_egress_enabled(): void
    {
        config(['app.iicp_probe_ipv6_egress' => true]);

        $node = $this->makeNode('http://[2a0a:a543:df54:0:888:9777:7743:8ae]:9484');

        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)->where('probe_type', 'reachability')->first();
        $this->assertNotNull($probe, 'IPv6 endpoint must be probed when egress is enabled');
        $this->assertTrue($probe->passed);
    }

    /** Batch limit: --batch=1 probes at most 1 node. */
    public function test_batch_limit_respected(): void
    {
        $this->makeNode('https://n1.example.com');
        $this->makeNode('https://n2.example.com');

        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes', ['--batch' => 1])->assertSuccessful();

        $this->assertEquals(1, TelemetryProbe::where('probe_type', 'reachability')->count(),
            '--batch=1 must probe only one node');
    }

    /** Unavailable node is not probed. */
    public function test_unavailable_node_not_probed(): void
    {
        $node = $this->makeNode('https://unavailable.example.com', available: false);

        Http::fake();

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertEquals(0, TelemetryProbe::where('node_id', $node->id)->count(),
            'Unavailable node must not be probed');
    }
}
