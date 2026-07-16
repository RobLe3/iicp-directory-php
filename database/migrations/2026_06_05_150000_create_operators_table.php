<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #463/#310/#464 — the operator-identity record, keyed by operator_id (== the ed25519
 * `operator_pubkey` the directory verifies via the ADR-045 delegation). One row per
 * operator (NOT per node): the single source of truth for an operator's public identity
 * across node detail, the recognition leaderboard, and future community surfaces.
 *
 * - operator_pubkey: PRIVATE — the key; never exposed in any public API response.
 * - display_name: PUBLIC, mutable (operator-signed to change) — the universal handle.
 * - attested_created_at + operator_integrity_hash = SHA256(operator_id ':' created_at):
 *   self-attested, pinned on first register for tamper detection. A self-claimed
 *   created_at is backdatable, so it is NEVER authoritative for ordinals.
 * - first_seen_ms: DIRECTORY-observed; authoritative for founder ordinal/tier timing.
 * - ordinal/tier/badge/provenance: #310 founder recognition (nullable until lock-in;
 *   populated by the lock-in detector in a later slice).
 *
 * Rust-directory parity (#385): iicp-directory-rs migration 007.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            // `ed25519:` + 64 hex characters.
            $table->string('operator_pubkey', 80)->unique();
            $table->string('display_name', 64)->nullable();
            $table->string('attested_created_at', 40)->nullable();
            $table->char('operator_integrity_hash', 64)->nullable();
            $table->unsignedBigInteger('first_seen_ms')->nullable();
            // #310 founder recognition (populated at lock-in; nullable until then).
            $table->unsignedBigInteger('ordinal')->nullable();
            $table->string('tier', 32)->nullable();
            $table->string('badge', 32)->nullable();
            $table->json('provenance')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
