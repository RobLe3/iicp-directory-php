<?php

use App\Http\Controllers\ConsumerTokenController;
use App\Http\Middleware\NodeTokenAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/directory-key', [ConsumerTokenController::class, 'publicKey'])
        ->middleware('throttle:60,1');

    Route::post('/consumer-token', [ConsumerTokenController::class, 'issue'])
        ->middleware([NodeTokenAuth::class, 'restricted-domain:consumer_token', 'throttle:consumer-token']);
});
