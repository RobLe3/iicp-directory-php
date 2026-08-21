<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trust_domain_memberships', function (Blueprint $table): void {
            $table->json('membership_envelope')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('trust_domain_memberships', function (Blueprint $table): void {
            $table->dropColumn('membership_envelope');
        });
    }
};
