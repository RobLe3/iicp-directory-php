<?php

namespace Tests\Feature;

use App\Models\Replica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A.3 / ADR-039 §5.5: replica lifecycle auto-decommission.
 *
 * Verifies the daily cron transitions:
 *   active → dormant      after 7d
 *   dormant → archived    after 30d
 *   archived → decommissioned after 1y
 */
class RotateReplicaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function makeReplica(array $overrides = []): Replica
    {
        static $idx = 0;
        $idx++;

        return Replica::create(array_merge([
            'replica_id' => 'rep-'.str_pad((string) $idx, 32, '0', STR_PAD_LEFT),
            'did' => "did:web:replica{$idx}.test",
            'endpoint' => "https://replica{$idx}.test",
            'trust_tier' => 'low',
            'replica_token_hash' => hash('sha256', "tok-{$idx}"),
            'expires_at' => now()->addDays(90),
            'status' => Replica::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ], $overrides));
    }

    public function test_recent_replica_stays_active(): void
    {
        $r = $this->makeReplica(['last_seen_at' => now()->subDays(3)]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_ACTIVE, $r->status);
    }

    public function test_replica_inactive_8_days_becomes_dormant(): void
    {
        $r = $this->makeReplica(['last_seen_at' => now()->subDays(8)]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_DORMANT, $r->status);
    }

    public function test_replica_inactive_31_days_becomes_archived(): void
    {
        $r = $this->makeReplica(['last_seen_at' => now()->subDays(31)]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_ARCHIVED, $r->status);
    }

    public function test_replica_inactive_400_days_becomes_decommissioned(): void
    {
        $r = $this->makeReplica([
            'last_seen_at' => now()->subDays(400),
            'status' => Replica::STATUS_ARCHIVED,
        ]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_DECOMMISSIONED, $r->status);
    }

    public function test_decommissioned_replicas_skipped(): void
    {
        $r = $this->makeReplica([
            'last_seen_at' => now()->subDays(500),
            'status' => Replica::STATUS_DECOMMISSIONED,
        ]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_DECOMMISSIONED, $r->status, 'decommissioned is terminal');
    }

    public function test_replica_never_seen_keeps_current_status(): void
    {
        $r = $this->makeReplica(['last_seen_at' => null]);
        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_ACTIVE, $r->status, 'null last_seen_at = keep current');
    }

    public function test_dry_run_does_not_modify(): void
    {
        $r = $this->makeReplica(['last_seen_at' => now()->subDays(50)]);
        $this->artisan('iicp:rotate-replica-lifecycle', ['--dry-run' => true])->assertSuccessful();
        $r->refresh();
        $this->assertSame(Replica::STATUS_ACTIVE, $r->status, 'dry-run must NOT change state');
    }

    public function test_multiple_transitions_in_one_run(): void
    {
        $active = $this->makeReplica(['last_seen_at' => now()->subDays(2)]);
        $dormant = $this->makeReplica(['last_seen_at' => now()->subDays(10)]);
        $archived = $this->makeReplica(['last_seen_at' => now()->subDays(60)]);
        $decom = $this->makeReplica(['last_seen_at' => now()->subDays(500)]);

        $this->artisan('iicp:rotate-replica-lifecycle')->assertSuccessful();

        $active->refresh();
        $dormant->refresh();
        $archived->refresh();
        $decom->refresh();
        $this->assertSame(Replica::STATUS_ACTIVE, $active->status);
        $this->assertSame(Replica::STATUS_DORMANT, $dormant->status);
        $this->assertSame(Replica::STATUS_ARCHIVED, $archived->status);
        $this->assertSame(Replica::STATUS_DECOMMISSIONED, $decom->status);
    }
}
