<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Deploy-time HMAC-gated migration runner — closes the systemic gap where
 * FTP-based deploy_directory.sh uploads migration files but never runs
 * `php artisan migrate`. Replaces the prior SSH-based step so the deploy
 * pipeline can drive itself end-to-end over plain HTTP.
 *
 * Wire:
 *   - Route POST /_deploy/migrate registered in routes/api.php
 *   - Single deploy secret stored at directory/.env as IICP_DEPLOY_SECRET
 *     and at deploy/.credentials/deploy_secret.sh on the client side.
 *   - Request must carry:
 *       X-IICP-Deploy-Timestamp: <unix seconds>
 *       X-IICP-Deploy-Signature: hex(hmac_sha256(timestamp + "\n" + version, secret))
 *     Body JSON: {"version": "<x.y.z>"}
 *
 * Security:
 *   - constant-time HMAC compare (no timing side-channel on the secret)
 *   - ±60s timestamp window (replay protection)
 *   - rate-limited 6/min/IP on top of the HMAC gate
 *   - returns 503 if IICP_DEPLOY_SECRET is unset (fail-closed)
 *   - runs `migrate --force` only; never `migrate:fresh` or `migrate:reset`
 *     so a compromised secret cannot drop tables.
 */
class DeployMigrateController extends Controller
{
    /** ±60 seconds drift allowed on the signed timestamp. */
    private const TIMESTAMP_WINDOW_SECS = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('app.deploy_secret', '');
        if ($secret === '') {
            return response()->json([
                'error' => [
                    'code' => 'deploy_disabled',
                    'message' => 'IICP_DEPLOY_SECRET not configured on the server.',
                ],
            ], 503);
        }

        $tsHeader = $request->header('X-IICP-Deploy-Timestamp');
        $sigHeader = $request->header('X-IICP-Deploy-Signature');
        $version = (string) $request->input('version', '');

        if (! is_string($tsHeader) || ! ctype_digit($tsHeader) || $sigHeader === null || $version === '') {
            return response()->json([
                'error' => [
                    'code' => 'bad_request',
                    'message' => 'X-IICP-Deploy-Timestamp + X-IICP-Deploy-Signature headers and {version} body field are required.',
                ],
            ], 400);
        }

        $ts = (int) $tsHeader;
        $now = time();
        if (abs($now - $ts) > self::TIMESTAMP_WINDOW_SECS) {
            return response()->json([
                'error' => [
                    'code' => 'timestamp_out_of_window',
                    'message' => 'Signed timestamp is '.abs($now - $ts).'s away from server time (window: ±'
                        .self::TIMESTAMP_WINDOW_SECS.'s).',
                    'server_time' => $now,
                ],
            ], 401);
        }

        $expected = hash_hmac('sha256', $ts."\n".$version, $secret);
        if (! hash_equals($expected, (string) $sigHeader)) {
            // Audit failed attempts so brute-force attempts are visible.
            Log::warning('deploy.migrate: invalid signature', [
                'version' => $version,
                'ip' => $request->ip(),
                'ts' => $ts,
            ]);

            return response()->json([
                'error' => ['code' => 'unauthorized', 'message' => 'Invalid signature.'],
            ], 401);
        }

        Log::info('deploy.migrate: starting', [
            'version' => $version,
            'ip' => $request->ip(),
        ]);

        // Capture artisan output. --force is required for production env where
        // migrate refuses to run interactively.
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            Log::error('deploy.migrate: artisan threw', [
                'version' => $version,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => [
                    'code' => 'migrate_failed',
                    'message' => $e->getMessage(),
                ],
            ], 500);
        }

        Log::info('deploy.migrate: complete', [
            'version' => $version,
            'exit_code' => $exitCode,
        ]);

        return response()->json([
            'ok' => $exitCode === 0,
            'version' => $version,
            'exit_code' => $exitCode,
            'output' => $output,
        ], $exitCode === 0 ? 200 : 500);
    }
}
