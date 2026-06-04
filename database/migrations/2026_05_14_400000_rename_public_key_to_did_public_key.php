<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// GAP-09: public_key arrived in Phase 1 schema before the Phase 3 DID spec was written.
// Rename to did_public_key to make the Phase 3 intent explicit and exclude it from Phase 1
// REGISTER validation. Field remains nullable; populated when Phase 3 DID identity lands.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->renameColumn('public_key', 'did_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->renameColumn('did_public_key', 'public_key');
        });
    }
};
