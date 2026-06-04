<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            // Advisory fields per iicp-core.md §2.1 v1.2.4 (#118).
            // Directory MUST NOT reject unknown values — nullable, no enum constraint.
            $table->string('quantization', 32)->nullable()->after('max_tokens');
            $table->string('inference_engine', 32)->nullable()->after('quantization');
        });
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            $table->dropColumn(['quantization', 'inference_engine']);
        });
    }
};
