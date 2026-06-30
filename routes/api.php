<?php

use App\Http\Controllers\DeployMigrateController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

// ADR-014 §3: Prometheus metrics (MUST by Phase 5 gate)
Route::get('/metrics', MetricsController::class)->middleware('throttle:30,1');

// Deploy-time HMAC-gated migration runner. Replaces the prior SSH-based
// `artisan migrate` step in deploy_directory.sh. Rate-limited on top of
// the HMAC + timestamp gate so brute-force attempts are bounded.
Route::post('/_deploy/migrate', DeployMigrateController::class)
    ->middleware('throttle:6,1');

require __DIR__.'/api_protocol.php';
require __DIR__.'/api_consumer_token.php';
require __DIR__.'/api_public.php';
