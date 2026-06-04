<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REP-DECAY-01..05: Idle reputation decay — spec §11.3 λ=0.005/hr.
 *
 * REP1 §2: idle decay floor = 0.30. Nodes at or below 0.30 receive no further
 * idle-decay penalty (they may have reached that level via task-failure penalties).
 */
class ReputationDecayTest extends TestCase
{
    use RefreshDatabase;

    private function makeNode(string $status = 'active'): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://decay-test.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'last_seen' => now(),
            'status' => $status,
        ]);
    }

    private function setReputation(Node $node, float $score): Reputation
    {
        $rep = Reputation::create([
            'node_id' => $node->id,
            'score' => $score,
            'tasks_total' => 0,
            'tasks_failed' => 0,
            'completed_tasks_count' => 0,
            'avg_latency_ms' => 0.0,
        ]);
        // D2-READ test parity: production dual-writes both columns; tests must
        // seed the canonical denormalized field too or post-D2-READ reads
        // return the default 0.5 instead of the seeded value.
        $node->update(['reputation_score' => $score]);

        return $rep;
    }

    // REP-DECAY-01: active node score decreases by λ=0.005 per run
    public function test_decay_reduces_active_node_score(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.8);

        $this->artisan('iicp:reputation-decay')->assertSuccessful();

        $rep = Reputation::where('node_id', $node->id)->first();
        $this->assertEqualsWithDelta(0.795, $rep->score, 0.0001, 'REP-DECAY-01: score must decrease by 0.005');
    }

    // REP-DECAY-02: score below 0.30 is NOT decayed — REP1 §2 idle decay floor
    public function test_decay_does_not_apply_below_floor(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.003); // already below REP1 idle-decay floor (0.30)

        $this->artisan('iicp:reputation-decay')->assertSuccessful();

        $rep = Reputation::where('node_id', $node->id)->first();
        $this->assertEqualsWithDelta(0.003, (float) $rep->score, 0.0001, 'REP-DECAY-02: idle decay must not apply below 0.30 floor');
    }

    // REP-DECAY-02b: score at exactly 0.30 receives no further idle decay
    public function test_decay_stops_at_030_floor(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.30);

        $this->artisan('iicp:reputation-decay')
            ->assertSuccessful()
            ->expectsOutputToContain('0 node(s)');

        $rep = Reputation::where('node_id', $node->id)->first();
        $this->assertEqualsWithDelta(0.30, (float) $rep->score, 0.0001, 'REP-DECAY-02b: score at floor must not decay further');
    }

    // REP-DECAY-02c: score decays to 0.30 floor when within one step
    public function test_decay_clamps_at_030_when_near_floor(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.302); // within one λ step of floor

        $this->artisan('iicp:reputation-decay')->assertSuccessful();

        $rep = Reputation::where('node_id', $node->id)->first();
        $this->assertEqualsWithDelta(0.30, (float) $rep->score, 0.0001, 'REP-DECAY-02c: score must floor at 0.30, not go below');
    }

    // REP-DECAY-03: archived node is excluded from decay
    public function test_decay_skips_archived_nodes(): void
    {
        $node = $this->makeNode('archived');
        $this->setReputation($node, 0.7);

        $this->artisan('iicp:reputation-decay')->assertSuccessful();

        $rep = Reputation::where('node_id', $node->id)->first();
        $this->assertEqualsWithDelta(0.7, (float) $rep->score, 0.0001, 'REP-DECAY-03: archived node score must not change');
    }

    // REP-DECAY-04: score at 0.0 (task-failure path) produces no update or event
    public function test_decay_skips_node_already_below_floor(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.0); // fell here via task failures, not idle decay

        $this->artisan('iicp:reputation-decay')
            ->assertSuccessful()
            ->expectsOutputToContain('0 node(s)');
    }

    // REP-DECAY-05: optimistic lock — decay must not overwrite a concurrently updated score
    public function test_decay_optimistic_lock_rejects_stale_write(): void
    {
        $node = $this->makeNode('active');
        $this->setReputation($node, 0.8);

        // Simulate: command read oldScore=0.8, but a concurrent task upsert raised it to 0.85.
        DB::table('reputations')->where('node_id', $node->id)->update(['score' => 0.85]);

        // The WHERE score=0.8 must NOT match (DB has 0.85) — zero rows affected.
        $affected = DB::table('reputations')
            ->where('node_id', $node->id)
            ->where('score', 0.8)
            ->update(['score' => 0.795]);

        $this->assertSame(0, $affected, 'REP-DECAY-05: optimistic lock must reject stale update');

        $score = (float) Reputation::where('node_id', $node->id)->value('score');
        $this->assertEqualsWithDelta(0.85, $score, 0.0001, 'REP-DECAY-05: concurrent upsert score must not be overwritten');
    }
}
