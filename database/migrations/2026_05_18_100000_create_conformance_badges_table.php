<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conformance_badges', function (Blueprint $table) {
            $table->string('badge_id', 36)->primary(); // UUID v4
            $table->string('tier', 32);                // iicp:core:v1 etc.
            $table->string('subject_did', 256);
            $table->string('subject_component', 16);   // adapter|proxy|sdk|replica
            $table->string('suite_version', 32);       // semver
            $table->dateTime('passed_at');
            $table->dateTime('expires_at');            // BADGE-03: 90 days after passed_at
            $table->string('test_results_url', 512);
            $table->string('issuer_did', 256);
            $table->string('verification_mode', 32)->default('self-attested');
            $table->text('sig');                       // base64url Ed25519 signature
            $table->string('status', 16)->default('active'); // active|expired|revoked
            $table->timestamps();

            $table->index(['subject_did', 'tier', 'status']);
            $table->index(['expires_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conformance_badges');
    }
};
