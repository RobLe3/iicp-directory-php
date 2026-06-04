<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-042 / db-D1prime: denormalize reputation_score + credit_balance onto nodes.
 *
 * Per reports/db-storage-design-v2-2026-05-25.md §"nodes — KEEP, denormalize",
 * the directory's "≤ 3× heartbeat window" storage budget treats reputation
 * and credit balance as canonical persistent facts that belong on the
 * `nodes` row itself, not as separate JOIN-required tables.
 *
 * This migration is ADDITIVE — adds two columns + backfills from the
 * existing `reputations` / `credits` tables. The old tables are NOT
 * dropped here; they stay as fallback-read during the D1prime → D2prime
 * → D5prime stability window (per the design doc's "dual-read" discipline).
 *
 * `last_known_ip` from the design doc is already covered by the existing
 * `observed_source_ip` column on nodes — no new column needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->float('reputation_score')->default(0.5)->after('attested')
                ->comment('Denormalized from reputations.score (W-042/D1prime). Canonical source per v2 storage design.');
            $table->decimal('credit_balance', 15, 4)->default(0.0000)->after('reputation_score')
                ->comment('Denormalized from credits.balance (W-042/D1prime). Canonical source per v2 storage design.');
        });

        // Backfill from existing canonical tables. Safe to re-run.
        // Cross-driver subquery form (SQLite tests + MySQL prod both work;
        // SQLite does not support UPDATE ... INNER JOIN).
        DB::statement('
            UPDATE nodes
            SET reputation_score = (
                SELECT score FROM reputations WHERE reputations.node_id = nodes.id
            )
            WHERE id IN (SELECT node_id FROM reputations)
        ');
        DB::statement('
            UPDATE nodes
            SET credit_balance = (
                SELECT balance FROM credits WHERE credits.node_id = nodes.id
            )
            WHERE id IN (SELECT node_id FROM credits)
        ');
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['reputation_score', 'credit_balance']);
        });
    }
};
