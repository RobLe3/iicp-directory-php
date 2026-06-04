<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RT-02 fix (#376): drop cascadeOnDelete from credits.node_id FK so that
 * free_credit_last_allocation_at survives node deletion.
 *
 * Without this, an attacker can: register → earn free credits → deregister
 * (CASCADE deletes credits row) → re-register with same node_id → earn free
 * credits again, indefinitely.
 *
 * After this migration, the credits row is preserved when a node is deleted.
 * Re-registration with the same node_id finds the existing credits record and
 * respects the original free_credit_last_allocation_at gate.
 *
 * Credits are a financial ledger — their allocation history should survive
 * node lifecycle events. The node_id is a logical account identifier here,
 * not a referential integrity constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (used in testing) has no named FK constraints to drop.
        // Only MySQL/MariaDB (production) needs the explicit DROP + no-replace.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE credits DROP FOREIGN KEY credits_node_id_foreign');
            // No replacement FK — the node_id is a logical account identifier;
            // credits rows must outlive node lifecycle events.
        }
        // SQLite: FK constraints are unenforced by default (PRAGMA foreign_keys=OFF
        // in Laravel's test env), so no action needed — cascade behaviour never fires.
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement(
                'ALTER TABLE credits ADD CONSTRAINT credits_node_id_foreign
                 FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE'
            );
        }
    }
};
