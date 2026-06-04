<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A.3 / ADR-039 §5.5: replicas lifecycle status.
 *
 * Adds a status enum to replicas so dormant/archived/decommissioned states
 * are first-class. Mirrors the nodes lifecycle pattern (active → dormant
 * → archived).
 *
 * Status transitions (driven by RotateReplicaLifecycleCommand cron):
 *   active       — last_seen_at within 7 days
 *   dormant      — last_seen_at 7-30 days old; still in registry but trust_tier=low
 *   archived     — last_seen_at 30d-1y old; DROPPED from /.well-known/iicp-replicas.json
 *   decommissioned — last_seen_at >1y; soft-purged but row retained for audit
 *
 * Tracking: ADR-039, verified-retention-plan-2026-05-26.md §1.10.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('replicas', function (Blueprint $table) {
            $table->string('status', 16)
                ->default('active')
                ->after('trust_tier')
                ->comment('Phase A.3: active/dormant/archived/decommissioned (lifecycle state)');
            $table->index('status');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('replicas', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('status');
        });
    }
};
