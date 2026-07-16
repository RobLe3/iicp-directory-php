<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portability-first event origin metadata (#624).
 *
 * Nullable by design: existing signatures use the v1 signing input and remain
 * valid. Runtime emitters do not set this field until the independent-service
 * activation gate is explicitly closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_events', function (Blueprint $table) {
            $table->string('service_id', 64)->nullable()->after('event_type')->index();
        });
    }

    public function down(): void
    {
        Schema::table('node_events', function (Blueprint $table) {
            $table->dropIndex(['service_id']);
            $table->dropColumn('service_id');
        });
    }
};
