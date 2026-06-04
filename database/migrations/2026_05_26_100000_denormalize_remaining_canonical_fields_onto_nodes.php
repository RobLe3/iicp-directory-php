<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-042 / D1prime-followup: denormalize the REMAINING canonical fields
 * from `reputations` and `credits` onto `nodes`.
 *
 * Context: 2026_05_25_300000 denormalized reputation_score + credit_balance.
 * That left 4 fields still read from the old tables, which blocks Phase 2
 * (dropping reputations/credits):
 *
 * From `reputations`: tasks_total, tasks_failed, avg_latency_ms
 *   - Read by StatsController (network aggregate) + ReputationService
 *     (decay computation) + AuditReportController (declaration audit)
 *   - These are technically "operational data" per ADR-033, but they're
 *     small (12 bytes per node) and frequently read for live stats —
 *     denormalizing keeps the directory single-source-of-truth without
 *     the latency penalty of pulling them from external telemetry.
 *
 * From `credits`: free_credit_last_allocation_at
 *   - Read by CreditService::maybeAllocateFreeCredits for the 6h interval
 *     gate (issue #306). Lifecycle-load-bearing per ADR-033 §"Canonical
 *     persistent facts" (small per-node state).
 *
 * After this migration + D2-READ code switch ships, Phase 2 SQL drop
 * (DROP TABLE reputations; DROP TABLE credits;) becomes safe.
 *
 * Migration is ADDITIVE — adds 4 nullable columns with backfill from
 * existing tables. Old tables remain. Idempotent.
 *
 * Tracking: #316 (Phase 2 cleanup), ADR-033 (storage design),
 * W-042 (DB growth investigation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('tasks_total')->default(0)->after('credit_balance')
                ->comment('Denormalized from reputations.tasks_total (D2prime-followup). Canonical per ADR-033 + W-042 v2 storage design.');
            $table->unsignedInteger('tasks_failed')->default(0)->after('tasks_total')
                ->comment('Denormalized from reputations.tasks_failed.');
            $table->float('avg_latency_ms')->default(0.0)->after('tasks_failed')
                ->comment('Denormalized from reputations.avg_latency_ms.');
            $table->timestamp('free_credit_last_allocation_at')->nullable()->after('avg_latency_ms')
                ->comment('Denormalized from credits.free_credit_last_allocation_at — 6h free-tier gate (issue #306).');
        });

        // Backfill from existing tables. Safe to re-run on subsequent migrations.
        // Cross-driver subquery form (SQLite tests + MySQL prod both work).
        DB::statement('
            UPDATE nodes
            SET tasks_total = COALESCE((
                SELECT tasks_total FROM reputations WHERE reputations.node_id = nodes.id
            ), 0)
            WHERE id IN (SELECT node_id FROM reputations)
        ');
        DB::statement('
            UPDATE nodes
            SET tasks_failed = COALESCE((
                SELECT tasks_failed FROM reputations WHERE reputations.node_id = nodes.id
            ), 0)
            WHERE id IN (SELECT node_id FROM reputations)
        ');
        DB::statement('
            UPDATE nodes
            SET avg_latency_ms = COALESCE((
                SELECT avg_latency_ms FROM reputations WHERE reputations.node_id = nodes.id
            ), 0)
            WHERE id IN (SELECT node_id FROM reputations)
        ');
        DB::statement('
            UPDATE nodes
            SET free_credit_last_allocation_at = (
                SELECT free_credit_last_allocation_at FROM credits WHERE credits.node_id = nodes.id
            )
            WHERE id IN (SELECT node_id FROM credits WHERE free_credit_last_allocation_at IS NOT NULL)
        ');
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['tasks_total', 'tasks_failed', 'avg_latency_ms', 'free_credit_last_allocation_at']);
        });
    }
};
