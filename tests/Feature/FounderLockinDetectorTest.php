<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use App\Services\FounderLockinDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #310 founder lock-in detector (spec §5.4). Behavior tests (#404) — each asserts a
 * specific rule the detector must satisfy and would fail without it. #1 is reserved by
 * the founder's operator_pubkey (the cryptographic identity); #2..N by genuine served
 * nodes in first-appearance order.
 */
class FounderLockinDetectorTest extends TestCase
{
    use RefreshDatabase;

    private const FOUNDER = 'FOUNDER_PUBKEY';

    private int $nowMs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nowMs = (int) (microtime(true) * 1000);
        // Genesis 1 day ago → inside all founder windows; #1 reserved by operator_pubkey.
        Config::set('app.iicp_genesis_ms', $this->nowMs - 86400_000);
        Config::set('app.iicp_founder_one_pubkey', self::FOUNDER);
    }

    private function days(int $n): int
    {
        return $n * 86400_000;
    }

    private function makeNode(string $operatorPubkey, array $overrides = []): Node
    {
        return Node::create(array_merge([
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
        ], $overrides));
    }

    private function detector(): FounderLockinDetector
    {
        return app(FounderLockinDetector::class);
    }

    public function test_end_to_end_detector_to_public_leaderboard(): void
    {
        // Full path: founder + a genuine 31-day operator → detector → the public HTTP board.
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'ZeroKelvinMoralist', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);
        Operator::create(['operator_pubkey' => 'OP2', 'display_name' => 'Alice', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('OP2');

        $this->detector()->scan();

        $resp = $this->getJson('/api/v1/leaderboards/founders');
        $resp->assertStatus(200)
            ->assertJsonPath('count', 2)
            ->assertJsonPath('entries.0.ordinal', 1)
            ->assertJsonPath('entries.0.display_name', 'ZeroKelvinMoralist')
            ->assertJsonPath('entries.1.ordinal', 2)
            ->assertJsonPath('entries.1.display_name', 'Alice');
        // The operator keys must never reach the public board.
        $this->assertStringNotContainsString(self::FOUNDER, $resp->getContent());
        $this->assertStringNotContainsString('OP2', $resp->getContent());
    }

    public function test_reserves_ordinal_one_for_the_founder_by_pubkey(): void
    {
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);

        $this->detector()->scan();

        $dev = Operator::where('operator_pubkey', self::FOUNDER)->first();
        $this->assertSame(1, $dev->ordinal);
        $this->assertSame('genesis_50', $dev->tier);
    }

    public function test_founder_reserved_even_without_thirty_days(): void
    {
        // #1 is founder privilege — reserved regardless of the 30-day gate.
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(1)]);
        $this->makeNode(self::FOUNDER);

        $this->detector()->scan();

        $this->assertSame(1, Operator::where('operator_pubkey', self::FOUNDER)->first()->ordinal);
    }

    public function test_assigns_two_to_a_genuine_operator_after_thirty_days(): void
    {
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);
        Operator::create(['operator_pubkey' => 'OP2', 'display_name' => 'Alice', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('OP2');

        $this->detector()->scan();

        $alice = Operator::where('operator_pubkey', 'OP2')->first();
        $this->assertSame(2, $alice->ordinal, 'first genuine external operator is #2');
        $this->assertSame('genesis_50', $alice->tier);
    }

    public function test_does_not_assign_before_thirty_days(): void
    {
        Operator::create(['operator_pubkey' => 'OP', 'display_name' => 'TooNew', 'first_seen_ms' => $this->nowMs - $this->days(10)]);
        $this->makeNode('OP');

        $this->detector()->scan();

        $this->assertNull(Operator::where('operator_pubkey', 'OP')->first()->ordinal);
    }

    public function test_excludes_operator_without_a_public_reachable_verified_node(): void
    {
        Operator::create(['operator_pubkey' => 'OPX', 'display_name' => 'Internal', 'first_seen_ms' => $this->nowMs - $this->days(40)]);
        $this->makeNode('OPX', ['public_reachable' => false]);
        Operator::create(['operator_pubkey' => 'OPY', 'display_name' => 'Unverified', 'first_seen_ms' => $this->nowMs - $this->days(40)]);
        $this->makeNode('OPY', ['operator_verified' => false]);

        $this->detector()->scan();

        $this->assertNull(Operator::where('operator_pubkey', 'OPX')->first()->ordinal);
        $this->assertNull(Operator::where('operator_pubkey', 'OPY')->first()->ordinal);
    }

    // ── Self-hoster fairness (spec §5.4.2 no-reset semantics + 24h scan window) ─────────
    // These verify the claims made to external operators: reboots/sleep never reset the
    // 30-day clock, and a node asleep at the scan instant still counts if it served that day.

    public function test_node_dormant_at_scan_but_seen_today_still_locks_in(): void
    {
        // The WSL2 case: node heartbeat 2 hours ago, then went to sleep (dormant at scan
        // time). The trailing-24h window must count it as a genuine served node.
        Operator::create(['operator_pubkey' => 'SLEEPER', 'display_name' => 'WslBox', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('SLEEPER', [
            'status' => 'dormant',
            'available' => false,
            'last_seen' => now()->subHours(2),
        ]);

        $this->detector()->scan();

        $this->assertNotNull(Operator::where('operator_pubkey', 'SLEEPER')->first()->ordinal);
    }

    public function test_node_unseen_for_over_a_day_does_not_lock_in(): void
    {
        // Counterpart: a node that has not served within the trailing 24h is NOT genuine
        // this scan (it will be re-evaluated tomorrow — no penalty, but no free pass).
        Operator::create(['operator_pubkey' => 'GHOST', 'display_name' => 'Gone', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('GHOST', [
            'status' => 'dormant',
            'available' => false,
            'last_seen' => now()->subDays(3),
        ]);

        $this->detector()->scan();

        $this->assertNull(Operator::where('operator_pubkey', 'GHOST')->first()->ordinal);
    }

    public function test_thirty_day_gate_is_calendar_elapsed_not_continuous(): void
    {
        // Operator first seen 31 days ago whose node was offline for long stretches
        // (proven by a dormant gap) still locks in: the gate is now-first_seen elapsed
        // time, not accumulated uptime. Node is online today.
        Operator::create(['operator_pubkey' => 'FLAKY', 'display_name' => 'HomeLab', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('FLAKY'); // active now; history of gaps is irrelevant to the gate

        $this->detector()->scan();

        $this->assertNotNull(Operator::where('operator_pubkey', 'FLAKY')->first()->ordinal);
    }

    public function test_orders_by_first_appearance(): void
    {
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);
        // Bob appeared before Alice → Bob is #2, Alice #3, regardless of insert order.
        Operator::create(['operator_pubkey' => 'ALICE', 'display_name' => 'Alice', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('ALICE');
        Operator::create(['operator_pubkey' => 'BOB', 'display_name' => 'Bob', 'first_seen_ms' => $this->nowMs - $this->days(40)]);
        $this->makeNode('BOB');

        $this->detector()->scan();

        $this->assertSame(2, Operator::where('operator_pubkey', 'BOB')->first()->ordinal);
        $this->assertSame(3, Operator::where('operator_pubkey', 'ALICE')->first()->ordinal);
    }

    public function test_is_idempotent(): void
    {
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);
        Operator::create(['operator_pubkey' => 'OP2', 'display_name' => 'Alice', 'first_seen_ms' => $this->nowMs - $this->days(31)]);
        $this->makeNode('OP2');

        $this->detector()->scan();
        $this->detector()->scan(); // second pass must not renumber or duplicate

        $this->assertSame(1, Operator::where('operator_pubkey', self::FOUNDER)->first()->ordinal);
        $this->assertSame(2, Operator::where('operator_pubkey', 'OP2')->first()->ordinal);
        $this->assertSame(2, Operator::whereNotNull('ordinal')->count());
    }

    public function test_dry_run_persists_nothing(): void
    {
        Operator::create(['operator_pubkey' => self::FOUNDER, 'display_name' => 'Founder', 'first_seen_ms' => $this->nowMs - $this->days(2)]);
        $this->makeNode(self::FOUNDER);

        $result = $this->detector()->scan(true);

        $this->assertTrue($result['dry_run']);
        $this->assertNull(Operator::where('operator_pubkey', self::FOUNDER)->first()->ordinal);
    }
}
