<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // ADR-017: Operator opt-in public listing (REG-01 — no endpoint URL exposed)
            $table->boolean('public_listing')->default(false)->after('attested');
            $table->string('operator_url', 256)->nullable()->after('public_listing');
            $table->string('operator_contact', 256)->nullable()->after('operator_url');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['public_listing', 'operator_url', 'operator_contact']);
        });
    }
};
