<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use App\Models\Reputation;
use App\Models\TelemetryProbe;
use App\Services\NodePolicyManifestVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private function createNode(array $overrides = [], array $capabilities = []): Node
    {
        $node = Node::create(array_merge([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'load' => 0.2,
            'active_jobs' => 1,
            'last_seen' => now(),
            // #326 — test fixtures default to public_reachable so existing
            // discover assertions don't need per-test setup. Override with
            // ['public_reachable' => false] when testing the new filter.
            'public_reachable' => true,
        ], $overrides));

        foreach ($capabilities as $cap) {
            $node->capabilities()->create($cap);
        }

        return $node;
    }

    private function withReputation(Node $node, float $score = 0.5, int $completedTasks = 0): void
    {
        Reputation::create([
            'node_id' => $node->id,
            'score' => $score,
            'tasks_total' => max(1, $completedTasks),
            'tasks_failed' => 0,
            'completed_tasks_count' => $completedTasks,
            'avg_latency_ms' => 120.0,
        ]);
    }

    public function test_discover_exposes_only_consumer_cosignature_readiness_boolean(): void
    {
        $this->createNode([
            'supported_receipt_profiles' => ['consumer_cosignature_v1'],
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertOk();
        $this->assertTrue($response->json('nodes.0.consumer_cosignature_ready'));
        $this->assertStringNotContainsString('supported_receipt_profiles', $response->getContent());
    }

    public function test_discover_includes_operator_display_name_for_bound_nodes_never_the_key(): void
    {
        // #463 — a delegation-verified node surfaces its operator's public display_name in discover;
        // an unbound node does not; the operator_pubkey is NEVER returned.
        Operator::create(['operator_pubkey' => 'OPKEY', 'display_name' => 'ZeroKelvinMoralist']);
        $this->createNode(['operator_pubkey' => 'OPKEY', 'operator_verified' => true], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 4096,
        ]]);
        $this->createNode([], [[ // unbound node — no operator_display_name
            'intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertStatus(200);
        $names = collect($resp->json('nodes'))->pluck('operator_display_name')->filter()->values()->all();
        $fingerprints = collect($resp->json('nodes'))->pluck('operator_fingerprint')->filter()->values()->all();
        $this->assertContains('ZeroKelvinMoralist', $names);
        $this->assertCount(1, $names, 'only the bound node carries operator_display_name');
        $this->assertContains(Operator::publicFingerprint('OPKEY'), $fingerprints);
        $this->assertCount(1, $fingerprints, 'only the bound node carries operator_fingerprint');
        $this->assertStringNotContainsString('OPKEY', $resp->getContent(), 'operator_pubkey must never be served');
    }

    public function test_discover_health_enrichment_uses_bounded_queries_for_many_nodes(): void
    {
        // Discovery renders health evidence for every candidate.  Keep that
        // evidence and operator enrichment batch-loaded so adding providers
        // cannot create an N+1 query path on an origin cache miss.
        foreach (['OPKEY-A', 'OPKEY-B'] as $key) {
            Operator::create(['operator_pubkey' => $key, 'display_name' => "Operator {$key}"]);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->createNode([
                'operator_pubkey' => $i % 2 === 0 ? 'OPKEY-A' : 'OPKEY-B',
                'operator_verified' => true,
            ], [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['m'],
                'max_tokens' => 4096,
            ]]);
        }

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertOk();
        $selects = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_starts_with(strtolower(ltrim($query['query'])), 'select'));
        $operatorSelects = $selects->filter(
            fn (array $query) => preg_match('/\bfrom\s+["`]?operators["`]?\b/i', $query['query'])
        );

        $this->assertLessThanOrEqual(
            12,
            $selects->count(),
            'Discovery must batch health, lifecycle and operator evidence rather than query per returned node.',
        );
        $this->assertCount(1, $operatorSelects, 'all operator display names must be loaded in one query');
    }

    public function test_discover_surfaces_self_attested_node_policy_manifest(): void
    {
        $this->createNode([
            'policy_manifest' => [
                'version' => '2026-07-02',
                'jurisdiction' => 'DE',
                'remote_executor_can_read_prompt' => true,
                'training_use' => 'none',
                'retention' => ['task_payload' => 'none', 'logs_days' => 3],
                'subprocessors' => ['self-hosted'],
            ],
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertStatus(200);

        $resp->assertJsonPath('nodes.0.node_policy_manifest.jurisdiction', 'DE');
        $resp->assertJsonPath('nodes.0.node_policy_manifest.training_use', 'none');
        $resp->assertJsonPath('nodes.0.node_policy_manifest.evidence', 'self_attested');
    }

    public function test_discover_surfaces_signed_verified_node_policy_manifest(): void
    {
        $this->createNode([
            'policy_manifest' => $this->signedPolicyManifest(),
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertStatus(200);

        $resp->assertJsonPath('nodes.0.node_policy_manifest.evidence', 'signed_verified');
        $resp->assertJsonPath('nodes.0.node_policy_manifest.verification.status', 'signed_valid');
        $resp->assertJsonPath('nodes.0.node_policy_manifest.verification.key_id', 'policy-key-1');
        $resp->assertJsonPath('nodes.0.node_policy_manifest.manifest_identity_level', 'signed_valid');
        $this->assertNotNull($resp->json('nodes.0.node_policy_manifest.policy_key_fingerprint'));
    }

    public function test_discover_surfaces_operator_bound_policy_manifest_without_raw_operator_key(): void
    {
        [$manifest, $operatorPubkey] = $this->signedPolicyManifestWithOperatorKey();
        $this->createNode([
            'operator_pubkey' => $operatorPubkey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
            'policy_manifest' => $manifest,
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')->assertStatus(200);

        $resp->assertJsonPath('nodes.0.node_policy_manifest.manifest_identity_level', 'operator_bound');
        $resp->assertJsonPath(
            'nodes.0.node_policy_manifest.operator_fingerprint',
            Operator::publicFingerprint($operatorPubkey),
        );
        $this->assertStringNotContainsString($operatorPubkey, $resp->getContent());
    }

    public function test_discover_refuses_prohibited_intent_before_scoring(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:social-scoring:score:v1');

        $resp->assertStatus(422);
        $this->assertStringContainsString('IICP directory policy', $resp->getContent());
    }

    public function test_discover_refuses_high_risk_intent_before_scoring(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:employment:hiring-decision:v1');

        $resp->assertStatus(422);
        $this->assertStringContainsString('high_risk', $resp->getContent());
    }

    public function test_discover_rejects_prompt_payload_fields(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['m'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&prompt=GDPR_CANARY_PROMPT_DO_NOT_LOG_20260701');

        $resp->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.fields.prompt.0', 'Discovery is control-plane only; send task payloads directly to the selected node.');
        $this->assertStringContainsString('Discovery is control-plane only', $resp->getContent());
    }

    public function test_discover_profile_negotiation_is_additive_and_required_mismatches_fail_closed(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 4096,
        ]]);

        $fixture = json_decode(file_get_contents(base_path('parity/profile-negotiation-v0.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as $case) {
            $query = array_merge(['intent' => 'urn:iicp:intent:llm:chat:v1'], $case['request']);
            $response = $this->getJson('/api/v1/discover?'.http_build_query($query));
            $expected = $case['expected'];
            if (($expected['requested'] ?? false) === false) {
                $response->assertOk()->assertJsonMissingPath('profile_negotiation');

                continue;
            }
            $response->assertStatus($expected['dispatch_allowed'] ? 200 : 422)
                ->assertJsonPath('profile_negotiation.dispatch_allowed', $expected['dispatch_allowed']);
            foreach (['status', 'reason'] as $field) {
                if (array_key_exists($field, $expected)) {
                    $response->assertJsonPath("profile_negotiation.$field", $expected[$field]);
                }
            }
        }
    }

    public function test_discover_public_view_redacts_route_endpoints_and_full_node_ids(): void
    {
        Cache::flush();
        $node = $this->createNode([
            'endpoint' => 'https://associated-green-levy-lesser.trycloudflare.com',
            'transport_endpoint' => 'iicpsec://associated-green-levy-lesser.trycloudflare.com',
            'transport_method' => 'external_tunnel',
            'nat_type' => 'unknown',
            'transport_metadata' => ['detection_log_tail' => ['rung 5: quick tunnel']],
            'cx_public_key' => [
                'algorithm' => 'X25519',
                'key' => 'abc',
                'features' => ['response_encryption_v1'],
            ],
            'relay_capable' => true,
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&view=public')
            ->assertStatus(200)
            ->assertHeader('X-IICP-Discover-Data-Class', 'public_presentation')
            ->assertJsonPath('view', 'public')
            ->assertJsonPath('data_class', 'public_presentation')
            ->assertJsonPath('route_fields_present', false)
            ->assertJsonPath('nodes.0.node_id_prefix', substr($node->id, 0, 8))
            ->assertJsonPath('nodes.0.route_class', 'external_tunnel')
            ->assertJsonPath('nodes.0.key_ready', true)
            ->assertJsonPath('nodes.0.response_encryption_ready', true);

        $publicNode = $resp->json('nodes.0');
        $this->assertArrayNotHasKey('node_id', $publicNode);
        $this->assertArrayNotHasKey('endpoint', $publicNode);
        $this->assertArrayNotHasKey('transport_endpoint', $publicNode);
        $this->assertArrayNotHasKey('transport_metadata', $publicNode);
        $this->assertArrayNotHasKey('cx_public_key', $publicNode);
        $this->assertArrayNotHasKey('public_key', $publicNode);
        $this->assertArrayNotHasKey('features', $publicNode);

        $content = $resp->getContent();
        $this->assertStringNotContainsString($node->id, $content);
        $this->assertStringNotContainsString('associated-green-levy-lesser.trycloudflare.com', $content);
        $this->assertStringNotContainsString('iicpsec://', $content);
    }

    public function test_discover_default_dispatch_view_keeps_route_fields_for_client_compatibility(): void
    {
        Cache::flush();
        $node = $this->createNode([
            'endpoint' => 'https://dispatch-route.example.com',
            'transport_endpoint' => 'iicpsec://dispatch-route.example.com',
            'transport_method' => 'external_tunnel',
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertStatus(200)
            ->assertHeader('X-IICP-Discover-Data-Class', 'route_dispatch')
            ->assertJsonPath('view', 'dispatch')
            ->assertJsonPath('data_class', 'route_dispatch')
            ->assertJsonPath('route_fields_present', true)
            ->assertJsonPath('nodes.0.node_id', $node->id)
            ->assertJsonPath('nodes.0.endpoint', 'https://dispatch-route.example.com')
            ->assertJsonPath('nodes.0.transport_endpoint', 'iicpsec://dispatch-route.example.com');
    }

    private function signedPolicyManifest(): array
    {
        return $this->signedPolicyManifestWithOperatorKey()[0];
    }

    /** @return array{0: array<string,mixed>, 1: string} */
    private function signedPolicyManifestWithOperatorKey(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyB64 = base64_encode($publicKey);
        $manifest = [
            'version' => '2026-07-02',
            'jurisdiction' => 'DE',
            'remote_executor_can_read_prompt' => true,
            'training_use' => 'none',
            'retention' => ['task_payload' => 'none', 'logs_days' => 3],
            'subprocessors' => ['self-hosted'],
            'unsupported_intents' => [],
        ];
        $manifest['signature'] = [
            'algorithm' => 'Ed25519',
            'key_id' => 'policy-key-1',
            'public_key' => $publicKeyB64,
            'signed_at' => now()->subMinute()->toIso8601String(),
            'expires_at' => now()->addDay()->toIso8601String(),
        ];
        $manifest['signature']['signature'] = base64_encode(sodium_crypto_sign_detached(
            NodePolicyManifestVerifier::canonicalPayload($manifest),
            $secretKey,
        ));

        return [$manifest, $publicKeyB64];
    }

    public function test_discovers_node_matching_intent(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonStructure(['nodes' => [['node_id', 'endpoint', 'score', 'available', 'region']]]);
    }

    public function test_discover_includes_capability_summary_without_changing_default_score(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama3:latest', 'qwen2.5:0.5b'],
            'max_tokens' => 4096,
            'input_modalities' => ['text'],
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)
            ->assertJsonStructure(['nodes' => [[
                'score',
                'performance' => ['task_latency_ms', 'task_latency_ms_basis', 'health_impact'],
                'capability_summary' => ['model_count_registered', 'model_count_live', 'model_family_count', 'modalities', 'quality_evidence'],
            ]]])
            ->assertJsonMissingPath('nodes.0.routing_score_v2')
            ->assertJsonPath('nodes.0.capability_summary.model_count_registered', 2)
            ->assertJsonPath('nodes.0.capability_summary.model_count_live', 2);
    }

    public function test_discover_surfaces_degraded_backend_stability_without_hiding_node(): void
    {
        $this->createNode([
            'backend_stability' => [
                'backend_state' => 'degraded',
                'reason_class' => 'backend_cold',
            ],
        ], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama3:latest'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&limit=9');

        $response->assertStatus(200)
            ->assertJsonPath('count', 1)
            ->assertJsonPath('nodes.0.backend_stability.backend_state', 'degraded')
            ->assertJsonPath('nodes.0.backend_stability.routing_guard', 'observe_only');
    }

    public function test_discover_excludes_backend_draining_nodes_from_normal_admission(): void
    {
        $cap = [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama3:latest'],
            'max_tokens' => 4096,
        ]];
        $this->createNode(['endpoint' => 'https://ok.example.com'], $cap);
        $this->createNode([
            'endpoint' => 'https://draining.example.com',
            'backend_stability' => [
                'backend_state' => 'draining',
                'reason_class' => 'backend_loading',
                'retry_after_s' => 120,
            ],
        ], $cap);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&limit=8');

        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame('https://ok.example.com', $response->json('nodes.0.endpoint'));
    }

    public function test_discover_v2_shadow_adds_routing_score_without_replacing_v1_score(): void
    {
        $node = $this->createNode(['load' => 0.0, 'active_jobs' => 0], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama3:latest'],
            'max_tokens' => 4096,
        ]]);
        $this->withReputation($node, score: 0.8, completedTasks: 200);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&score_version=v2_shadow');

        $response->assertStatus(200)
            ->assertJsonStructure(['nodes' => [[
                'score',
                'routing_score_v2',
                'routing_score_v2_components' => ['health', 'capability_fit', 'load_capacity', 'reputation', 'latency', 'uptime_stability', 'price', 'policy_fit'],
            ]]]);
        $this->assertIsFloat($response->json('nodes.0.score'));
        $this->assertIsFloat($response->json('nodes.0.routing_score_v2'));
    }

    /** @test #528 — ?relay_capable=true returns only relay-capable nodes (was a no-op param) */
    public function test_relay_capable_filter_returns_only_relay_nodes(): void
    {
        $cap = [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['llama-3-8b'], 'max_tokens' => 4096]];
        $this->createNode(['relay_capable' => false], $cap);
        $relay = $this->createNode(['relay_capable' => true], $cap);

        // Unfiltered: both nodes.
        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1')
            ->assertStatus(200)->assertJsonPath('count', 2);

        // Filtered: only the relay-capable node.
        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&relay_capable=true')
            ->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame($relay->id, $resp->json('nodes.0.node_id'));
    }

    /**
     * #528 follow-up — regression guard for the historical relay-capable/no-op + default-limit gap:
     * even when non-relay nodes fill the first page, relay filtering must still return only relay-capable rows.
     */
    public function test_relay_capable_filter_is_respected_under_default_limit(): void
    {
        $cap = [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['llama-3-8b'], 'max_tokens' => 4096]];

        // 12 strong non-relay nodes (higher score), then 1 weak relay node.
        // If relay filter is a no-op and default limit is small, relay would be omitted.
        for ($i = 0; $i < 12; $i++) {
            $this->withReputation($this->createNode(['relay_capable' => false], $cap), score: 0.95, completedTasks: 500);
        }

        $relay = $this->createNode(['relay_capable' => true], $cap);
        $this->withReputation($relay, score: 0.05, completedTasks: 0);

        $resp = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&relay_capable=true')
            ->assertStatus(200);

        $this->assertSame(1, count($resp->json('nodes')));
        $this->assertSame($relay->id, $resp->json('nodes.0.node_id'));
        $this->assertSame(true, (bool) $resp->json('nodes.0.relay_capable'));
    }

    public function test_excludes_stale_nodes(): void
    {
        $this->createNode(['last_seen' => now()->subSeconds(95)], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_excludes_unavailable_nodes(): void
    {
        $this->createNode(['available' => false], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_returns_empty_for_unmatched_intent(): void
    {
        $this->createNode([], [[
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['llama-3-8b'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:code:lint:v1');

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_scores_nodes_and_returns_sorted(): void
    {
        $cap = [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 100]];

        $this->createNode(['load' => 0.9, 'active_jobs' => 3], $cap);
        $this->createNode(['load' => 0.1, 'active_jobs' => 0], $cap);

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200);
        $nodes = $response->json('nodes');
        $this->assertCount(2, $nodes);
        $this->assertGreaterThan($nodes[1]['score'], $nodes[0]['score']);
    }

    public function test_respects_limit_parameter(): void
    {
        $cap = [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 100]];

        for ($i = 0; $i < 5; $i++) {
            $this->createNode([], $cap);
        }

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&limit=2');

        $response->assertStatus(200)->assertJsonPath('count', 2);
    }

    public function test_requires_intent_parameter(): void
    {
        $this->getJson('/api/v1/discover')->assertStatus(422);
    }

    public function test_returns_query_ms_in_response(): void
    {
        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1');

        $response->assertStatus(200)->assertJsonStructure(['query_ms']);
    }

    public function test_discover_exposes_safe_origin_cache_state(): void
    {
        $url = '/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&region=cache-contract-unique';
        Cache::flush();

        $first = $this->getJson($url)->assertOk();
        $first->assertHeader('X-IICP-Discover-Origin-Cache', 'miss');
        $missTiming = $first->headers->get('Server-Timing');
        $this->assertMatchesRegularExpression('/iicp_cache;dur=\d+\.\d{3}/', $missTiming);
        $this->assertMatchesRegularExpression('/iicp_db;dur=\d+\.\d{3}/', $missTiming);
        $this->assertMatchesRegularExpression('/iicp_score;dur=\d+\.\d{3}/', $missTiming);
        $this->assertMatchesRegularExpression('/iicp_operator;dur=\d+\.\d{3}/', $missTiming);
        $this->assertStringNotContainsString('cache-contract-unique', $missTiming);

        // A cache header alone is not enough evidence: prove a warm origin
        // request does not re-enter the node scorer/health path. Database-cache
        // bookkeeping may still issue its own query, so look specifically for
        // serving-state tables rather than asserting a blanket query count.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $second = $this->getJson($url)->assertOk();
        $second->assertHeader('X-IICP-Discover-Origin-Cache', 'hit');
        $hitTiming = $second->headers->get('Server-Timing');
        $this->assertMatchesRegularExpression('/iicp_cache;dur=\d+\.\d{3}/', $hitTiming);
        $this->assertStringNotContainsString('iicp_db', $hitTiming);
        $this->assertStringNotContainsString('iicp_score', $hitTiming);
        $this->assertSame($first->json('nodes'), $second->json('nodes'));

        $servingStateSelects = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_starts_with(strtolower(ltrim($query['query'])), 'select'))
            ->filter(fn (array $query) => preg_match('/\b(nodes|capabilities|iicp_telemetry_probes|node_events)\b/i', $query['query']));
        $this->assertCount(0, $servingStateSelects, 'warm discovery must not recompute node scoring or health evidence');
    }

    public function test_discovery_profile_header_is_opt_in_and_content_free(): void
    {
        config()->set('app.iicp_discovery_profile', true);
        Cache::flush();

        $response = $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&region=profile-contract-unique');

        $response->assertOk();
        $profile = $response->headers->get('X-IICP-Discovery-Profile');
        $this->assertMatchesRegularExpression('/iicp_eligibility;dur=\d+\.\d{3}/', $profile);
        $this->assertMatchesRegularExpression('/iicp_ranking;dur=\d+\.\d{3}/', $profile);
        $this->assertMatchesRegularExpression('/iicp_health;dur=\d+\.\d{3}/', $profile);
        $this->assertMatchesRegularExpression('/iicp_projection;dur=\d+\.\d{3}/', $profile);
        $this->assertStringNotContainsString('profile-contract-unique', $profile);
        $this->assertStringNotContainsString('urn:', $profile);
    }

    // --- ADR-021 model filter tests (CIP-D2 / #162) ---

    public function test_model_filter_returns_only_nodes_advertising_the_model(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['endpoint' => 'https://phi.example.com'], [[
            'intent' => $intent, 'models' => ['phi3:mini'], 'max_tokens' => 4096,
        ]]);
        $this->createNode(['endpoint' => 'https://llama.example.com'], [[
            'intent' => $intent, 'models' => ['llama3.2:1b'], 'max_tokens' => 4096,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&model=phi3:mini");

        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame('https://phi.example.com', $response->json('nodes.0.endpoint'));
    }

    public function test_model_filter_excludes_nodes_not_advertising_the_model(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [['intent' => $intent, 'models' => ['llama3.2:1b'], 'max_tokens' => 4096]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&model=phi3:mini");

        $response->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_model_filter_absent_returns_all_matching_nodes(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['phi3:mini'], 'max_tokens' => 4096]];
        $this->createNode([], $cap);
        $this->createNode(['endpoint' => 'https://b.example.com'], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200)->assertJsonPath('count', 2);
    }

    public function test_model_filter_multi_model_node_matches_any_listed_model(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [[
            'intent' => $intent,
            'models' => ['phi3:mini', 'llama3.2:1b', 'qwen2.5:0.5b'],
            'max_tokens' => 4096,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&model=llama3.2:1b");

        $response->assertStatus(200)->assertJsonPath('count', 1);
    }

    public function test_response_includes_models_field(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [[
            'intent' => $intent, 'models' => ['phi3:mini', 'llama3.2:1b'], 'max_tokens' => 4096,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $models = $response->json('nodes.0.models');
        $this->assertContains('phi3:mini', $models);
        $this->assertContains('llama3.2:1b', $models);
    }

    public function test_model_filter_invalid_string_too_long_is_rejected(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $longModel = str_repeat('x', 129);

        $this->getJson("/api/v1/discover?intent={$intent}&model={$longModel}")->assertStatus(422);
    }

    // --- CIP-D2 min_reputation filter tests (#74) ---

    public function test_min_reputation_excludes_low_reputation_nodes(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $highNode = $this->createNode(['endpoint' => 'https://high.example.com'], $cap);
        $highNode->reputation()->create(['score' => 0.9]);

        $lowNode = $this->createNode(['endpoint' => 'https://low.example.com'], $cap);
        $lowNode->reputation()->create(['score' => 0.3]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&min_reputation=0.7");

        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame('https://high.example.com', $response->json('nodes.0.endpoint'));
    }

    public function test_min_reputation_default_zero_returns_all_nodes(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $n1 = $this->createNode([], $cap);
        $n1->reputation()->create(['score' => 0.1]);

        $n2 = $this->createNode(['endpoint' => 'https://b.example.com'], $cap);
        $n2->reputation()->create(['score' => 0.9]);

        // No min_reputation param — default 0.0, all nodes returned
        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200)->assertJsonPath('count', 2);
    }

    public function test_min_reputation_nodes_without_reputation_use_default_score(): void
    {
        // Nodes without a reputation record default to 0.5
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $this->createNode([], $cap);  // no reputation — defaults to 0.5

        // min_reputation=0.4 → 0.5 >= 0.4, node is included
        $this->getJson("/api/v1/discover?intent={$intent}&min_reputation=0.4")
            ->assertStatus(200)->assertJsonPath('count', 1);

        // min_reputation=0.6 → 0.5 < 0.6, node is excluded
        $this->getJson("/api/v1/discover?intent={$intent}&min_reputation=0.6")
            ->assertStatus(200)->assertJsonPath('count', 0);
    }

    public function test_min_reputation_out_of_range_is_rejected(): void
    {
        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&min_reputation=1.5')
            ->assertStatus(422);
        $this->getJson('/api/v1/discover?intent=urn:iicp:intent:llm:chat:v1&min_reputation=-0.1')
            ->assertStatus(422);
    }

    // --- S.12 §5.1.1 reputation_tier tests (REP2) ---

    public function test_reputation_tier_stays_silver_during_low_observation_fast_score_jump(): void
    {
        // #554: probation (<100 completed tasks) still appears no higher than Silver,
        // even if the score has jumped above the Gold threshold.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.75, 'completed_tasks_count' => 50]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('silver', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_bronze_for_score_below_silver(): void
    {
        // CIP spec v0.6.9 (2026-05-30): "none" retired; "bronze" is the floor tier
        // for all sub-Silver nodes (score < 0.40, ≥ 100 completed tasks).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.3, 'completed_tasks_count' => 100]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('bronze', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_silver_for_score_between_040_and_065(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.55, 'completed_tasks_count' => 100]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('silver', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_gold_for_score_between_065_and_085(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.72, 'completed_tasks_count' => 100]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('gold', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_gold_not_platinum_when_score_high_but_identity_age_insufficient(): void
    {
        // score ≥ 0.85 but registered < 720h ago → gold (identity-age conjunctive gate)
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.90, 'completed_tasks_count' => 100]);
        // created_at defaults to now() — age is ~0h, well below 720h

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('gold', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_platinum_for_high_score_and_sufficient_age(): void
    {
        // score ≥ 0.85 AND identity age ≥ 720h AND ≥ 1000 tasks → platinum
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.92, 'completed_tasks_count' => 1000]);
        $node->created_at = now()->subHours(800);
        $node->save();

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('platinum', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_silver_is_default_when_no_reputation_record(): void
    {
        // No reputation record → default score 0.5 → silver tier (score-based, no probation gate).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode([], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('silver', $response->json('nodes.0.reputation_tier'));
    }

    public function test_discover_response_includes_public_cache_control_header(): void
    {
        // Discover is public and unauthenticated, but it carries live serving
        // URLs. Keep CDN staleness short so browser dispatch and relay election
        // see Quick Tunnel rotations quickly.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=5', $cacheControl);
        $this->assertStringContainsString('s-maxage=10', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=5', $cacheControl);
    }

    public function test_cip_capable_filter_returns_only_cip_provider_nodes(): void
    {
        // Only nodes with allow_remote_inference=true should appear when ?cip_capable=true
        // CIP coordinators need to find worker nodes without client-side filtering (S.12 §5.2).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $this->createNode(['endpoint' => 'https://cip.example.com', 'allow_remote_inference' => true], $cap);
        $this->createNode(['endpoint' => 'https://nocip.example.com', 'allow_remote_inference' => false], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&cip_capable=1");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('https://cip.example.com', $response->json('nodes.0.endpoint'));
        $this->assertTrue($response->json('nodes.0.cip_policy.allow_remote_inference'));
    }

    public function test_cip_capable_filter_omitted_returns_all_nodes(): void
    {
        // Without ?cip_capable, both CIP-Provider and CIP-None nodes are returned (backward compatible).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $this->createNode(['endpoint' => 'https://cip.example.com', 'allow_remote_inference' => true], $cap);
        $this->createNode(['endpoint' => 'https://nocip.example.com', 'allow_remote_inference' => false], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('count'));
    }

    public function test_include_internal_accepts_string_true_query_param(): void
    {
        // #413 follow-up — operators debug demoted/vanished nodes via
        // ?include_internal=true. The query string is "true" (not a typed bool);
        // it must be accepted (was 422 "must be true or false") AND surface the
        // otherwise-hidden internal node.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode(['endpoint' => 'https://internal-only.example.com', 'public_reachable' => false], $cap);

        $public = $this->getJson("/api/v1/discover?intent={$intent}");
        $public->assertStatus(200);
        $this->assertSame(0, $public->json('count'), 'internal node hidden by default');

        $response = $this->getJson("/api/v1/discover?intent={$intent}&include_internal=true");
        $response->assertStatus(200); // not 422
        $this->assertSame(1, $response->json('count'), 'include_internal=true surfaces the internal node');
        $this->assertSame('https://internal-only.example.com', $response->json('nodes.0.endpoint'));
    }

    public function test_cip_capable_accepts_string_true_query_param(): void
    {
        // Same string-boolean fix applies to cip_capable=true (was 422).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode(['endpoint' => 'https://cip.example.com', 'allow_remote_inference' => true], $cap);
        $this->createNode(['endpoint' => 'https://nocip.example.com', 'allow_remote_inference' => false], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&cip_capable=true");

        $response->assertStatus(200); // not 422
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('https://cip.example.com', $response->json('nodes.0.endpoint'));
    }

    public function test_include_internal_false_string_keeps_default_filter(): void
    {
        // ?include_internal=false must parse as false (not truthy) and keep the
        // public-only contract — guards against a naive (bool)"false" === true bug.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode(['endpoint' => 'https://internal-only.example.com', 'public_reachable' => false], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&include_internal=false");
        $response->assertStatus(200);
        $this->assertSame(0, $response->json('count'), 'include_internal=false stays public-only');
    }

    public function test_discover_outputs_input_modalities_default_text(): void
    {
        // #408/ADR-046 — capability with no/legacy modality surfaces ["text"].
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");
        $response->assertStatus(200);
        $this->assertSame(['text'], $response->json('nodes.0.input_modalities'));
    }

    public function test_modality_image_filter_returns_only_vision_nodes(): void
    {
        // #408/ADR-046 — ?modality=image returns only nodes whose capability accepts
        // images; text-only nodes are excluded. Fails without the modality filter.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['endpoint' => 'https://vision.example.com'], [[
            'intent' => $intent, 'models' => ['qwen-vl'], 'max_tokens' => 100,
            'input_modalities' => ['text', 'image'],
        ]]);
        $this->createNode(['endpoint' => 'https://textonly.example.com'], [[
            'intent' => $intent, 'models' => ['qwen-text'], 'max_tokens' => 100,
            'input_modalities' => ['text'],
        ]]);

        $all = $this->getJson("/api/v1/discover?intent={$intent}");
        $this->assertSame(2, $all->json('count'), 'no filter → both nodes');

        $imageOnly = $this->getJson("/api/v1/discover?intent={$intent}&modality=image");
        $imageOnly->assertStatus(200);
        $this->assertSame(1, $imageOnly->json('count'), 'modality=image → only the vision node');
        $this->assertSame('https://vision.example.com', $imageOnly->json('nodes.0.endpoint'));
        $this->assertContains('image', $imageOnly->json('nodes.0.input_modalities'));
    }

    public function test_relay_tier_node_is_discoverable_when_dial_back_failed(): void
    {
        // ADR-047 (#411) — a heartbeating node with a routable serving surface
        // (exposure_mode set) but public_reachable=false (directory dial-back failed,
        // e.g. CGNAT/IPv6 no-egress) must STILL appear in default discover, tagged
        // reachability_tier=relay. Before the fix it was hidden (the live active_nodes=0 bug).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode([
            'endpoint' => 'http://[2a0a:a543:df54::8ae]:9487',
            'public_reachable' => false,
            'exposure_mode' => 'ipv6_direct_pinhole_available',
        ], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('count'), 'heartbeating routable node must be discoverable via relay tier');
        $this->assertSame('relay', $response->json('nodes.0.reachability_tier'));
        $this->assertNull($response->json('nodes.0.directory_observed_reachable'));
        $this->assertSame('self_attested', $response->json('nodes.0.route_evidence'));
        $this->assertSame('http_ipv6', $response->json('nodes.0.routing_hint'));
        $this->assertFalse($response->json('nodes.0.browser_usable'));
    }

    public function test_direct_tier_when_dial_back_verified(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode(['endpoint' => 'https://direct.example.com', 'public_reachable' => true], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('direct', $response->json('nodes.0.reachability_tier'));
        $this->assertNull($response->json('nodes.0.directory_observed_reachable'));
        $this->assertSame('self_attested', $response->json('nodes.0.route_evidence'));
        $this->assertSame('https_direct', $response->json('nodes.0.routing_hint'));
        $this->assertTrue($response->json('nodes.0.browser_usable'));
    }

    public function test_relay_service_exposes_browser_usable_routing_signal(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode([
            'endpoint' => 'https://relay.example.com',
            'relay_capable' => true,
            'exposure_mode' => 'relay_required',
        ], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&relay_capable=true");
        $response->assertStatus(200);
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('relay_service', $response->json('nodes.0.routing_hint'));
        $this->assertTrue($response->json('nodes.0.browser_usable'));
        $this->assertSame('self_attested', $response->json('nodes.0.route_evidence'));
    }

    public function test_recent_directory_probe_sets_observed_reachability_signal(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode(['endpoint' => 'https://observed.example.com'], $cap);
        TelemetryProbe::create([
            'probe_token_id' => null,
            'node_id' => $node->id,
            'run_id' => 'test-run',
            'probe_id' => 'dir-node-reachability',
            'probe_type' => 'reachability',
            'test_id' => 'DIR-PROBE-NODE-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 120,
            'detail' => 'endpoint reachable',
            'metadata' => [],
            'probed_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");
        $response->assertStatus(200);
        $this->assertTrue($response->json('nodes.0.directory_observed_reachable'));
        $this->assertSame('directory_observed', $response->json('nodes.0.route_evidence'));
        $this->assertSame('https_direct', $response->json('nodes.0.routing_hint'));
    }

    public function test_internal_node_without_exposure_mode_stays_hidden(): void
    {
        // Legacy/internal node (no exposure_mode) + not dial-back-reachable stays hidden
        // by default — relay tier only un-hides nodes with a declared routable surface.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode([
            'endpoint' => 'http://internal.local:9484',
            'public_reachable' => false,
            'exposure_mode' => null,
        ], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");
        $response->assertStatus(200);
        $this->assertSame(0, $response->json('count'), 'internal node without a routable exposure_mode stays hidden');
    }

    // #246: intent field must have a max length to prevent cache-flooding via unique long strings.
    public function test_rejects_intent_exceeding_max_length(): void
    {
        $longIntent = str_repeat('a', 256);

        $this->getJson("/api/v1/discover?intent={$longIntent}")->assertStatus(422);
    }

    // #300: latency_estimate_ms must surface observed_latency_ms EMA from reputation row.
    public function test_latency_estimate_ms_surfaces_ema_from_reputation(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.7, 'observed_latency_ms' => 142.5]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame(143, $response->json('nodes.0.latency_estimate_ms'));
    }

    // #300: latency_estimate_ms is null when no telemetry received yet.
    public function test_latency_estimate_ms_is_null_without_telemetry(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];

        $this->createNode([], $cap);  // no reputation row

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertNull($response->json('nodes.0.latency_estimate_ms'));
    }

    public function test_discovery_explains_latency_trust_sdk_and_health_without_changing_ranking(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([
            'implementation_name' => 'iicp-web-node',
            'implementation_version' => '0.2.2',
            'sdk_compatibility_version' => '0.7.98',
            'sdk_latest_seen' => '0.7.101',
            'backend_stability' => [
                'backend_state' => 'degraded',
                'reason_class' => 'backend_cold',
            ],
        ], $cap);
        $node->reputation()->create([
            'score' => 0.60,
            'completed_tasks_count' => 104,
            'observed_latency_ms' => 142.5,
        ]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertOk()
            ->assertJsonPath('nodes.0.implementation_name', 'iicp-web-node')
            ->assertJsonPath('nodes.0.implementation_version', '0.2.2')
            ->assertJsonPath('nodes.0.sdk_compatibility_version', '0.7.98')
            ->assertJsonPath('nodes.0.sdk_version', '0.7.98')
            ->assertJsonPath('nodes.0.latency_evidence.estimate_ms', 143)
            ->assertJsonPath('nodes.0.latency_evidence.basis', 'multi_proxy_ema')
            ->assertJsonPath('nodes.0.trust_progress.gold_task_threshold_met', true)
            ->assertJsonPath('nodes.0.trust_progress.gold_reputation_threshold_met', false)
            ->assertJsonPath('nodes.0.trust_progress.remaining_gold_requirements.0', 'reputation_score')
            ->assertJsonPath('nodes.0.sdk_release.compatibility', 'current')
            ->assertJsonPath('nodes.0.sdk_release.relation', 'behind_known')
            ->assertJsonPath('nodes.0.sdk_release.latest_known_source', 'directory_release_manifest')
            ->assertJsonPath('nodes.0.health_reasons.1.dimension', 'backend')
            ->assertJsonPath('nodes.0.health_reasons.1.state', 'degraded')
            ->assertJsonPath('nodes.0.health_reasons.1.reason', 'backend_cold')
            ->assertJsonPath('nodes.0.health_reasons.3.dimension', 'policy')
            ->assertJsonPath('nodes.0.health_reasons.3.state', 'missing');
    }

    public function test_discovery_diversity_is_aggregate_only_and_does_not_expose_operator_keys(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $operatorKey = base64_encode(str_repeat('a', 32));
        $this->createNode([
            'operator_pubkey' => $operatorKey,
            'operator_verified' => true,
            'region' => 'eu-test',
        ], $cap);
        $this->createNode([
            'operator_pubkey' => $operatorKey,
            'operator_verified' => true,
            'region' => 'eu-test',
        ], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}&view=public");

        $response->assertOk()
            ->assertJsonPath('diversity_evidence.nodes', 2)
            ->assertJsonPath('diversity_evidence.nodes_with_verified_operator', 2)
            ->assertJsonPath('diversity_evidence.distinct_verified_operators', 1)
            ->assertJsonPath('diversity_evidence.distinct_regions', 1)
            ->assertJsonPath('diversity_evidence.failure_domain_count', null)
            ->assertJsonPath('diversity_evidence.identity_material_exposed', false);
        $this->assertStringNotContainsString($operatorKey, $response->getContent());
        $this->assertStringNotContainsString('operator_pubkey', $response->getContent());
    }

    /** @test CX-01/CX-02/#557: discover surfaces only the canonical CX key */
    public function test_discover_surfaces_cx_public_key(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $key = [
            'algorithm' => 'X25519',
            'encoding' => 'base64url',
            'key' => 'k7Hx2Yb9QnJ3vF1mZ0pLd8RtWcXeSgUaNoBhKiMjPw',
            'key_id' => 'a1b2c3d4e5f60718',
            'not_after' => '2026-08-27T00:00:00Z',
            'hybrid_pq' => null,
        ];
        $this->createNode(['cx_public_key' => $key], [[
            'intent' => $intent, 'models' => ['m'], 'max_tokens' => 100,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200)
            ->assertJsonPath('nodes.0.cx_public_key.algorithm', 'X25519')
            ->assertJsonPath('nodes.0.cx_public_key.key_id', 'a1b2c3d4e5f60718');
        $this->assertArrayNotHasKey('public_key', $response->json('nodes.0'));
    }

    /** @test #360/#557: a node without CX support surfaces only canonical null */
    public function test_discover_public_key_null_for_non_cx_node(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [[
            'intent' => $intent, 'models' => ['m'], 'max_tokens' => 100,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertNull($response->json('nodes.0.cx_public_key'));
        $this->assertArrayNotHasKey('public_key', $response->json('nodes.0'));
    }

    public function test_relay_available_false_when_no_relay_capable_nodes(): void
    {
        // Behavior: relay_available=false when all discovered nodes have relay_capable=false.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['relay_capable' => false], [[
            'intent' => $intent, 'models' => ['m'], 'max_tokens' => 100,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertFalse($response->json('relay_available'));
    }

    public function test_relay_available_true_when_relay_capable_node_present(): void
    {
        // Behavior: relay_available=true when ≥1 discovered node has relay_capable=true.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['relay_capable' => true], [[
            'intent' => $intent, 'models' => ['m'], 'max_tokens' => 100,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertTrue($response->json('relay_available'));
    }

    // ── #494 — health_models reconciliation ──────────────────────────────────

    public function test_health_models_null_uses_static_capabilities_backward_compat(): void
    {
        // Behavior: when health_models is null (SDK has not reported), discover
        // returns the statically-registered model — no filtering applied (#494 compat).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['health_models' => null], [[
            'intent' => $intent, 'models' => ['qwen2.5:0.5b'], 'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson("/api/v1/discover?intent={$intent}&model=qwen2.5:0.5b")
            ->assertStatus(200);
        $this->assertCount(1, $resp->json('nodes'), 'node must appear — health_models null = backward compat');
        $this->assertContains('qwen2.5:0.5b', $resp->json('nodes.0.models'));
    }

    public function test_health_models_empty_excludes_node_from_model_filtered_discover(): void
    {
        // Behavior: a node with health_models=[] (runtime has no models loaded right now)
        // must NOT appear in a model-filtered discover result — DIR-TRUST-01 fix (#494).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['health_models' => []], [[
            'intent' => $intent, 'models' => ['qwen2.5:0.5b'], 'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson("/api/v1/discover?intent={$intent}&model=qwen2.5:0.5b")
            ->assertStatus(200);
        $this->assertCount(0, $resp->json('nodes'), 'node must be excluded — health_models=[] means runtime is empty');
    }

    public function test_health_models_empty_excludes_node_from_unfiltered_discover(): void
    {
        // Behavior: a node with health_models=[] must be excluded from discover even without
        // ?model= filter — fixes DIR-TRUST-01 (#494). The REACH probe calls discover without
        // ?model= and picks the first node; if that node has health_models=[] it fails.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['health_models' => []], [[
            'intent' => $intent, 'models' => ['qwen2.5:0.5b'], 'max_tokens' => 4096,
        ]]);

        $resp = $this->getJson("/api/v1/discover?intent={$intent}")
            ->assertStatus(200);
        $this->assertCount(0, $resp->json('nodes'), 'node must be excluded — health_models=[] means no models serving');
    }

    public function test_health_models_partial_list_only_live_models_in_response(): void
    {
        // Behavior: when health_models=['qwen2.5:0.5b'] (only one of two registered models
        // is running), discover returns the intersection as the models array and only matches
        // a ?model= query for the live model (#494).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode(['health_models' => ['qwen2.5:0.5b']], [[
            'intent' => $intent, 'models' => ['qwen2.5:0.5b', 'llama3:latest'], 'max_tokens' => 4096,
        ]]);

        // Querying for the live model → appears; models list shows only live model.
        $resp = $this->getJson("/api/v1/discover?intent={$intent}&model=qwen2.5:0.5b")
            ->assertStatus(200);
        $this->assertCount(1, $resp->json('nodes'));
        $this->assertSame(['qwen2.5:0.5b'], $resp->json('nodes.0.models'));

        // Querying for the unloaded model → excluded.
        $resp2 = $this->getJson("/api/v1/discover?intent={$intent}&model=llama3:latest")
            ->assertStatus(200);
        $this->assertCount(0, $resp2->json('nodes'), 'unloaded model must be excluded even if registered');
    }
}
