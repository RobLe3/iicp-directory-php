<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.2 / ADR-036: reputation rolling window.
 *
 * Adds 4 columns to nodes for the rolling reputation window:
 *   tasks_total_recent       — count in current rolling window
 *   tasks_failed_recent      — count of failures in current rolling window
 *   avg_latency_ms_recent    — EMA-smoothed latency over the rolling window
 *   recent_window_start      — when the current window opened (TIMESTAMP)
 *
 * Window length = credit_economy.TTL_days (currently 90; ADR-035 alignment).
 * Backfill: existing nodes get tasks_total_recent = tasks_total, etc.,
 * with recent_window_start = NOW() so they start a fresh window at deploy time.
 *
 * Tracking: ADR-036, verified-retention-plan-2026-05-26.md Step 4.A.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('tasks_total_recent')->default(0)
                ->after('avg_latency_ms')
                ->comment('Phase A.2 / ADR-036: rolling-window task count (window = credit TTL)');
            $table->unsignedInteger('tasks_failed_recent')->default(0)
                ->after('tasks_total_recent')
                ->comment('Phase A.2 / ADR-036: rolling-window failure count');
            $table->float('avg_latency_ms_recent')->default(0.0)
                ->after('tasks_failed_recent')
                ->comment('Phase A.2 / ADR-036: rolling-window EMA-smoothed latency');
            $table->timestamp('recent_window_start')->nullable()
                ->after('avg_latency_ms_recent')
                ->comment('Phase A.2 / ADR-036: when current rolling window opened');
            $table->index('recent_window_start');
        });

        // Backfill: existing nodes start a fresh window at migration time.
        // Their current lifetime totals become the seed values of the new window
        // (treats the current values as "everything from before deploy" — gives
        // operators no scoring discontinuity at deploy).
        // CURRENT_TIMESTAMP works on both SQLite (tests) and MySQL (prod) —
        // NOW() is MySQL-only.
        DB::statement('
            UPDATE nodes
            SET tasks_total_recent = tasks_total,
                tasks_failed_recent = tasks_failed,
                avg_latency_ms_recent = avg_latency_ms,
                recent_window_start = CURRENT_TIMESTAMP
        ');
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex(['recent_window_start']);
            $table->dropColumn([
                'tasks_total_recent',
                'tasks_failed_recent',
                'avg_latency_ms_recent',
                'recent_window_start',
            ]);
        });
    }
};
