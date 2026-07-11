<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #608 — directory-owned lifecycle state for policy-signing keys.
 *
 * The raw public key is not stored here: records are keyed by SHA-256(public_key_bytes).
 * Public APIs may expose only short fingerprints and normalized lifecycle status derived
 * from this table, never raw keys or evidence documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_key_lifecycle_records', function (Blueprint $table) {
            $table->id();
            $table->char('policy_key_sha256', 64)->unique();
            $table->string('status', 32)->default('active'); // active|revoked|superseded
            $table->unsignedInteger('rotation_epoch')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason_class', 64)->nullable();
            $table->char('superseded_by_policy_key_sha256', 64)->nullable();
            $table->string('evidence_ref', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'policy_key_lifecycle_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_key_lifecycle_records');
    }
};
