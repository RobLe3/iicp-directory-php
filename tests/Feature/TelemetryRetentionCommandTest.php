<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Jobs\AggregateProbeMetricsJob;
use App\Models\Node;
use App\Models\TelemetryProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelemetryRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_db_maintenance_status_is_metadata_only_json(): void
    {
        TelemetryProbe::create($this->probeRow(['probed_at' => now()->subDays(20)]));
        $this->insertDispatchUsage(now()->subDays(40));

        Artisan::call('iicp:db-maintenance-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('iicp.db.maintenance_status.v1', $payload['schema']);
        $this->assertTrue($payload['safety']['dry_run_only']);
        $this->assertFalse($payload['safety']['exports_row_payloads']);
        $this->assertFalse($payload['safety']['drops_tables']);
        $this->assertArrayHasKey('tables', $payload);
        $this->assertNotEmpty($payload['tables']);
        $dispatch = collect($payload['tables'])->firstWhere('table', 'dispatch_usage_daily');
        $this->assertNotNull($dispatch);
        $this->assertSame(1, $dispatch['rows']);
        $this->assertSame(1, $dispatch['eligible_prune_rows']);
    }

    public function test_prune_telemetry_dry_run_deletes_nothing(): void
    {
        TelemetryProbe::create($this->probeRow(['probed_at' => now()->subDays(20)]));
        $this->insertAggregate(now()->subDays(40));
        $this->insertProxyTelemetry(now()->subDays(40));
        $this->insertDispatchUsage(now()->subDays(40));

        $this->artisan('iicp:prune-telemetry', [
            '--probe-days' => 14,
            '--aggregate-days' => 30,
            '--proxy-days' => 30,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('iicp_telemetry_probes')->count());
        $this->assertSame(1, DB::table('iicp_telemetry_aggregates')->count());
        $this->assertSame(1, DB::table('proxy_telemetry')->count());
        $this->assertSame(1, DB::table('dispatch_usage_daily')->count());
    }

    public function test_prune_telemetry_removes_only_old_operational_rows(): void
    {
        TelemetryProbe::create($this->probeRow(['probed_at' => now()->subDays(20)]));
        TelemetryProbe::create($this->probeRow(['run_id' => 'new-run', 'probed_at' => now()->subDays(2)]));
        $this->insertAggregate(now()->subDays(40), 'old_metric');
        $this->insertAggregate(now()->subDays(2), 'new_metric');
        $this->insertProxyTelemetry(now()->subDays(40));
        $this->insertProxyTelemetry(now()->subDays(2), 2);
        $this->insertDispatchUsage(now()->subDays(40));
        $this->insertDispatchUsage(now()->subDays(2), 'ticketed_dispatch');

        DB::table('credits')->insert([
            'node_id' => $this->node()->id,
            'balance' => 12.5,
            'free_credit_last_allocation_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('iicp:prune-telemetry', [
            '--probe-days' => 14,
            '--aggregate-days' => 30,
            '--proxy-days' => 30,
            '--dispatch-days' => 30,
            '--batch' => 100,
            '--max-batches' => 2,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('iicp_telemetry_probes')->count());
        $this->assertSame('new-run', DB::table('iicp_telemetry_probes')->value('run_id'));
        $this->assertSame(1, DB::table('iicp_telemetry_aggregates')->count());
        $this->assertSame('new_metric', DB::table('iicp_telemetry_aggregates')->value('metric'));
        $this->assertSame(1, DB::table('proxy_telemetry')->count());
        $this->assertSame(1, DB::table('dispatch_usage_daily')->count());
        $this->assertSame('ticketed_dispatch', DB::table('dispatch_usage_daily')->value('mode'));
        $this->assertSame(1, DB::table('credits')->count(), 'credit/accounting rows must not be touched by telemetry pruning');
    }

    public function test_prune_telemetry_respects_batch_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            TelemetryProbe::create($this->probeRow([
                'run_id' => 'old-run-'.$i,
                'probe_id' => 'probe-'.$i,
                'probed_at' => now()->subDays(20),
            ]));
        }

        $this->artisan('iicp:prune-telemetry', [
            '--probe-days' => 14,
            '--aggregate-days' => 30,
            '--proxy-days' => 30,
            '--batch' => 1,
            '--max-batches' => 2,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('iicp_telemetry_probes')->count());
    }

    public function test_aggregate_job_upserts_same_five_minute_bucket(): void
    {
        $this->travelTo(now()->setTime(12, 2, 15));
        foreach ([20, 30, 40] as $latency) {
            TelemetryProbe::create($this->probeRow([
                'test_id' => 'DIR-DISC-01',
                'passed' => true,
                'latency_ms' => $latency,
                'probed_at' => now()->subMinutes(5),
            ]));
        }

        (new AggregateProbeMetricsJob)->handle();
        (new AggregateProbeMetricsJob)->handle();

        $this->assertSame(
            1,
            DB::table('iicp_telemetry_aggregates')
                ->where('window', '24h')
                ->where('metric', 'discover_p50_ms')
                ->count(),
            'repeated aggregation in the same bucket should update, not append duplicate metric rows',
        );
    }

    public function test_dsr_export_still_works_after_probe_rows_are_pruned(): void
    {
        $node = $this->node();
        TelemetryProbe::create($this->probeRow([
            'node_id' => $node->id,
            'probed_at' => now()->subDays(20),
        ]));

        $this->artisan('iicp:prune-telemetry', ['--probe-days' => 14])
            ->assertSuccessful();

        Artisan::call('iicp:dsr', ['action' => 'export', '--node-id' => $node->id]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('iicp.dsr.export.v1', $payload['schema']);
        $this->assertSame([], $payload['records']['telemetry_probes']);
    }

    /** @param array<string,mixed> $overrides */
    private function probeRow(array $overrides = []): array
    {
        return array_merge([
            'probe_token_id' => null,
            'node_id' => null,
            'run_id' => 'retention-run',
            'probe_id' => 'retention-probe',
            'probe_type' => 'conformance',
            'test_id' => 'DIR-RETENTION-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 25,
            'detail' => 'retention test',
            'metadata' => ['source' => 'test'],
            'probed_at' => now(),
        ], $overrides);
    }

    private function insertAggregate(mixed $computedAt, string $metric = 'old_metric'): void
    {
        DB::table('iicp_telemetry_aggregates')->insert([
            'window' => '24h',
            'metric' => $metric,
            'value' => 1.0,
            'sample_count' => 1,
            'computed_at' => $computedAt,
        ]);
    }

    private function insertProxyTelemetry(mixed $createdAt, int $suffix = 1): void
    {
        DB::table('proxy_telemetry')->insert([
            'node_id' => (string) Str::uuid(),
            'proxy_node_id' => (string) Str::uuid(),
            'time_bucket' => now()->timestamp + $suffix,
            'latency_ms_observed' => 20,
            'tokens_observed' => 10,
            'status' => 'success',
            'qos_met' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertDispatchUsage(mixed $usageDate, string $mode = 'legacy_dispatch'): void
    {
        DB::table('dispatch_usage_daily')->insert([
            'usage_date' => $usageDate->toDateString(),
            'mode' => $mode,
            'request_count' => 1,
            'created_at' => $usageDate,
            'updated_at' => $usageDate,
        ]);
    }

    private function node(): Node
    {
        static $node = null;
        if ($node instanceof Node && Schema::hasTable('nodes') && Node::whereKey($node->id)->exists()) {
            return $node;
        }

        $node = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://retention-node.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash('token', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'public_reachable' => true,
            'status' => 'active',
            'last_seen' => now(),
        ]);

        return $node;
    }
}
