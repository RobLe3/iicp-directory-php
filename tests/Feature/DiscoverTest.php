<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_reputation_tier_bronze_during_probation(): void
    {
        // Nodes with < 100 completed tasks are always bronze (probation).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        // Even a high score is bronze during probation.
        $node->reputation()->create(['score' => 0.75, 'completed_tasks_count' => 50]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('bronze', $response->json('nodes.0.reputation_tier'));
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
        // score ≥ 0.85 AND identity age ≥ 720h AND ≥ 100 tasks → platinum
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $node = $this->createNode([], $cap);
        $node->reputation()->create(['score' => 0.92, 'completed_tasks_count' => 100]);
        $node->created_at = now()->subHours(800);
        $node->save();

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('platinum', $response->json('nodes.0.reputation_tier'));
    }

    public function test_reputation_tier_silver_is_default_when_no_reputation_record(): void
    {
        // No reputation record → 0 completed tasks → bronze (probation).
        // The silver default was removed when probation was introduced.
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $cap = [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]];
        $this->createNode([], $cap);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertSame('bronze', $response->json('nodes.0.reputation_tier'));
    }

    public function test_discover_response_includes_public_cache_control_header(): void
    {
        // Discover is public and unauthenticated — CDN caching must be enabled
        // via Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=120
        // (#324 v1.9.22 tuning; pairs with Laravel Cache::remember TTL 120s).
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [['intent' => $intent, 'models' => ['m'], 'max_tokens' => 100]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $cacheControl = $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
        $this->assertStringContainsString('s-maxage=300', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=120', $cacheControl);
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

    /** @test CX-01/CX-02 (#360): discover surfaces a CX node's advertised public_key */
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
            ->assertJsonPath('nodes.0.public_key.algorithm', 'X25519')
            ->assertJsonPath('nodes.0.public_key.key_id', 'a1b2c3d4e5f60718');
    }

    /** @test #360: a node without CX support surfaces public_key as null in discover */
    public function test_discover_public_key_null_for_non_cx_node(): void
    {
        $intent = 'urn:iicp:intent:llm:chat:v1';
        $this->createNode([], [[
            'intent' => $intent, 'models' => ['m'], 'max_tokens' => 100,
        ]]);

        $response = $this->getJson("/api/v1/discover?intent={$intent}");

        $response->assertStatus(200);
        $this->assertNull($response->json('nodes.0.public_key'));
    }
}
