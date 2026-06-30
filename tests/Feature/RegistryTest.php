<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Services\NodeEventLogger;
use App\Services\NodeScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegistryTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveNode(string $region, array $intents = [], float $multiplier = 1.0): Node
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => $region,
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'status' => 'active',
            'load' => 0.2,
            'active_jobs' => 1,
            'last_seen' => now(),
            'credit_cost_multiplier' => $multiplier,
            'pricing_model' => 'per_token',
            'attested' => false,
        ]);
        foreach ($intents as $intent) {
            $node->capabilities()->create([
                'intent' => $intent,
                'models' => ['llama-3-8b'],
                'max_tokens' => 4096,
            ]);
        }

        return $node;
    }

    private function seedLifecycleEvent(string $nodeId, string $type, int $tsMs): void
    {
        NodeEvent::create([
            'event_id' => (string) Str::uuid(),
            'seq' => (NodeEvent::max('seq') ?? 0) + 1,
            'event_type' => $type,
            'node_id' => $nodeId,
            'ts_ms' => $tsMs,
            'payload' => [],
            'prev_hash' => NodeEventLogger::GENESIS_ROOT,
            'signature' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/registry/stats
    // -------------------------------------------------------------------------

    public function test_registry_stats_returns_active_and_total_counts(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('us-east', ['urn:iicp:intent:llm:chat:v1']);

        // Inactive node — not counted in active_nodes
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://stale.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 1000,
            'available' => true,
            'load' => 0.0,
            'active_jobs' => 0,
            'last_seen' => now()->subSeconds(120),
        ]);

        $response = $this->getJson('/api/v1/registry/stats');

        $response->assertStatus(200)
            ->assertJsonStructure(['total_nodes', 'active_nodes', 'regions', 'region_breakdown', 'intent_coverage'])
            ->assertJsonPath('active_nodes', 2)
            ->assertJsonPath('total_nodes', 3);
    }

    public function test_registry_stats_includes_regional_breakdown(): void
    {
        $this->createActiveNode('eu-central');
        $this->createActiveNode('eu-central');
        $this->createActiveNode('us-east');

        $response = $this->getJson('/api/v1/registry/stats');

        $response->assertStatus(200);
        $breakdown = $response->json('region_breakdown');
        $this->assertEquals(2, $breakdown['eu-central']);
        $this->assertEquals(1, $breakdown['us-east']);
    }

    public function test_registry_stats_includes_intent_coverage(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('us-east', [
            'urn:iicp:intent:llm:chat:v1',
            'urn:iicp:intent:llm:completion:v1',
        ]);

        $response = $this->getJson('/api/v1/registry/stats');

        $coverage = $response->json('intent_coverage');
        $this->assertEquals(2, $coverage['urn:iicp:intent:llm:chat:v1']);
        $this->assertEquals(1, $coverage['urn:iicp:intent:llm:completion:v1']);
    }

    // -------------------------------------------------------------------------
    // GET /v1/registry/intents
    // -------------------------------------------------------------------------

    public function test_registry_intents_returns_urn_list_with_counts(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('us-east', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('ap-south', ['urn:iicp:intent:llm:embedding:v1']);

        $response = $this->getJson('/api/v1/registry/intents');

        $response->assertStatus(200)->assertJsonStructure(['intents' => [['urn', 'node_count']]]);
        $intents = collect($response->json('intents'))->keyBy('urn');
        $this->assertEquals(2, $intents['urn:iicp:intent:llm:chat:v1']['node_count']);
        $this->assertEquals(1, $intents['urn:iicp:intent:llm:embedding:v1']['node_count']);
    }

    // -------------------------------------------------------------------------
    // GET /v1/registry/nodes
    // -------------------------------------------------------------------------

    public function test_registry_nodes_returns_public_fields_without_private_data(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);

        $response = $this->getJson('/api/v1/registry/nodes');

        $response->assertStatus(200)
            ->assertJsonStructure(['total', 'page', 'limit', 'nodes' => [[
                'node_id_prefix', 'region', 'intents',
                'directory_observed_reachable', 'route_evidence', 'routing_hint', 'browser_usable',
                'backend_stability' => ['backend_state', 'reason_class', 'routing_guard', 'evidence'],
                'performance' => ['task_latency_ms', 'task_latency_ms_basis', 'health_impact'],
                'health_summary' => ['score', 'label', 'confidence', 'evidence_level', 'latency_ms_basis', 'components'],
                'active', 'trust_progress' => ['completed_tasks', 'tasks_until_gold', 'probation'],
                'status_summary' => [
                    'state', 'headline', 'description', 'reasons', 'evidence_gaps',
                    'evidence_last_refreshed_at', 'evidence_source', 'health_basis',
                    'trust_progress' => ['completed_tasks', 'tasks_until_gold', 'probation'],
                ],
            ]]]);

        // Private fields must NOT be present
        $node = $response->json('nodes.0');
        $this->assertArrayNotHasKey('endpoint', $node);
        $this->assertArrayNotHasKey('node_token_hash', $node);
        $this->assertArrayNotHasKey('node_hmac_key', $node);
        $this->assertEquals(8, strlen($node['node_id_prefix']));  // prefix only
        $this->assertSame('https_direct', $node['routing_hint']);
        $this->assertTrue($node['browser_usable']);
        $this->assertSame('unknown', $node['backend_stability']['backend_state']);
    }

    public function test_registry_nodes_status_summary_distinguishes_limited_reach_from_public_routable(): void
    {
        $limited = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $limited->update([
            'endpoint' => 'http://[2001:db8::1]:9484',
            'public_reachable' => false,
            'exposure_mode' => null,
            'sdk_version' => '0.7.68',
            'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
        ]);

        $response = $this->getJson('/api/v1/registry/nodes')->assertOk();
        $node = $response->json('nodes.0');

        $this->assertTrue($node['active']);
        $this->assertSame('limited_reach', $node['status_summary']['state']);
        $this->assertFalse($node['status_summary']['public_routable']);
        $this->assertContains('limited_or_unverified_reach', $node['status_summary']['reasons']);
    }

    public function test_registry_nodes_explains_high_score_probation_gate(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $node->update([
            'public_reachable' => true,
            'sdk_version' => '0.7.72',
            'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
        ]);
        $node->reputation()->create([
            'score' => 0.995,
            'tasks_total' => 79,
            'tasks_failed' => 0,
            'completed_tasks_count' => 79,
            'avg_latency_ms' => 120,
        ]);

        $response = $this->getJson('/api/v1/registry/nodes')->assertOk();

        $this->assertSame('silver', $response->json('nodes.0.reputation_tier'));
        $this->assertTrue($response->json('nodes.0.probation'));
        $this->assertSame(79, $response->json('nodes.0.trust_progress.completed_tasks'));
        $this->assertSame(21, $response->json('nodes.0.trust_progress.tasks_until_gold'));
        $this->assertSame('task_history_probation', $response->json('nodes.0.status_summary.evidence_gaps.0.code'));
    }

    public function test_registry_health_summary_exposes_raw_and_display_band(): void
    {
        $summary = NodeScorer::healthSummary([
            'score' => 84,
            'label' => 'degraded',
            'confidence' => 'low',
            'evidence_level' => 'self_attested',
            'latency_ms_basis' => 'none',
            'observed' => false,
            'components' => [
                'reachability' => 1.0,
                'latency' => 0.5,
                'uptime' => null,
                'stability' => null,
            ],
            'evaluated_at' => now()->toIso8601String(),
        ]);

        $this->assertSame('degraded', $summary['label']);
        $this->assertSame('evidence limited', $summary['display']['label']);
        $this->assertSame('steady', $summary['display']['tone']);
        $this->assertSame('degraded', $summary['display']['raw_label']);
        $this->assertTrue($summary['display']['near_threshold']);
    }

    public function test_status_summary_does_not_flap_at_healthy_boundary(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $node->update([
            'public_reachable' => true,
            'sdk_version' => '0.7.72',
            'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
        ]);

        $nearHealthy = NodeScorer::statusSummary($node, [
            'score' => 84,
            'label' => 'degraded',
            'confidence' => 'low',
            'evidence_level' => 'self_attested',
            'latency_ms_basis' => 'none',
            'observed' => false,
            'components' => [],
            'evaluated_at' => now()->toIso8601String(),
        ]);
        $healthy = NodeScorer::statusSummary($node, [
            'score' => 85,
            'label' => 'healthy',
            'confidence' => 'medium',
            'evidence_level' => 'directory_observed',
            'latency_ms_basis' => 'directory_probe',
            'observed' => true,
            'components' => [],
            'evaluated_at' => now()->toIso8601String(),
        ]);

        $this->assertSame('ready', $nearHealthy['state']);
        $this->assertSame('ready', $healthy['state']);
        $this->assertContains('health_degraded', $nearHealthy['reasons']);
        $this->assertContains('health_healthy', $healthy['reasons']);
    }

    public function test_registry_nodes_filters_by_region(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('us-east', ['urn:iicp:intent:llm:chat:v1']);

        $response = $this->getJson('/api/v1/registry/nodes?region=eu-central');

        $response->assertStatus(200)->assertJsonPath('total', 1);
        $this->assertEquals('eu-central', $response->json('nodes.0.region'));
    }

    public function test_registry_nodes_filters_by_intent(): void
    {
        $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $this->createActiveNode('us-east', ['urn:iicp:intent:llm:embedding:v1']);

        $response = $this->getJson('/api/v1/registry/nodes?intent=urn:iicp:intent:llm:embedding:v1');

        $response->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_registry_nodes_filters_by_cip_enabled(): void
    {
        $cipNode = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $cipNode->update(['allow_remote_inference' => true]);

        $this->createActiveNode('us-east', ['urn:iicp:intent:llm:chat:v1']); // cip=false

        $response = $this->getJson('/api/v1/registry/nodes?cip_enabled=1');

        $response->assertStatus(200)->assertJsonPath('total', 1);
        $this->assertTrue($response->json('nodes.0.cip_enabled'));
    }

    // -------------------------------------------------------------------------
    // GET /v1/registry/nodes/{prefix} — REG-01: no private fields (ADR-017)
    // -------------------------------------------------------------------------

    public function test_registry_node_show_returns_public_profile(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1'], 1.25);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'node_id_prefix', 'region', 'active', 'last_seen',
                'reputation_score', 'intents', 'models',
                'credit_cost_multiplier', 'pricing_model', 'attested',
            ])
            ->assertJsonPath('node_id_prefix', $prefix)
            ->assertJsonPath('region', 'eu-central')
            ->assertJsonPath('credit_cost_multiplier', 1.25);

        // REG-01: no private data
        $body = $response->json();
        $this->assertArrayNotHasKey('endpoint', $body);
        $this->assertArrayNotHasKey('node_token_hash', $body);
        $this->assertArrayNotHasKey('id', $body);
    }

    // ADR-044 (#372) — registry profile carries the per-node health vector +
    // exposure_mode so the website node-detail page can render them.
    public function test_registry_node_show_includes_health_and_exposure_mode(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1'], 1.0);
        $node->update(['exposure_mode' => 'ipv4_public_direct', 'public_reachable' => true]);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'health' => ['score', 'label', 'observed', 'components' => [
                    'liveness', 'reachability', 'latency', 'uptime', 'stability', 'freshness',
                ], 'confidence', 'evidence_level', 'latency_ms_basis'],
                'health_summary' => ['score', 'label', 'confidence', 'evidence_level', 'latency_ms_basis', 'components'],
                'directory_observed_reachable',
                'route_evidence',
                'routing_hint',
                'browser_usable',
                'performance' => ['task_latency_ms', 'task_latency_ms_basis', 'health_impact'],
                'trust_progress' => ['completed_tasks', 'tasks_until_gold', 'probation'],
                'status_summary' => [
                    'state', 'headline', 'description', 'reasons', 'evidence_gaps',
                    'evidence_last_refreshed_at', 'evidence_source', 'health_basis',
                    'trust_progress' => ['completed_tasks', 'tasks_until_gold', 'probation'],
                ],
                'capability_summary' => ['model_count_registered', 'model_count_live', 'model_family_count', 'modalities', 'quality_evidence'],
                'exposure_mode',
            ])
            ->assertJsonPath('health.confidence', 'low')
            ->assertJsonPath('exposure_mode', 'ipv4_public_direct')
            ->assertJsonPath('route_evidence', 'self_attested')
            ->assertJsonPath('routing_hint', 'https_direct')
            ->assertJsonPath('browser_usable', true);

        // No endpoint/secrets leaked alongside the new fields (REG-01 still holds).
        $this->assertArrayNotHasKey('endpoint', $response->json());
    }

    public function test_registry_node_show_exposes_backend_stability_separately_from_health(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $node->update([
            'backend_stability' => [
                'backend_state' => 'degraded',
                'reason_class' => 'backend_cold',
                'retry_after_s' => 45,
            ],
        ]);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}")->assertOk();

        $response->assertJsonPath('backend_stability.backend_state', 'degraded')
            ->assertJsonPath('backend_stability.reason_class', 'backend_cold')
            ->assertJsonPath('backend_stability.routing_guard', 'observe_only')
            ->assertJsonPath('status_summary.backend_stability.backend_state', 'degraded');
        $this->assertArrayHasKey('health', $response->json());
        $this->assertArrayNotHasKey('endpoint', $response->json());
    }

    public function test_registry_node_show_status_summary_explains_keyless_downlevel_node(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $node->update([
            'public_reachable' => true,
            'sdk_version' => '0.7.62',
            'cx_public_key' => null,
        ]);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}")->assertOk();

        $this->assertSame('upgrade_privacy_needed', $response->json('status_summary.state'));
        $this->assertTrue($response->json('status_summary.public_routable'));
        $this->assertFalse($response->json('status_summary.key_ready'));
        $this->assertFalse($response->json('status_summary.client_current'));
        $this->assertContains('encryption_key_missing', $response->json('status_summary.reasons'));
        $this->assertContains('client_upgrade_needed', $response->json('status_summary.reasons'));
    }

    public function test_registry_node_show_status_summary_marks_unverified_ipv6_direct_without_calling_it_broken(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $node->update([
            'endpoint' => 'http://[2a0a:a543::1]:9484',
            'public_reachable' => false,
            'exposure_mode' => 'ipv6_direct_firewall_required',
            'sdk_version' => '0.7.68',
            'cx_public_key' => ['algorithm' => 'X25519', 'key' => 'abc'],
        ]);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}")->assertOk();

        $this->assertSame('direct_unverified', $response->json('status_summary.state'));
        $this->assertSame('http_ipv6', $response->json('routing_hint'));
        $this->assertNull($response->json('directory_observed_reachable'));
        $this->assertTrue($response->json('status_summary.public_routable'));
        $this->assertSame('ipv6_not_directory_verified', $response->json('status_summary.evidence_gaps.1.code'));
    }

    // #397 — node detail surfaces the transport protocols a node speaks
    // (http/https/iicp-native), derived from endpoint schemes (no host leaked).
    public function test_registry_node_show_includes_transport_methods(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        // https endpoint (factory default) + native IICP-TCP transport endpoint.
        $node->update(['transport_endpoint' => 'iicp://node.example.com:9484']);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200)
            ->assertJsonStructure(['transport'])
            ->assertJsonPath('transport', ['https', 'iicp-native']);
        // Privacy: protocol tokens only, no host/endpoint leaked.
        $this->assertArrayNotHasKey('endpoint', $response->json());
        $this->assertArrayNotHasKey('transport_endpoint', $response->json());
    }

    public function test_registry_node_show_populates_verified_uptime_and_stability_components(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $prefix = substr($node->id, 0, 8);
        $this->seedLifecycleEvent($node->id, 'REGISTER', (int) (microtime(true) * 1000) - 3_600_000);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200);
        $this->assertIsNumeric($response->json('health.components.uptime'));
        $this->assertIsNumeric($response->json('health.components.stability'));
    }

    public function test_registry_node_show_returns_uptime_history_structure(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200)->assertJsonStructure(['uptime_history']);
        $history = $response->json('uptime_history');
        $this->assertIsArray($history);
        // Each entry must have date and uptime_pct
        foreach ($history as $entry) {
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('uptime_pct', $entry);
            $this->assertArrayHasKey('evidence', $entry);
            $this->assertArrayHasKey('observed_heartbeat_count', $entry);
            $this->assertArrayHasKey('expected_heartbeat_count', $entry);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['date']);
            $this->assertGreaterThanOrEqual(0.0, $entry['uptime_pct']);
            $this->assertLessThanOrEqual(100.0, $entry['uptime_pct']);
        }
    }

    public function test_registry_node_show_uptime_reflects_heartbeat_records(): void
    {
        $node = $this->createActiveNode('eu-central');
        $prefix = substr($node->id, 0, 8);
        $today = now()->format('Y-m-d');

        // Insert 1440 heartbeat records for today (half-day's worth)
        $rows = [];
        for ($i = 0; $i < 1440; $i++) {
            $rows[] = [
                'node_id' => $node->id,
                'ip_address' => '1.2.3.4',
                'request_type' => 'heartbeat',
                'observed_at' => now()->subSeconds($i * 60),
            ];
        }
        \DB::table('node_address_history')->insert($rows);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200);
        $history = collect($response->json('uptime_history'))->keyBy('date');
        $this->assertArrayHasKey($today, $history->all());
        // Today had 1440 records: uptime_pct must be > 0
        $this->assertGreaterThan(0.0, $history[$today]['uptime_pct']);
        $this->assertSame('heartbeat_samples', $history[$today]['evidence']);
    }

    public function test_registry_node_show_returns_empty_latency_percentiles_when_no_telemetry(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $prefix = substr($node->id, 0, 8);

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200)->assertJsonPath('latency_percentiles', []);
    }

    public function test_registry_node_show_computes_latency_percentiles_from_telemetry(): void
    {
        $node = $this->createActiveNode('eu-central', ['urn:iicp:intent:llm:chat:v1']);
        $prefix = substr($node->id, 0, 8);

        // Insert 10 telemetry records with known latencies
        $base = now()->subDays(1)->getTimestamp();
        for ($i = 0; $i < 10; $i++) {
            \DB::table('proxy_telemetry')->insert([
                'node_id' => $node->id,
                'proxy_node_id' => (string) Str::uuid(),
                'time_bucket' => $base + ($i * 60),
                'latency_ms_observed' => ($i + 1) * 10.0,   // 10, 20, 30 … 100
                'tokens_observed' => 100,
                'status' => 'success',
                'created_at' => now()->subHours(1),
                'updated_at' => now()->subHours(1),
            ]);
        }

        $response = $this->getJson("/api/v1/registry/nodes/{$prefix}");

        $response->assertStatus(200);
        $pct = $response->json('latency_percentiles');
        $this->assertNotEmpty($pct);
        $this->assertArrayHasKey('p50_ms', $pct);
        $this->assertArrayHasKey('p95_ms', $pct);
        $this->assertArrayHasKey('p99_ms', $pct);
        $this->assertArrayHasKey('sample_count', $pct);
        $this->assertEquals(10, $pct['sample_count']);
        $this->assertEquals(7, $pct['window_days']);
        // Sorted: 10,20,30,40,50,60,70,80,90,100 — p50 idx=4 → 50ms
        $this->assertEquals(50.0, $pct['p50_ms']);
        $this->assertGreaterThanOrEqual($pct['p50_ms'], $pct['p95_ms']);
        $this->assertGreaterThanOrEqual($pct['p95_ms'], $pct['p99_ms']);
    }

    public function test_registry_node_show_returns_404_for_unknown_prefix(): void
    {
        $response = $this->getJson('/api/v1/registry/nodes/00000000');
        $response->assertStatus(404)->assertJsonPath('error', 'REGISTRY-NODE-NOT-FOUND');
    }

    public function test_registry_node_show_returns_422_for_invalid_prefix(): void
    {
        $response = $this->getJson('/api/v1/registry/nodes/GGGGGGGG');
        // Route constraint [0-9a-f]{8} rejects non-hex, fallback 404 from router is acceptable
        $this->assertContains($response->status(), [404, 422]);
    }

    // -------------------------------------------------------------------------
    // ADR-017 public listing opt-in (REG-01)
    // -------------------------------------------------------------------------

    public function test_nodes_does_not_expose_operator_url_by_default(): void
    {
        $node = $this->createActiveNode('eu-central');
        $node->update(['operator_url' => 'https://mynode.example.com', 'public_listing' => false]);

        $response = $this->getJson('/api/v1/registry/nodes');
        $response->assertOk();
        $items = $response->json('nodes');
        $this->assertNotEmpty($items);
        $this->assertArrayNotHasKey('operator_url', $items[0]);
    }

    public function test_nodes_exposes_operator_url_when_public_listing_enabled(): void
    {
        $node = $this->createActiveNode('eu-central');
        $node->update([
            'operator_url' => 'https://mynode.example.com',
            'public_listing' => true,
        ]);

        $response = $this->getJson('/api/v1/registry/nodes');
        $response->assertOk();
        $items = $response->json('nodes');
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('operator_url', $items[0]);
        $this->assertEquals('https://mynode.example.com', $items[0]['operator_url']);
        $this->assertTrue($items[0]['public_listing']);
    }

    public function test_nodes_never_exposes_operator_contact(): void
    {
        $node = $this->createActiveNode('eu-central');
        $node->update([
            'operator_contact' => 'operator@example.com',
            'public_listing' => true,
        ]);

        $response = $this->getJson('/api/v1/registry/nodes');
        $response->assertOk();
        $items = $response->json('nodes');
        $this->assertNotEmpty($items);
        $this->assertArrayNotHasKey('operator_contact', $items[0]);
    }
}
