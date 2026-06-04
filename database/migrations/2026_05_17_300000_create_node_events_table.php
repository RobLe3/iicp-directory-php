<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 prerequisite: append-only signed event log.
 *
 * Spec: spec/iicp-federated-directory.md §3.4 Event Log
 * Sequence numbers are monotonically increasing per genesis seed instance.
 * Signatures are Ed25519 over canonical JSON per the spec §3.4 signing input.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('seq')->unique();
            $table->string('event_type', 32);  // REGISTER | HEARTBEAT | SCORE_UPDATE | REPUTATION_UPDATE | CREDIT_AWARD | DEREGISTER
            $table->string('node_id', 36)->nullable()->index();
            $table->unsignedBigInteger('ts_ms');  // Unix ms timestamp
            $table->json('payload');
            $table->string('signature', 128)->nullable();  // Ed25519 hex; null until key provisioned
            $table->timestamps();

            $table->index(['seq', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_events');
    }
};
