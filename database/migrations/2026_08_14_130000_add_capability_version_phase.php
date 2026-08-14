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
            $table->string('capability_version', 32)->nullable()->after('intent');
            $table->unsignedInteger('capability_phase')->nullable()->after('capability_version');
        });
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table): void {
            $table->dropColumn(['capability_version', 'capability_phase']);
        });
    }
};
