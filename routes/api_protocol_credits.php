<?php

use App\Http\Controllers\CreditsController;
use App\Http\Middleware\NodeTokenAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(NodeTokenAuth::class)->group(function () {
    Route::get('/credits/balance', [CreditsController::class, 'balance']);
    Route::get('/credits/summary', [CreditsController::class, 'summary']);
    Route::get('/credits/transactions', [CreditsController::class, 'transactions']);
    Route::get('/credits/quote', [CreditsController::class, 'quote']);
    Route::post('/credits/award', [CreditsController::class, 'award']);
});
