<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-047 Part A (#411) — cryptographic liveness via heartbeat challenge-response.
 *
 * The directory issues a fresh nonce in each heartbeat response; the node HMACs
 * it with its node_hmac_key (ADR-019) and returns it on the next beat. A match
 * upgrades "holds a node_token" to "controls the HMAC key" (anti-replay /
 * anti-token-theft), recorded as liveness_verified_at — WITHOUT any dial-back
 * (works for CGNAT/IPv6 nodes the directory can't reach). Additive + nullable:
 * nodes that don't answer the challenge simply stay liveness-unverified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // The nonce issued in the last heartbeat response, awaiting HMAC proof.
            $table->string('liveness_challenge', 64)->nullable()->after('last_seen');
            // When the node last proved control of its HMAC key (null = never).
            $table->timestamp('liveness_verified_at')->nullable()->after('liveness_challenge');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['liveness_challenge', 'liveness_verified_at']);
        });
    }
};
