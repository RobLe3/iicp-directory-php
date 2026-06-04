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
            'https://probe-ok.example.com' => Http::response('', 200),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNotNull($probe, 'Probe row must be created for the node');
        $this->assertTrue($probe->passed, 'Probe must be marked passed for a 200 response');
        $this->assertEquals('DIR-PROBE-NODE-01', $probe->test_id);
    }

    /** Unreachable node: probe records passed=false. */
    public function test_probe_records_unreachable_node(): void
    {
        $node = $this->makeNode('https://probe-fail.example.com');

        Http::fake([
            'https://probe-fail.example.com' => Http::response('', 503),
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNotNull($probe);
        $this->assertFalse($probe->passed, 'Probe must be marked failed for a 5xx response');
    }

    /**
     * #413 — a false→true promote must emit a REACHABILITY_RESTORE event so the
     * node's (re)appearance in discover is auditable (fails without the emit).
     */
    public function test_promote_emits_reachability_restore_event(): void
    {
        $node = $this->makeNode('https://comesback.example.com');
        $node->update(['public_reachable' => false]); // currently hidden from discover

        Http::fake(['https://comesback.example.com' => Http::response('', 200)]);

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

        Http::fake(['https://stable.example.com' => Http::response('', 200)]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $this->assertSame(0, NodeEvent::where('event_type', 'REACHABILITY_RESTORE')
            ->where('node_id', $node->id)->count(), 'No event when there is no transition');
    }

    /** SSRF guard: private/loopback endpoints must not be probed. */
    public function test_private_endpoint_is_skipped(): void
    {
        $node = $this->makeNode('http://192.168.1.50:8090');

        Http::fake(); // Should NOT be called for private IPs

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();

        $this->assertNotNull($probe, 'Probe row created even for private endpoint (SSRF result)');
        $this->assertFalse($probe->passed, 'Private endpoint probe must record passed=false');

        Http::assertNothingSent();
    }

    /** Loopback endpoint must not be probed. */
    public function test_loopback_endpoint_is_skipped(): void
    {
        $node = $this->makeNode('http://127.0.0.1:8090');

        Http::fake();

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)
            ->where('probe_type', 'reachability')
            ->first();
        $this->assertNotNull($probe);
        $this->assertFalse($probe->passed);
        Http::assertNothingSent();
    }

    /** HEAD fallback to GET on 405: still records passed=true. */
    public function test_head_405_falls_back_to_get(): void
    {
        $node = $this->makeNode('https://probe-no-head.example.com');

        Http::fake([
            'https://probe-no-head.example.com' => Http::sequence()
                ->push('', 405)   // HEAD → 405
                ->push('ok', 200), // GET → 200
        ]);

        $this->artisan('iicp:probe-nodes')->assertSuccessful();

        $probe = TelemetryProbe::where('node_id', $node->id)->first();
        $this->assertNotNull($probe);
        $this->assertTrue($probe->passed, 'HEAD 405 + GET 200 must record passed=true');
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
