<?php

use App\Http\Controllers\DispatchRouteTicketController;
use Illuminate\Support\Facades\Route;

// #612 / DIR-DISPATCH-01 — explicit ticketed route-dispatch discovery.
// Keeps public presentation discovery endpoint-free while giving new clients a
// short-lived, intent-scoped route ticket before task dispatch.
Route::post('/dispatch/ticket', [DispatchRouteTicketController::class, 'issue'])
    ->middleware(['restricted-domain:dispatch', 'throttle:60,1']);
