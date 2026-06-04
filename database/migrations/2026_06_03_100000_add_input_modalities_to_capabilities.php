<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #408 / ADR-046 — input modalities a capability accepts (["text"] default,
 * ["text","image"] for vision-language models). Additive; lets /v1/discover
 * filter to image-capable nodes (?modality=image) and surface the modality so
 * clients can route multimodal tasks. Vision is a modality of chat, not a new
 * intent. Existing rows backfilled to ["text"] (text-only, back-compatible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            $table->json('input_modalities')->nullable()->after('max_tokens');
        });

        // Backfill legacy rows to text-only so the ?modality=text filter and the
        // discover output behave consistently (no NULLs to special-case).
        DB::table('capabilities')->whereNull('input_modalities')
            ->update(['input_modalities' => json_encode(['text'])]);
    }

    public function down(): void
    {
        Schema::table('capabilities', function (Blueprint $table) {
            $table->dropColumn('input_modalities');
        });
    }
};
