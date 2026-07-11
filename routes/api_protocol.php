<?php

use App\Http\Controllers\AuditReportController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\DeregisterController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NodeController;
use App\Http\Controllers\PeersController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RelayTicketController;
use App\Http\Controllers\TelemetryController;
use App\Http\Middleware\LoadRedirect;
use App\Http\Middleware\NodeTokenAuth;
use App\Http\Middleware\ProbeTokenAuth;
use App\Http\Middleware\ProxyTokenAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/register', RegisterController::class)
        ->middleware('throttle:register');

    Route::delete('/register', DeregisterController::class)
        ->middleware(NodeTokenAuth::class);

    Route::post('/heartbeat', HeartbeatController::class)
        ->middleware([NodeTokenAuth::class, 'throttle:heartbeat']);

    Route::get('/discover', DiscoverController::class)
        ->middleware([LoadRedirect::class, 'throttle:60,1']);

    require __DIR__.'/api_protocol_dispatch.php';

    Route::get('/node/{id}', [NodeController::class, 'show'])
        ->middleware([LoadRedirect::class, 'throttle:60,1']);

    // Phase 2 — Mesh layer
    Route::get('/bootstrap', BootstrapController::class)
        ->middleware([LoadRedirect::class, 'throttle:60,1']);
    Route::post('/peers', PeersController::class)
        ->middleware(NodeTokenAuth::class);

    // Implicit Address Learning — DIR-ADDR-04
    Route::get('/me', MeController::class)
        ->middleware(NodeTokenAuth::class);

    // Phase 3 — Credits system (all routes require node auth).
    require __DIR__.'/api_protocol_credits.php';

    // REACH telemetry ingestion (probe token auth)
    Route::post('/telemetry/probe', [TelemetryController::class, 'store'])
        ->middleware(ProbeTokenAuth::class);

    // Proxy-observed metrics (#112/#114) — proxy_token auth only; node_token rejected (#114 Sybil gate)
    Route::post('/telemetry', [TelemetryController::class, 'proxyReport'])
        ->middleware(ProxyTokenAuth::class);

    // Trust-auditor declaration-divergence reports (#118 Part D) — node_token auth
    Route::post('/audit-report', AuditReportController::class)
        ->middleware([NodeTokenAuth::class, 'throttle:60,1']);

    // Relay bind ticket issuance (#510 / DIR-RELAY-03). Authenticated by the
    // worker's node_token; relays verify the directory signature offline.
    Route::post('/relay/ticket', [RelayTicketController::class, 'issue'])
        ->middleware([NodeTokenAuth::class, 'throttle:consumer-token']);

});
