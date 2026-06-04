<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-044 stopgap: HEARTBEAT prune from node_events.
 *
 * Verifies the weekly cron deletes only HEARTBEAT rows older than --days
 * and leaves REGISTER/DEREGISTER/CREDIT_AWARD/REPLICA_REGISTERED untouched.
 */
class PruneHeartbeatEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $nodeId;

    private int $seq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nodeId = '550e8400-e29b-41d4-a716-446655440300';
        Node::create([
            'id' => $this->nodeId,
            'endpoint' => 'https://prune.test',
            'region' => 'eu',
            'node_token_hash' => 'h',
            'max_concurrent' => 1,
            'tokens_per_min' => 100,
            'available' => true,
            'credit_balance' => 0,
        ]);
    }

    private function insertEvent(string $type, int $tsMsOffset): void
    {
        DB::table('node_events')->insert([
            'event_id' => (string) Str::uuid(),
            'seq' => $this->seq++,
            'node_id' => $this->nodeId,
            'event_type' => $type,
            'ts_ms' => (int) ((microtime(true) - $tsMsOffset) * 1000),
            'payload' => json_encode(['test' => true]),
            'signature' => 'sig',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_prunes_heartbeat_older_than_default_7d(): void
    {
        $this->insertEvent('HEARTBEAT', 8 * 86400); // 8d old
        $this->insertEvent('HEARTBEAT', 3 * 86400); // 3d old (keep)
        $this->insertEvent('REGISTER', 10 * 86400); // 10d old, but not HEARTBEAT (keep)

        $this->artisan('iicp:prune-heartbeat-events')
            ->assertSuccessful();

        $heartbeats = DB::table('node_events')->where('event_type', 'HEARTBEAT')->count();
        $registers = DB::table('node_events')->where('event_type', 'REGISTER')->count();

        $this->assertSame(1, $heartbeats, 'only the 3d HEARTBEAT should remain');
        $this->assertSame(1, $registers, 'REGISTER must never be pruned regardless of age');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->insertEvent('HEARTBEAT', 30 * 86400);
        $this->insertEvent('HEARTBEAT', 30 * 86400);

        $this->artisan('iicp:prune-heartbeat-events', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(2, DB::table('node_events')->where('event_type', 'HEARTBEAT')->count());
    }

    public function test_custom_retention_days_honored(): void
    {
        $this->insertEvent('HEARTBEAT', 20 * 86400); // 20d old
        $this->insertEvent('HEARTBEAT', 10 * 86400); // 10d old
        $this->insertEvent('HEARTBEAT', 5 * 86400);  // 5d old

        $this->artisan('iicp:prune-heartbeat-events', ['--days' => 15])
            ->assertSuccessful();

        // Only the 20d HEARTBEAT should be pruned
        $this->assertSame(2, DB::table('node_events')->where('event_type', 'HEARTBEAT')->count());
    }

    public function test_does_not_touch_credit_award_or_replica_registered(): void
    {
        $this->insertEvent('CREDIT_AWARD', 365 * 86400); // 1 year old
        $this->insertEvent('REPLICA_REGISTERED', 365 * 86400);
        $this->insertEvent('DEREGISTER', 365 * 86400);
        $this->insertEvent('REPUTATION_DECAY', 365 * 86400);
        $this->insertEvent('HEARTBEAT', 365 * 86400); // pruneable

        $this->artisan('iicp:prune-heartbeat-events')->assertSuccessful();

        // All non-HEARTBEAT must survive
        $this->assertSame(0, DB::table('node_events')->where('event_type', 'HEARTBEAT')->count());
        $this->assertSame(4, DB::table('node_events')->where('event_type', '!=', 'HEARTBEAT')->count());
    }

    public function test_no_op_when_no_heartbeat_rows(): void
    {
        $this->insertEvent('REGISTER', 30 * 86400);

        $this->artisan('iicp:prune-heartbeat-events')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('node_events')->count());
    }
}
