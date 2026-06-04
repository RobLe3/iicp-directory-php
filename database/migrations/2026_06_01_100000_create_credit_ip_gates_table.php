<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RT-02b mitigation: IP-level free credit gate (#380).
 *
 * The per-node_id free credit gate (credits.free_credit_last_allocation_at)
 * only prevents re-harvest with the SAME node_id. A fresh registration creates
 * a new credits row with NULL last_allocation_at, bypassing the 6h period.
 *
 * This table adds a secondary guard keyed on source IP. Even with a new node_id,
 * CreditService checks whether this IP already received free credits within the
 * FREE_CREDITS_PERIOD_HOURS window before allocating again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_ip_gates', function (Blueprint $table): void {
            $table->string('ip_address', 45)->primary();
            $table->timestamp('last_allocation_at')->nullable();
            $table->unsignedInteger('allocation_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ip_gates');
    }
};
