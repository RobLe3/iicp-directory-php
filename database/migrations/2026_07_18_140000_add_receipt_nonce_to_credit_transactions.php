<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_transactions', 'nonce')) {
            Schema::table('credit_transactions', function (Blueprint $table): void {
                // Rust-directory parity: durable replay evidence. Existing PHP
                // transactions remain NULL and PHP continues to use its atomic
                // cache lock on the request path.
                $table->string('nonce', 64)->nullable()->after('task_id');
                $table->unique('nonce', 'credit_transactions_nonce_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_transactions', 'nonce')) {
            Schema::table('credit_transactions', function (Blueprint $table): void {
                $table->dropUnique('credit_transactions_nonce_unique');
                $table->dropColumn('nonce');
            });
        }
    }
};
