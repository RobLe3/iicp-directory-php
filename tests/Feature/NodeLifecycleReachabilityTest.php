<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #325 Layer 3 — periodic re-verification of public reachability.
 *
 * The NodeLifecycle command's verifyReachability() pass runs in production
 * only; tests force config('app.env') to 'production' so the path is exercised.
 */
class NodeLifecycleReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.env', 'production');
    }

    private function createActiveNode(array $overrides = []): Node
    {
        return Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('t', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'status' => 'active',
            'public_reachable' => true,
            'last_seen' => now(),
        ], $overrides));
    }

    public function test_reachable_node_stays_public(): void
    {
        $node = $this->createActiveNode(['endpoint' => 'https://reachable.test']);
        Http::fake(['https://reachable.test/iicp/health' => Http::response('ok', 200)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $this->assertTrue((bool) $node->fresh()->public_reachable);
    }

    public function test_unreachable_node_demoted(): void
    {
        $node = $this->createActiveNode(['endpoint' => 'https://broken.test']);
        Http::fake(['https://broken.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $this->assertFalse((bool) $node->fresh()->public_reachable);
    }

    public function test_demote_emits_reachability_demote_event(): void
    {
        // #413 — the transition that hides a node from discover must be recorded in
        // the signed node_events log (fails without the NodeEventLogger emit).
        $node = $this->createActiveNode(['endpoint' => 'https://gone.test']);
        Http::fake(['https://gone.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $this->assertFalse((bool) $node->fresh()->public_reachable);
        $event = NodeEvent::where('event_type', 'REACHABILITY_DEMOTE')
            ->where('node_id', $node->id)
            ->first();
        $this->assertNotNull($event, 'REACHABILITY_DEMOTE event must be written on demote');
        $this->assertSame(true, $event->payload['from']);
        $this->assertSame(false, $event->payload['to']);
        $this->assertSame('probe_non_2xx', $event->payload['reason']);
        $this->assertSame('node_lifecycle', $event->payload['probe_source']);
        $this->assertSame('https://gone.test', $event->payload['endpoint']);
    }

    public function test_single_transient_failure_does_not_demote(): void
    {
        // #413 — confirm-probe guard: one failed dial-back followed by success is a
        // transient blip, not an outage. Node stays public; no demote event.
        $node = $this->createActiveNode(['endpoint' => 'https://blip.test']);
        Http::fake(['https://blip.test/iicp/health' => Http::sequence()
            ->push('', 503)   // first probe fails
            ->push('ok', 200), // confirm-probe succeeds
        ]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $this->assertTrue((bool) $node->fresh()->public_reachable);
        $this->assertSame(0, NodeEvent::where('event_type', 'REACHABILITY_DEMOTE')
            ->where('node_id', $node->id)->count());
    }

    public function test_natted_node_skipped(): void
    {
        // NATted nodes can't be dial-back-probed by the directory; trust their
        // traversal claim until Phase 6 transport-aware probes land.
        $node = $this->createActiveNode([
            'endpoint' => 'https://natted.test',
            'transport_method' => 'upnp_mapped',
            'nat_type' => 'full_cone',
        ]);
        Http::fake(['https://natted.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        // Stays true despite the broken response — wasn't probed
        $this->assertTrue((bool) $node->fresh()->public_reachable);
    }

    public function test_already_internal_node_unchanged(): void
    {
        $node = $this->createActiveNode([
            'endpoint' => 'https://internal.test',
            'public_reachable' => false,
        ]);
        Http::fake(['https://internal.test/iicp/health' => Http::response('ok', 200)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        // Internal nodes aren't probed — they didn't claim public reachability
        $this->assertFalse((bool) $node->fresh()->public_reachable);
    }

    public function test_non_production_env_skips_reachability_pass(): void
    {
        Config::set('app.env', 'staging');
        $node = $this->createActiveNode(['endpoint' => 'https://staging-broken.test']);
        Http::fake(['https://staging-broken.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        // Non-prod skip — would have been demoted in prod
        $this->assertTrue((bool) $node->fresh()->public_reachable);
    }

    public function test_liveness_verified_node_skips_probe(): void
    {
        // #493 — ADR-047 Part A: a node that answered the HMAC challenge recently is
        // cryptographically confirmed live; skip the TCP probe to avoid demoting nodes
        // that the directory host can't reach outbound (DomainFactory egress limitation).
        $node = $this->createActiveNode([
            'endpoint' => 'https://liveness-verified.test',
            'liveness_verified_at' => now()->subSeconds(30),
        ]);
        // Probe would fail — but the test asserts it is never sent.
        Http::fake(['https://liveness-verified.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        // Still public — liveness challenge proof overrides the dial-back failure.
        $this->assertTrue((bool) $node->fresh()->public_reachable);
        $this->assertSame(0, NodeEvent::where('event_type', 'REACHABILITY_DEMOTE')
            ->where('node_id', $node->id)->count(), 'liveness-verified node must not be demoted');
    }

    public function test_ipv6_literal_without_directory_ipv6_egress_is_not_demoted(): void
    {
        Config::set('app.iicp_probe_ipv6_egress', false);
        $node = $this->createActiveNode([
            'endpoint' => 'http://[2a0a:a543::1]:9484',
            'transport_method' => 'direct',
            'public_reachable' => true,
            'liveness_verified_at' => now()->subSeconds(30),
        ]);
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $fresh = $node->fresh();
        $this->assertTrue((bool) $fresh->public_reachable);
        $this->assertNull($fresh->endpoint_verified_dead_at);
        Http::assertNothingSent();
    }

    public function test_liveness_verified_ipv6_clears_stale_dead_flag_without_probe(): void
    {
        Config::set('app.iicp_probe_ipv6_egress', false);
        $node = $this->createActiveNode([
            'endpoint' => 'http://[2a0a:a543::1]:9484',
            'transport_method' => 'direct',
            'public_reachable' => false,
            'endpoint_verified_dead_at' => now()->subMinutes(10),
            'liveness_verified_at' => now()->subSeconds(30),
        ]);
        Http::fake(['*' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $fresh = $node->fresh();
        $this->assertFalse((bool) $fresh->public_reachable);
        $this->assertNull($fresh->endpoint_verified_dead_at);
        Http::assertNothingSent();
        $event = NodeEvent::where('event_type', 'REACHABILITY_RESTORE')
            ->where('node_id', $node->id)
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('liveness_verified_ipv6_probe_unavailable', $event->payload['reason']);
        $this->assertSame(true, $event->payload['endpoint_verified_dead_at_cleared']);
    }

    public function test_liveness_expired_node_is_probed(): void
    {
        // #493 — an old liveness_verified_at (outside the 300s window) does not confer
        // protection. The probe runs, and on double failure the node is demoted.
        $node = $this->createActiveNode([
            'endpoint' => 'https://liveness-expired.test',
            'liveness_verified_at' => now()->subSeconds(400),
        ]);
        Http::fake(['https://liveness-expired.test/iicp/health' => Http::response('', 503)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $this->assertFalse((bool) $node->fresh()->public_reachable);
        $this->assertSame(1, NodeEvent::where('event_type', 'REACHABILITY_DEMOTE')
            ->where('node_id', $node->id)->count());
    }

    public function test_dead_external_tunnel_endpoint_is_flagged_and_hidden_from_relay_discover(): void
    {
        // #536 regression: a relay/tunnel exposure_mode used to bypass
        // public_reachable=false in discover, so a dead Quick Tunnel URL could be
        // served as relay-capable. Confirmed-dead listed endpoints must be hidden.
        $node = $this->createActiveNode([
            'endpoint' => 'https://dead-tunnel.example.test',
            'transport_method' => 'external_tunnel',
            'exposure_mode' => 'relay_required',
            'public_reachable' => false,
            'relay_capable' => true,
        ]);
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]);
        Http::fake(['https://dead-tunnel.example.test/iicp/health' => Http::response('', 530)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $fresh = $node->fresh();
        $this->assertFalse((bool) $fresh->public_reachable);
        $this->assertNotNull($fresh->endpoint_verified_dead_at);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&relay_capable=true')
            ->assertStatus(200);
        $this->assertSame(0, $resp->json('count'));
        $this->assertNotContains($node->id, collect($resp->json('nodes'))->pluck('node_id')->all());
    }

    public function test_recovered_external_tunnel_endpoint_clears_dead_flag_and_returns_to_discover(): void
    {
        $node = $this->createActiveNode([
            'endpoint' => 'https://recovered-tunnel.example.test',
            'transport_method' => 'external_tunnel',
            'exposure_mode' => 'relay_required',
            'public_reachable' => false,
            'endpoint_verified_dead_at' => now()->subMinutes(10),
            'relay_capable' => true,
        ]);
        $node->capabilities()->create([
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]);
        Http::fake(['https://recovered-tunnel.example.test/iicp/health' => Http::response('ok', 200)]);

        $this->artisan('iicp:node-lifecycle')->assertSuccessful();

        $fresh = $node->fresh();
        $this->assertTrue((bool) $fresh->public_reachable);
        $this->assertNull($fresh->endpoint_verified_dead_at);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&relay_capable=true')
            ->assertStatus(200)
            ->assertJsonPath('count', 1);
        $this->assertSame($node->id, $resp->json('nodes.0.node_id'));
    }
}
