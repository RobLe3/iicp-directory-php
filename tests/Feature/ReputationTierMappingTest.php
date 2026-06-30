<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use App\Services\NodeScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #537 — regression guards for the tier-from-reputation mapping (spec S.12 §5.1.1).
 *
 * Pins the non-[POLICY] cases from the Promotion & Selection analysis: the tier is a
 * pure, monotonic function of the reputation score (single source of truth in
 * NodeScorer::reputationTier), so a higher score never yields a lower tier, a fresh
 * node never enters above the entry tier, and no node shows a tier whose floor exceeds
 * its score (REG-001 / PROMO-005 / PROMO-006 / PROMO-009).
 */
class ReputationTierMappingTest extends TestCase
{
    use RefreshDatabase;

    private function nodeWithScore(float $score, ?Carbon $createdAt = null, int $completedTasks = 100): Node
    {
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://tier-test.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ]);
        if ($createdAt !== null) {
            // created_at drives the platinum age gate; set it explicitly.
            Node::where('id', $node->id)->update(['created_at' => $createdAt]);
        }
        Reputation::create([
            'node_id' => $node->id,
            'score' => $score,
            'tasks_total' => $completedTasks,
            'tasks_failed' => 0,
            'completed_tasks_count' => $completedTasks,
            'avg_latency_ms' => 100.0,
        ]);

        return $node->fresh();
    }

    public function test_score_bands_map_to_expected_tiers(): void
    {
        $cases = [
            [0.00, 'bronze'],
            [0.39, 'bronze'],
            [0.40, 'silver'],   // boundary: 0.40 is silver, not bronze
            [0.52, 'silver'],
            [0.64, 'silver'],
            [0.65, 'gold'],     // boundary: 0.65 is gold, not silver
            [0.84, 'gold'],
        ];
        foreach ($cases as [$score, $tier]) {
            $node = $this->nodeWithScore($score);
            $this->assertSame($tier, NodeScorer::reputationTier($node), "score {$score} → {$tier}");
        }
    }

    public function test_platinum_requires_age_high_score_fresh_node_is_gold(): void
    {
        // ≥0.85 but young (age < 720h) → gold, not platinum (the #526 age gate).
        $fresh = $this->nodeWithScore(0.92, Carbon::now()->subHours(10));
        $this->assertSame('gold', NodeScorer::reputationTier($fresh));

        // ≥0.85 and aged ≥ 720h (30d) → platinum.
        $aged = $this->nodeWithScore(0.92, Carbon::now()->subHours(800), completedTasks: 1000);
        $this->assertSame('platinum', NodeScorer::reputationTier($aged));
    }

    public function test_gold_requires_minimum_observations(): void
    {
        // #554: a fast score jump is not enough evidence for a high public tier.
        $fastJump = $this->nodeWithScore(0.82, Carbon::now()->subHours(800), completedTasks: 20);
        $this->assertSame('silver', NodeScorer::reputationTier($fastJump));

        $observed = $this->nodeWithScore(0.82, Carbon::now()->subHours(800), completedTasks: 100);
        $this->assertSame('gold', NodeScorer::reputationTier($observed));
    }

    public function test_platinum_requires_age_score_and_deeper_observations(): void
    {
        // High score + age but only the Gold observation floor stays Gold.
        $notEnoughObservations = $this->nodeWithScore(0.92, Carbon::now()->subHours(800), completedTasks: 100);
        $this->assertSame('gold', NodeScorer::reputationTier($notEnoughObservations));

        $enoughObservations = $this->nodeWithScore(0.92, Carbon::now()->subHours(800), completedTasks: 1000);
        $this->assertSame('platinum', NodeScorer::reputationTier($enoughObservations));
    }

    public function test_compliance_signals_demote_downlevel_or_unkeyed_nodes_without_hiding_them(): void
    {
        $node = $this->nodeWithScore(0.82);
        $node->update([
            'sdk_version' => '0.7.62',
            'cx_public_key' => null,
            'auto_update_enabled' => true,
            'auto_update_interval_s' => 3600,
            'sdk_latest_seen' => '0.7.68',
            'sdk_update_last_checked_at' => now(),
            'sdk_update_error_class' => null,
        ]);

        $signals = NodeScorer::complianceSignals($node->fresh());

        $this->assertSame('downlevel', $signals['sdk_status']);
        $this->assertSame(NodeScorer::SDK_BASELINE_VERSION, $signals['sdk_baseline_version']);
        $this->assertTrue($signals['upgrade_required']);
        $this->assertFalse($signals['key_ready']);
        $this->assertSame('transitional', $signals['privacy_routing_status']);
        $this->assertSame(true, $signals['auto_update']['enabled']);
        $this->assertSame(3600, $signals['auto_update']['interval_s']);
    }

    public function test_current_keyed_node_is_not_upgrade_required(): void
    {
        $node = $this->nodeWithScore(0.82);
        $node->update([
            'sdk_version' => NodeScorer::SDK_BASELINE_VERSION,
            'cx_public_key' => [
                'algorithm' => 'X25519',
                'encoding' => 'base64url',
                'key' => str_repeat('a', 43),
                'key_id' => 'cx-test',
            ],
        ]);

        $signals = NodeScorer::complianceSignals($node->fresh());

        $this->assertSame('current', $signals['sdk_status']);
        $this->assertFalse($signals['upgrade_required']);
        $this->assertTrue($signals['key_ready']);
        $this->assertSame('key_ready', $signals['privacy_routing_status']);
    }

    public function test_tier_mapping_is_monotonic(): void
    {
        // REG-001 / PROMO-005: a higher score must never yield a lower tier.
        $rank = ['bronze' => 0, 'silver' => 1, 'gold' => 2, 'platinum' => 3];
        $prev = -1;
        foreach (range(0, 100, 2) as $pct) {
            $node = $this->nodeWithScore($pct / 100.0, Carbon::now()->subHours(800));
            $tier = NodeScorer::reputationTier($node);
            $this->assertArrayHasKey($tier, $rank, "unknown tier {$tier}");
            $this->assertGreaterThanOrEqual($prev, $rank[$tier], "tier dropped at score {$pct}% ({$tier})");
            $prev = $rank[$tier];
        }
    }

    public function test_cold_start_node_enters_at_silver_not_gold(): void
    {
        // PROMO-006: a brand-new node starts at INITIAL_REP = 0.5 → Silver (entry tier),
        // never Gold on first sight. Reaching Gold requires accrued reputation.
        $node = $this->nodeWithScore(0.5, Carbon::now());
        $this->assertSame('silver', NodeScorer::reputationTier($node));
    }

    public function test_missing_reputation_defaults_to_silver_not_above(): void
    {
        // A node with no reputation row falls back to 0.5 (silver) — the entry tier,
        // never a higher one (no tier whose floor exceeds the effective score, REG-001).
        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://no-rep.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
        ])->fresh();
        $this->assertSame('silver', NodeScorer::reputationTier($node));
    }
}
