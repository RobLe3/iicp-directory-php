<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_domain_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('domain_id', 191);
            $table->string('subject_kind', 16);
            $table->string('subject_id', 191);
            $table->string('issuer_id', 191);
            $table->char('token_hash', 64)->unique();
            $table->json('scopes');
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['domain_id', 'subject_kind', 'subject_id'],
                'trust_domain_membership_subject_unique'
            );
            $table->index(
                ['domain_id', 'revoked_at', 'expires_at'],
                'trust_domain_membership_validity_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_domain_memberships');
    }
};
