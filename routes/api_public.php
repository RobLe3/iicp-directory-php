<?php

use App\Http\Controllers\BadgeController;
use App\Http\Controllers\ComplianceAttestationController;
use App\Http\Controllers\ConformanceController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\OperatorController;
use App\Http\Controllers\ProbeController;
use App\Http\Controllers\RegistryController;
use App\Http\Controllers\ReplicasController;
use App\Http\Controllers\SnapshotController;
use App\Http\Controllers\StatsController;
use App\Http\Middleware\ReplicaTokenAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public stats endpoint
    Route::get('/stats', [StatsController::class, 'index']);

    // #460/#463 — operator-signed display_name rename (mutable nickname over the immutable
    // operator_id). Self-authenticating via the operator's ed25519 signature (no node token);
    // lives here (not api_protocol) to keep the protocol route file under the fan-out limit.
    Route::post('/operator/rename', [OperatorController::class, 'rename'])
        ->middleware('throttle:60,1');

    // #310/#463 — public recognition leaderboards (spec iicp-recognition §6). Anonymous,
    // cacheable; serves the public display_name + recognition state, never operator_pubkey.
    Route::get('/leaderboards/{board_id}', [LeaderboardController::class, 'show'])
        ->where('board_id', '[a-z0-9_]+')
        ->middleware('throttle:60,1');

    // ADR-017 — Public registry API (unauthenticated, rate-limited 60/min/IP)
    Route::middleware('throttle:60,1')->prefix('registry')->group(function () {
        Route::get('/stats', [RegistryController::class, 'stats']);
        Route::get('/intents', [RegistryController::class, 'intents']);
        Route::get('/nodes', [RegistryController::class, 'nodes']);
        Route::get('/nodes/{prefix}', [RegistryController::class, 'show'])
            ->where('prefix', '[a-zA-Z0-9][a-zA-Z0-9._:-]{0,35}');
    });

    // S.14 — Conformance badge API (unauthenticated, rate-limited 30/min/IP)
    Route::middleware('throttle:30,1')->prefix('conformance')->group(function () {
        Route::post('/submit', [ConformanceController::class, 'submit']);
        Route::get('/verify', [ConformanceController::class, 'verify']);
        Route::get('/badges', [ConformanceController::class, 'badges']);
    });

    // S.14 — SVG badge shield (BADGE-04, expiry-aware, 60 req/min/IP)
    Route::middleware('throttle:60,1')
        ->get('/badge/{tier}', [BadgeController::class, 'shield'])
        ->where('tier', '[a-z0-9:]+');

    // Phase 6 prerequisite: signed event log (spec/iicp-federated-directory.md §3.4)
    Route::get('/events', [EventsController::class, 'index']);

    // #508 — signed compliance attestation (spec/iicp-dir.md §12, SHOULD; 30 req/min/IP)
    Route::middleware('throttle:30,1')
        ->get('/compliance-attestation', [ComplianceAttestationController::class, 'index']);

    // #122 Part B — External reachability probe (10 req/min/IP, SSRF-safe)
    Route::middleware('throttle:10,1')
        ->get('/probe', [ProbeController::class, 'check']);

    // Phase 6 — Replica registration handshake (S.13 §7.1, 5 req/hour/IP)
    Route::middleware('throttle:5,60')
        ->post('/replicas/register', [ReplicasController::class, 'register']);

    // Phase 6 — Snapshot bootstrap (S.13 §5.5 / DIR-FED-15/17, P6-1.3b-iii)
    // 5 req/replica/hour: bootstrap is infrequent; abuse prevention.
    Route::middleware([ReplicaTokenAuth::class, 'throttle:5,60'])
        ->get('/snapshot', [SnapshotController::class, 'show']);
});
