<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // ADR-019 declarative pricing fields
            $table->float('credit_cost_multiplier')->default(1.0)->after('pricing_credits_per_1000');
            $table->string('pricing_model', 32)->default('per_token')->after('credit_cost_multiplier');
            $table->text('declaration_signature')->nullable()->after('pricing_model');
            $table->boolean('attested')->default(false)->after('declaration_signature');
            $table->timestamp('pricing_effective_from')->nullable()->after('attested');
            $table->timestamp('pricing_effective_until')->nullable()->after('pricing_effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'credit_cost_multiplier',
                'pricing_model',
                'declaration_signature',
                'attested',
                'pricing_effective_from',
                'pricing_effective_until',
            ]);
        });
    }
};
