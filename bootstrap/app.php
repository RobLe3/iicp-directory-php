<?php

use App\Http\Middleware\ReplicaModeRedirect;
use App\Http\Middleware\SignReplicaResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Replica-mode write-gate (P6-1.3b-ii / S.13): when IICP_REPLICA_MODE=true,
        // all unsafe HTTP methods are 307-redirected to the seed at IICP_SEED_URL.
        // No-op on the genesis seed (IICP_REPLICA_MODE=false). Prepended so it runs
        // before NodeTokenAuth — a replica should redirect before attempting auth.
        $middleware->prependToGroup('api', ReplicaModeRedirect::class);

        // Replica-mode response signing (P6-4.2b-iii / S.13 §6.5 / DIR-FED-20):
        // when IICP_REPLICA_MODE=true, sign GET responses on discovery endpoints
        // with Ed25519. Appended (runs LAST) so it sees the final response body.
        $middleware->appendToGroup('api', SignReplicaResponse::class);

        // Trust Cloudflare's proxy headers so Request::ip() returns the real client IP
        // and CF-Connecting-IP is available. Cloudflare IPv4/IPv6 ranges as of 2025.
        $middleware->trustProxies(
            at: [
                // Cloudflare IPv4
                '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
                '104.16.0.0/13', '104.24.0.0/14',
                '108.162.192.0/18', '131.0.72.0/22',
                '141.101.64.0/18', '162.158.0.0/15',
                '172.64.0.0/13', '173.245.48.0/20',
                '188.114.96.0/20', '190.93.240.0/20',
                '197.234.240.0/22', '198.41.128.0/17',
                // Cloudflare IPv6
                '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
                '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
                '2c0f:f248::/32',
            ],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => [
                        'code' => 'validation_error',
                        'message' => 'Input validation failed',
                        'fields' => $e->errors(),
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => ['code' => 'not_found', 'message' => 'Resource not found'],
                ], 404);
            }
        });

        // ThrottleRequestsException is a Symfony HttpException — without this handler
        // it falls through to the Throwable catch-all below and becomes a spurious 500.
        // Must be registered BEFORE the Throwable handler so it wins first.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
                $message = $retryAfter
                    ? "Too many requests — retry after {$retryAfter}s"
                    : 'Too many requests';

                return response()->json([
                    'error' => ['code' => 'rate_limited', 'message' => $message],
                ], 429)->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') && ! app()->hasDebugModeEnabled()) {
                return response()->json([
                    'error' => ['code' => 'server_error', 'message' => 'An internal error occurred'],
                ], 500);
            }
        });
    })->create();
