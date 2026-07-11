<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR phase-1 DSR audit log.
 *
 * This table intentionally stores only redacted selector hashes, counts and
 * retention reasons. It must not store prompts, endpoint secrets, node tokens,
 * full operator keys, identity documents, or support-message bodies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_subject_actions', function (Blueprint $table): void {
            $table->id();
            $table->string('tracking_id', 96)->unique();
            $table->string('action', 32);
            $table->string('subject_hash', 64)->index();
            $table->json('selector');
            $table->json('affected_counts')->nullable();
            $table->string('retention_reason', 255)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['action', 'applied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_actions');
    }
};
