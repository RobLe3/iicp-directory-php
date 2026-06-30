<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('endpoint_verified_dead_at')
                ->nullable()
                ->after('public_reachable')
                ->index('nodes_endpoint_verified_dead_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropIndex('nodes_endpoint_verified_dead_at_index');
            $table->dropColumn('endpoint_verified_dead_at');
        });
    }
};
