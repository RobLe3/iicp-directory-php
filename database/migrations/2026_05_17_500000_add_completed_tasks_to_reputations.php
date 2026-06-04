<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reputations', function (Blueprint $table) {
            $table->unsignedBigInteger('completed_tasks_count')->default(0)->after('tasks_failed');
        });
    }

    public function down(): void
    {
        Schema::table('reputations', function (Blueprint $table) {
            $table->dropColumn('completed_tasks_count');
        });
    }
};
