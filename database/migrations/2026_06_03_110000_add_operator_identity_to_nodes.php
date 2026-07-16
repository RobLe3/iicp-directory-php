<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-045 Phase A (#407 / #2) — verifiable operator→node binding.
 *
 * When a node registers with a valid ed25519 `operator_delegation`, the
 * directory records the cryptographically-verified operator public key + tier.
 * Phase A tier is `did_key` (self-asserted keypair, TOFU); `did_web`
 * (domain-verified, higher trust per OPEN-2) layers on later. Additive +
 * nullable — nodes without a delegation register exactly as before (the
 * non-cryptographic `operator.json` id string path is unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // `ed25519:` + 64 hex characters; verified at register.
            $table->string('operator_pubkey', 80)->nullable()->after('operator_contact');
            // true once a valid delegation bound this node to operator_pubkey.
            $table->boolean('operator_verified')->default(false)->after('operator_pubkey');
            // 'did_key' (self-asserted) | 'did_web' (domain-verified). OPEN-2.
            $table->string('operator_trust_tier', 16)->nullable()->after('operator_verified');
            // index for operator-scoped queries (reputation-by-operator, Phase C).
            $table->index('operator_pubkey', 'nodes_operator_pubkey_idx');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex('nodes_operator_pubkey_idx');
            $table->dropColumn(['operator_pubkey', 'operator_verified', 'operator_trust_tier']);
        });
    }
};
