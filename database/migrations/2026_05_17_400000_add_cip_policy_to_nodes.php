<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIP-D1 (#73): Provider Opt-In policy block — spec S.12 §2.1.
 *
 * All CIP policy flags default to false (MUST per spec — nodes opt in explicitly).
 * pricing_credits_per_1000 is nullable: null = pricing not declared (directory
 * uses a default rate for scoring; ADR-019 future multiplier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('allow_remote_inference')->default(false)->after('relay_capable');
            $table->boolean('allow_tool_execution')->default(false)->after('allow_remote_inference');
            $table->boolean('allow_file_access')->default(false)->after('allow_tool_execution');
            $table->decimal('pricing_credits_per_1000', 10, 4)->nullable()->after('allow_file_access');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'allow_remote_inference',
                'allow_tool_execution',
                'allow_file_access',
                'pricing_credits_per_1000',
            ]);
        });
    }
};
