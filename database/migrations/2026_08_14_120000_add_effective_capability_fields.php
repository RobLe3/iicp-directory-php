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
            $table->string('variant_id', 64)->nullable()->after('intent');
            $table->json('output_modalities')->nullable()->after('input_modalities');
            $table->json('features')->nullable()->after('output_modalities');
            $table->json('execution_capabilities')->nullable()->after('features');
            $table->json('capability_limits')->nullable()->after('execution_capabilities');
            $table->json('claim_provenance')->nullable()->after('supported_profiles');
            $table->json('extensions')->nullable()->after('claim_provenance');
            $table->unique(['node_id', 'intent', 'variant_id'], 'capabilities_node_intent_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table): void {
            $table->dropUnique('capabilities_node_intent_variant_unique');
            $table->dropColumn([
                'variant_id',
                'output_modalities',
                'features',
                'execution_capabilities',
                'capability_limits',
                'claim_provenance',
                'extensions',
            ]);
        });
    }
};
