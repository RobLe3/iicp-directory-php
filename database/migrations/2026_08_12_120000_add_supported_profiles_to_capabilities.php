<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capabilities', function (Blueprint $table): void {
            $table->json('supported_profiles')->nullable()->after('input_modalities');
        });
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table): void {
            $table->dropColumn('supported_profiles');
        });
    }
};
