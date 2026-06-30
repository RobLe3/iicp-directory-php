<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #310/#463 — public recognition leaderboard (spec iicp-recognition §6). The `founders` board
 * orders operators by their founder ordinal, serves the public display_name + recognition
 * state, and NEVER leaks operator_pubkey.
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_founders_board_orders_by_ordinal_and_hides_pubkey(): void
    {
        Operator::create(['operator_pubkey' => 'PUBKEY_C', 'display_name' => 'Third', 'ordinal' => 3, 'tier' => 'founders_1000', 'badge' => 'founder', 'first_seen_ms' => 3]);
        Operator::create(['operator_pubkey' => 'PUBKEY_A', 'display_name' => 'First', 'ordinal' => 1, 'tier' => 'genesis_50', 'badge' => 'genesis', 'first_seen_ms' => 1]);
        Operator::create(['operator_pubkey' => 'PUBKEY_B', 'display_name' => 'Second', 'ordinal' => 2, 'tier' => 'founders_500', 'badge' => 'founder', 'first_seen_ms' => 2]);
        // A non-founder (no ordinal) must NOT appear on the founders board.
        Operator::create(['operator_pubkey' => 'PUBKEY_X', 'display_name' => 'Latecomer', 'first_seen_ms' => 9]);

        $resp = $this->getJson('/api/v1/leaderboards/founders');
        $resp->assertStatus(200)
            ->assertJsonPath('board_id', 'founders')
            ->assertJsonPath('count', 3)
            ->assertJsonPath('entries.0.place', 1)
            ->assertJsonPath('entries.0.display_name', 'First')
            ->assertJsonPath('entries.0.ordinal', 1)
            ->assertJsonPath('entries.1.display_name', 'Second')
            ->assertJsonPath('entries.2.display_name', 'Third');

        // The operator_pubkey must never be exposed on a public leaderboard.
        $raw = $resp->getContent();
        $this->assertStringNotContainsString('PUBKEY_A', $raw);
        $this->assertStringNotContainsString('operator_pubkey', $raw);
        $this->assertStringNotContainsString('Latecomer', $raw);
    }

    public function test_empty_founders_board_is_ok(): void
    {
        $this->getJson('/api/v1/leaderboards/founders')
            ->assertStatus(200)
            ->assertJsonPath('count', 0)
            ->assertJsonPath('entries', []);
    }

    public function test_unknown_board_is_404(): void
    {
        $this->getJson('/api/v1/leaderboards/living_mesh_lords')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'IICP-E050');
    }

    // ── Provisional founders (pending section) ──────────────────────────────────

    private function makeServedNode(string $operatorPubkey): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'status' => 'active',
            'available' => true,
            'last_seen' => now(),
            'public_reachable' => true,
            'operator_verified' => true,
            'operator_pubkey' => $operatorPubkey,
        ]);
    }

    public function test_pending_shows_provisional_operator_with_projection_and_days(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        Operator::create(['operator_pubkey' => 'PUBKEY_A', 'display_name' => 'First', 'ordinal' => 1, 'tier' => 'genesis_50', 'badge' => 'genesis', 'first_seen_ms' => 1]);
        // Provisional: serving genuinely, 10 days into the 30-day clock.
        Operator::create(['operator_pubkey' => 'PUBKEY_P', 'display_name' => 'Challenger', 'first_seen_ms' => $nowMs - 10 * 86_400_000]);
        $this->makeServedNode('PUBKEY_P');

        $resp = $this->getJson('/api/v1/leaderboards/founders');
        $resp->assertStatus(200)
            ->assertJsonPath('pending.0.display_name', 'Challenger')
            ->assertJsonPath('pending.0.projected_ordinal', 2)
            ->assertJsonPath('pending.0.days_remaining', 20)
            ->assertJsonPath('pending.0.provisional', true);

        $this->assertStringNotContainsString('PUBKEY_P', $resp->getContent());
    }

    public function test_pending_excludes_operator_without_genuine_served_node(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // Registered an identity but runs no genuine node — must NOT appear (anti-squat).
        Operator::create(['operator_pubkey' => 'PUBKEY_GHOST', 'display_name' => 'NameSquatter', 'first_seen_ms' => $nowMs - 5 * 86_400_000]);

        $this->getJson('/api/v1/leaderboards/founders')
            ->assertStatus(200)
            ->assertJsonPath('pending', []);
    }

    public function test_pending_orders_by_first_appearance_and_projects_sequentially(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        Operator::create(['operator_pubkey' => 'PUBKEY_A', 'display_name' => 'First', 'ordinal' => 1, 'tier' => 'genesis_50', 'badge' => 'genesis', 'first_seen_ms' => 1]);
        // Bob appeared before Alice (insert order reversed to prove ordering by first_seen).
        Operator::create(['operator_pubkey' => 'PUBKEY_ALICE', 'display_name' => 'Alice', 'first_seen_ms' => $nowMs - 3 * 86_400_000]);
        $this->makeServedNode('PUBKEY_ALICE');
        Operator::create(['operator_pubkey' => 'PUBKEY_BOB', 'display_name' => 'Bob', 'first_seen_ms' => $nowMs - 8 * 86_400_000]);
        $this->makeServedNode('PUBKEY_BOB');

        $resp = $this->getJson('/api/v1/leaderboards/founders');
        $resp->assertJsonPath('pending.0.display_name', 'Bob')
            ->assertJsonPath('pending.0.projected_ordinal', 2)
            ->assertJsonPath('pending.1.display_name', 'Alice')
            ->assertJsonPath('pending.1.projected_ordinal', 3);
    }

    public function test_pending_eligible_operator_shows_zero_days_remaining(): void
    {
        $nowMs = (int) (microtime(true) * 1000);
        // 35 days served but not yet scanned/locked — shows 0 days (locks at next scan).
        Operator::create(['operator_pubkey' => 'PUBKEY_E', 'display_name' => 'Ready', 'first_seen_ms' => $nowMs - 35 * 86_400_000]);
        $this->makeServedNode('PUBKEY_E');

        $this->getJson('/api/v1/leaderboards/founders')
            ->assertJsonPath('pending.0.days_remaining', 0);
    }
}
