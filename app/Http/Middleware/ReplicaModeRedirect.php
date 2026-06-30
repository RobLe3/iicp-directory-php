<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ReplicaModeRedirect — P6-1.3b-ii.
 *
 * When this directory is running as a replica (IICP_REPLICA_MODE=true), it
 * MUST NOT accept writes: token issuance happens only on the seed, and the
 * federated event log MUST stay single-source. This middleware intercepts
 * any unsafe HTTP method (POST/PUT/PATCH/DELETE) and returns 307 Temporary
 * Redirect to the configured seed (IICP_SEED_URL), preserving the original
 * path + query. Reads (GET/HEAD/OPTIONS) and the replica-mirror apply path
 * (artisan iicp:replica-start --apply) are unaffected.
 *
 * Configuration:
 * - IICP_REPLICA_MODE (bool, default false) — master switch; true on a replica
 * - IICP_SEED_URL     (string) — required when IICP_REPLICA_MODE=true; the seed
 *                                 directory base URL clients should retry against
 *
 * Response (when triggered on POST/PUT/PATCH/DELETE):
 *   HTTP/1.1 307 Temporary Redirect
 *   Location: <IICP_SEED_URL>/<original-path-and-query>
 *   X-IICP-Replica-Redirect: true
 *   X-IICP-Redirect-Reason:  replica_mode
 *   Retry-After: 0
 *
 * Misconfiguration handling: if IICP_REPLICA_MODE=true but IICP_SEED_URL is
 * missing or non-https, the middleware returns 503 IICP-E047 (replica_mode_misconfigured)
 * — the replica cannot redirect writes without a target, so it must refuse them.
 *
 * Conformance: DIR-FED-05 (clients MUST follow 307 transparently); reciprocal
 * of LoadRedirect (seed→replica for reads). Combined, the 307 protocol forms
 * the full read/write split between seed and replicas.
 */
class ReplicaModeRedirect
{
    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }
        if (! in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return $next($request);
        }

        $seedUrl = $this->seedUrl();
        if ($seedUrl === null) {
            return response()->json([
                'error' => [
                    'code' => 'IICP-E047',
                    'message' => 'replica_mode_misconfigured: IICP_REPLICA_MODE=true but IICP_SEED_URL is missing or non-https',
                ],
            ], 503);
        }

        $target = rtrim($seedUrl, '/').'/'.ltrim($request->getRequestUri(), '/');

        return response('', 307, [
            'Location' => $target,
            'X-IICP-Replica-Redirect' => 'true',
            'X-IICP-Redirect-Reason' => 'replica_mode',
            'Retry-After' => '0',
        ]);
    }

    public static function enabled(): bool
    {
        return filter_var(env('IICP_REPLICA_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }

    private function seedUrl(): ?string
    {
        $url = (string) env('IICP_SEED_URL', '');
        if ($url === '') {
            return null;
        }
        // Production requires https. The docker-compose federation testbed wires seed↔replica
        // over plain http inside the bridge network; IICP_DEV_ALLOW_HTTP_DID (non-production
        // only) permits http:// here, mirroring ReplicasController/ReplicaStartCommand.
        $allowHttp = filter_var(env('IICP_DEV_ALLOW_HTTP_DID', false), FILTER_VALIDATE_BOOLEAN)
            && config('app.env') !== 'production';
        $ok = str_starts_with($url, 'https://') || ($allowHttp && str_starts_with($url, 'http://'));

        return $ok ? $url : null;
    }
}
