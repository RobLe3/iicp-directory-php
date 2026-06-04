<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 (W-041/c, charter P6-1.2): Genesis Seed replica registry.
 *
 * Stores the persistent identity + trust state of each replica directory
 * that registered against this Genesis Seed via POST /v1/replicas/register
 * (S.13 §7.1, v0.2.0).
 *
 * One row per (did) — DID is the natural key (the replica's W3C DID
 * identifier, e.g. did:web:replica.example.com). Re-registration is
 * idempotent: the existing row is updated and replica_token is rotated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replicas', function (Blueprint $table) {
            $table->id();
            // Surrogate identifier returned to the replica (UUIDv4)
            $table->uuid('replica_id')->unique();
            // Natural key — the replica's DID (e.g. did:web:replica.example.com)
            $table->string('did', 253)->unique();
            // HTTPS endpoint where the replica directory serves discovery
            $table->string('endpoint', 255);
            // Trust tier — always 'low' on first registration; governance promotes
            $table->string('trust_tier', 16)->default('low');
            // SHA-256 hash of the issued replica_token (JWT). We never store the
            // plaintext token; on rotation the new hash overwrites the old.
            $table->string('replica_token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->index(['trust_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replicas');
    }
};
