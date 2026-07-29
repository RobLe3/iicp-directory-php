<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Providers;

use App\Support\AppKeyPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        AppKeyPolicy::assertSafeForEnvironment(
            (string) config('app.env'),
            (string) config('app.key'),
        );

        // POST /v1/register rate-limit (anti-spam-register abuse vector).
        // Maintainer directive 2026-05-27: defer in non-prod (dev convenience),
        // raise to 60/min in prod (was 10/min — too aggressive while feature
        // work is in flight and operators are iterating).
        // Future: claim-back on clean deregister so operators can re-register
        // immediately after rotating identity (separate issue).
        RateLimiter::for('register', function (Request $request) {
            if (config('app.env') !== 'production') {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->ip())->response(function () {
                return response()->json([
                    'error' => [
                        'code' => 'rate_limited',
                        'message' => 'Registration rate limit exceeded (60/min/IP). Wait ~1 minute and retry, or deregister an existing node to free quota.',
                        'retry_after_seconds' => 60,
                    ],
                ], 429);
            });
        });

        // POST /v1/heartbeat: 60 requests per minute per node
        RateLimiter::for('heartbeat', function (Request $request) {
            return Limit::perMinute(60)->by($request->input('node_id', $request->ip()));
        });

        // POST /v1/consumer-token: 20 per minute per authenticated node (#496)
        RateLimiter::for('consumer-token', function (Request $request) {
            return Limit::perMinute(20)->by($request->bearerToken() ?? $request->ip());
        });
    }
}
