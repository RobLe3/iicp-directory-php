<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('implementation_name', 64)->nullable()->after('sdk_language');
            $table->string('implementation_version', 32)->nullable()->after('implementation_name');
            $table->string('sdk_compatibility_version', 32)->nullable()->after('implementation_version');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['implementation_name', 'implementation_version', 'sdk_compatibility_version']);
        });
    }
};
