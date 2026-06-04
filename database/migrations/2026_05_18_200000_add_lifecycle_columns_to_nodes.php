<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Columns applied to production via deploy/patches/reputation_persistence_v0.5.0.sql
// and deploy/patches/reputation_persistence_v0.5.1_remediation.sql — this migration
// keeps the test database in sync so php artisan test works without the SQL patches.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            if (! Schema::hasColumn('nodes', 'status')) {
                $table->string('status')->default('active')->after('attested');
            }
            if (! Schema::hasColumn('nodes', 'dormant_since')) {
                $table->timestamp('dormant_since')->nullable()->after('status');
            }
            if (! Schema::hasColumn('nodes', 'identity_key')) {
                $table->string('identity_key', 64)->nullable()->after('dormant_since');
            }
            if (! Schema::hasColumn('nodes', 'lifetime_jobs')) {
                $table->unsignedBigInteger('lifetime_jobs')->default(0)->after('identity_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['status', 'dormant_since', 'identity_key', 'lifetime_jobs']);
        });
    }
};
