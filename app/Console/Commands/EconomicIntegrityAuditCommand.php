<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EconomicIntegrityAuditCommand extends Command
{
    protected $signature = 'iicp:audit-economic-integrity
        {--event-limit=10000 : Maximum newest CREDIT_AWARD events to inspect}
        {--json : Emit machine-readable aggregate JSON}';

    protected $description = 'Read-only, content-free audit of credit attribution, ledger reconciliation, and operator concentration';

    public function handle(): int
    {
        foreach (['nodes', 'credit_transactions', 'node_events'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Required table is missing: {$table}");

                return self::FAILURE;
            }
        }

        $limit = max(1, min(100000, (int) $this->option('event-limit')));
        $payload = [
            'schema' => 'iicp.economic_integrity_audit.v1',
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'event_limit' => $limit,
                'read_only' => true,
                'content_free' => true,
                'exports_identifiers' => false,
            ],
            'ledger' => $this->ledgerSummary(),
            'credit_events' => $this->creditEventSummary($limit),
            'receipt_profile_readiness' => $this->receiptProfileReadiness(),
            'operator_concentration' => $this->operatorConcentration(),
            'advisory_counters' => $this->advisoryCounterSummary(),
            'decision' => 'evidence_only_no_enforcement',
            'limitations' => [
                'Rejected self-dealing attempts are not persisted as CREDIT_AWARD events.',
                'Legacy events may not contain querying-node attribution.',
                'Aggregate consistency is not proof of consumer co-signature validity.',
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('IICP economic-integrity audit (read-only; aggregate output only)');
        $this->line('  ledger rows: '.$payload['ledger']['transaction_count']);
        $this->line('  ledger mismatches: '.$payload['ledger']['node_balance_mismatch_count']);
        $this->line('  inspected credit events: '.$payload['credit_events']['inspected']);
        $this->line('  forbidden attributed events: '.$payload['credit_events']['forbidden_attribution_count']);
        $this->line('  debit reconciliation mismatches: '.$payload['credit_events']['spend_reconciliation_mismatch_count']);
        $this->line('  max verified-operator active-node share: '.$payload['operator_concentration']['max_active_node_share']);

        return self::SUCCESS;
    }

    /** @return array<string, int|string> */
    private function receiptProfileReadiness(): array
    {
        $profile = 'consumer_cosignature_v1';
        $active = DB::table('nodes')->where('status', 'active');
        $total = (int) (clone $active)->count();
        if (! Schema::hasColumn('nodes', 'supported_receipt_profiles')) {
            return [
                'profile' => $profile,
                'active_ready' => 0,
                'active_total' => $total,
            ];
        }

        $ready = (clone $active)
            ->pluck('supported_receipt_profiles')
            ->filter(function ($raw) use ($profile): bool {
                $profiles = is_array($raw) ? $raw : json_decode((string) $raw, true);

                return is_array($profiles) && in_array($profile, $profiles, true);
            })
            ->count();

        return [
            'profile' => $profile,
            'active_ready' => $ready,
            'active_total' => $total,
        ];
    }

    /** @return array<string, int|float> */
    private function ledgerSummary(): array
    {
        $totals = DB::table('credit_transactions')
            ->selectRaw('COUNT(*) AS transaction_count')
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS total_credited")
            ->selectRaw("SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END) AS total_debited")
            ->selectRaw("SUM(CASE WHEN type = 'credit' AND reason = 'free_allocation' THEN 1 ELSE 0 END) AS free_allocation_count")
            ->selectRaw("SUM(CASE WHEN type = 'credit' AND reason = 'free_allocation' THEN amount ELSE 0 END) AS free_allocation_amount")
            ->first();

        $ledgerByNode = DB::table('credit_transactions')
            ->select('node_id')
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) AS calculated_balance")
            ->groupBy('node_id');

        $mismatches = DB::table('nodes')
            ->leftJoinSub($ledgerByNode, 'ledger', fn ($join) => $join->on('nodes.id', '=', 'ledger.node_id'))
            ->whereRaw('ABS(COALESCE(nodes.credit_balance, 0) - COALESCE(ledger.calculated_balance, 0)) >= 0.0001')
            ->count();

        return [
            'transaction_count' => (int) ($totals->transaction_count ?? 0),
            'total_credited' => round((float) ($totals->total_credited ?? 0), 4),
            'total_debited' => round((float) ($totals->total_debited ?? 0), 4),
            'net_ledger' => round((float) ($totals->total_credited ?? 0) - (float) ($totals->total_debited ?? 0), 4),
            'node_balance_mismatch_count' => (int) $mismatches,
            'free_allocation_count' => (int) ($totals->free_allocation_count ?? 0),
            'free_allocation_amount' => round((float) ($totals->free_allocation_amount ?? 0), 4),
        ];
    }

    /** @return array<string, mixed> */
    private function creditEventSummary(int $limit): array
    {
        $total = (int) DB::table('node_events')->where('event_type', 'CREDIT_AWARD')->count();
        $events = DB::table('node_events')
            ->where('event_type', 'CREDIT_AWARD')
            ->orderByDesc('seq')
            ->limit($limit)
            ->pluck('payload');

        $attribution = [];
        $receiptClaims = [];
        $forbidden = 0;
        $withSpend = 0;
        $spendMismatch = 0;
        foreach ($events as $raw) {
            $event = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (! is_array($event)) {
                $attribution['invalid_payload'] = ($attribution['invalid_payload'] ?? 0) + 1;

                continue;
            }

            $class = is_string($event['attribution'] ?? null) ? $event['attribution'] : 'legacy_unclassified';
            $attribution[$class] = ($attribution[$class] ?? 0) + 1;
            $receiptProfile = is_string($event['receipt_profile'] ?? null)
                ? $event['receipt_profile']
                : 'legacy_missing';
            $receiptClaims[$receiptProfile] = ($receiptClaims[$receiptProfile] ?? 0) + 1;
            if (in_array($class, ['self_node', 'self_operator', 'unknown_querying_node'], true)) {
                $forbidden++;
            }

            $spent = round((float) ($event['spent'] ?? 0), 4);
            $taskId = $event['task_id'] ?? null;
            if ($spent <= 0.0 || ! is_string($taskId) || $taskId === '') {
                continue;
            }

            $withSpend++;
            $debited = round((float) DB::table('credit_transactions')
                ->where('type', 'debit')
                ->where('task_id', $taskId)
                ->sum('amount'), 4);
            if (abs($spent - $debited) >= 0.0001) {
                $spendMismatch++;
            }
        }
        ksort($attribution);
        ksort($receiptClaims);

        return [
            'total' => $total,
            'inspected' => $events->count(),
            'truncated' => $total > $events->count(),
            'attribution_counts' => $attribution,
            // Presence evidence only. This audit does not validate a consumer
            // signature and must not treat this count as co-signature proof.
            'receipt_profile_claim_counts' => $receiptClaims,
            'forbidden_attribution_count' => $forbidden,
            'events_with_recorded_spend' => $withSpend,
            'spend_reconciliation_mismatch_count' => $spendMismatch,
        ];
    }

    /** @return array<string, int|float> */
    private function operatorConcentration(): array
    {
        $active = DB::table('nodes')->where('status', 'active');
        $activeCount = (int) (clone $active)->count();
        $unverified = (int) (clone $active)->whereNull('operator_pubkey')->count();
        $groups = (clone $active)
            ->whereNotNull('operator_pubkey')
            ->selectRaw('COUNT(*) AS node_count')
            ->groupBy('operator_pubkey')
            ->pluck('node_count')
            ->map(fn ($count) => (int) $count);
        $max = $groups->max() ?? 0;

        return [
            'active_nodes' => $activeCount,
            'verified_operator_count' => $groups->count(),
            'unverified_active_nodes' => $unverified,
            'multi_node_verified_operators' => $groups->filter(fn (int $count) => $count > 1)->count(),
            'max_nodes_per_verified_operator' => (int) $max,
            'max_active_node_share' => $activeCount === 0 ? 0.0 : round($max / $activeCount, 4),
        ];
    }

    /** @return array<string, int> */
    private function advisoryCounterSummary(): array
    {
        if (! Schema::hasTable('reputations')) {
            return [
                'nodes_completed_exceeds_lifetime_jobs' => 0,
                'max_completed_tasks_count' => 0,
                'max_lifetime_jobs' => (int) (DB::table('nodes')->max('lifetime_jobs') ?? 0),
            ];
        }

        return [
            'nodes_completed_exceeds_lifetime_jobs' => (int) DB::table('nodes')
                ->join('reputations', 'nodes.id', '=', 'reputations.node_id')
                ->whereColumn('reputations.completed_tasks_count', '>', 'nodes.lifetime_jobs')
                ->count(),
            'max_completed_tasks_count' => (int) (DB::table('reputations')->max('completed_tasks_count') ?? 0),
            'max_lifetime_jobs' => (int) (DB::table('nodes')->max('lifetime_jobs') ?? 0),
        ];
    }
}
