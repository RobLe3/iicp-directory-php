<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RT-01b mitigation: per-node hourly reputation velocity ceiling (#381).
 *
 * RT-01 caps the delta per heartbeat call (+0.10 max). RT-01b bypass: register N nodes,
 * each accumulates +0.10/heartbeat independently. With 60 nodes/IP, all reach 1.0 in 5
 * heartbeats (~8 seconds).
 *
 * This migration adds a rolling 1h velocity window to nodes so ReputationService can cap
 * total reputation gain per node per hour (MAX_HOURLY_REPUTATION_GAIN = 0.20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->decimal('rep_hourly_gain', 8, 4)->default(0)->after('reputation_score');
            $table->timestamp('rep_hourly_window_start')->nullable()->after('rep_hourly_gain');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['rep_hourly_gain', 'rep_hourly_window_start']);
        });
    }
};
