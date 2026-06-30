<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-035 / credit-economy/09 §4A — the 90-day TTL credit sink.
 *
 * Adds `credit_transactions.expires_at`: the retention/expiry horizon for an
 * earn. On every `earn` (type=credit) the directory sets this to NOW()+TTL_days
 * (90). A node whose newest earn is past its TTL with a positive balance is
 * "idle" and its unspent balance is swept by the nightly iicp:expire-credits
 * command (the primary anti-inflation sink; the 2% burn is the secondary one).
 *
 * This is a LOCAL schema migration. Production application is a separate,
 * maintainer-gated deploy step — it never runs against prod from here.
 *
 * Spec: iicp-billing-extension §11 (Ledger Retention). Pinned to
 * credit_economy.TTL_days so retention follows the economy parameter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            // Nullable: spend/expire/burn rows carry no TTL; only earns do.
            $table->timestamp('expires_at')->nullable()->after('reason');
            $table->index('expires_at', 'credit_tx_expires_at_idx');
        });

        // Backfill existing earn rows so the sweep has a determinable TTL for
        // pre-migration credits: expires_at = created_at + 90 days. Spend/expire
        // rows stay NULL. Driver-portable (MySQL/MariaDB on prod, SQLite in tests);
        // bounded by current ledger size (Phase 1).
        $driver = DB::connection()->getDriverName();
        $expr = match ($driver) {
            'sqlite' => "datetime(created_at, '+90 days')",
            'pgsql' => "created_at + INTERVAL '90 days'",
            default => 'DATE_ADD(created_at, INTERVAL 90 DAY)', // mysql / mariadb
        };
        DB::table('credit_transactions')
            ->where('type', 'credit')
            ->whereNull('expires_at')
            ->update(['expires_at' => DB::raw($expr)]);
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex('credit_tx_expires_at_idx');
            $table->dropColumn('expires_at');
        });
    }
};
