<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('auto_update_enabled')->nullable()->after('sdk_version');
            $table->unsignedInteger('auto_update_interval_s')->nullable()->after('auto_update_enabled');
            $table->string('sdk_latest_seen', 32)->nullable()->after('auto_update_interval_s');
            $table->timestamp('sdk_update_last_checked_at')->nullable()->after('sdk_latest_seen');
            $table->string('sdk_update_error_class', 64)->nullable()->after('sdk_update_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn([
                'auto_update_enabled',
                'auto_update_interval_s',
                'sdk_latest_seen',
                'sdk_update_last_checked_at',
                'sdk_update_error_class',
            ]);
        });
    }
};
