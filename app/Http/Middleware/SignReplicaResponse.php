<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Middleware;

use App\Models\NodeEvent;
use App\Services\RuntimeSecretProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SignReplicaResponse — P6-4.2b-iii.
 *
 * When this directory runs in replica mode, sign discovery responses with
 * the replica's own Ed25519 key per S.13 v0.3.6 §6.5 / DIR-FED-20. The
 * Genesis Seed does NOT sign — clients trust the seed via TLS+DNS.
 * Replicas sign so proxies can verify end-to-end and bypass intermediary
 * tampering (TLS terminators, transparent proxies, compromised edges).
 *
 * Canonical signing input (matches §3.4 event log pattern):
 *   SHA256_bin(method + ":" + path + ":" + query_canonical + ":" +
 *              snapshot_seq + ":" + SHA256_hex(response_body))
 *
 * Configuration:
 * - IICP_REPLICA_MODE (bool, default false) — master switch
 * - IICP_REPLICA_ED25519_SECRET_KEY (128 hex chars) — REQUIRED in replica mode
 *
 * Routes signed: GET /v1/discover, /v1/node/{id}, /v1/bootstrap, /v1/snapshot
 * (anything else passes through unsigned — heartbeat-style endpoints don't
 * need it).
 *
 * Headers added on signed responses:
 *   X-IICP-Replica-DID:       did:web:<replica-domain>
 *   X-IICP-Replica-Sig:       <128 hex>
 *   X-IICP-Snapshot-Seq:      <decimal>
 *
 * Misconfiguration: replica mode without a secret key → 503 IICP-E048.
 */
class SignReplicaResponse
{
    public function __construct(private readonly RuntimeSecretProvider $secrets) {}

    private const SIGNED_PATH_PREFIXES = [
        '/api/v1/discover',
        '/api/v1/node/',
        '/api/v1/bootstrap',
        '/api/v1/snapshot',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! ReplicaModeRedirect::enabled()) {
            return $next($request);
        }
        if ($request->getMethod() !== 'GET') {
            return $next($request);
        }
        if (! $this->shouldSign($request->getPathInfo())) {
            return $next($request);
        }

        $secretHex = $this->secrets->get(RuntimeSecretProvider::REPLICA_ED25519_SECRET_KEY) ?? '';
        if (strlen($secretHex) !== 128) {
            return response()->json([
                'error' => [
                    'code' => 'IICP-E048',
                    'message' => 'replica_signing_misconfigured: IICP_REPLICA_ED25519_SECRET_KEY missing or wrong length (need 128 hex chars)',
                ],
            ], 503);
        }

        $response = $next($request);
        if (! $response->isSuccessful()) {
            return $response;
        }

        try {
            $secret = sodium_hex2bin($secretHex);
        } catch (\SodiumException $e) {
            Log::error('Invalid IICP_REPLICA_ED25519_SECRET_KEY (not hex)', ['error' => $e->getMessage()]);

            return response()->json([
                'error' => ['code' => 'IICP-E048', 'message' => 'replica_signing_misconfigured: invalid hex'],
            ], 503);
        }

        $body = (string) $response->getContent();
        $snapshotSeq = (int) (NodeEvent::max('seq') ?? 0);
        $message = $this->signingInput(
            $request->getMethod(),
            $request->getPathInfo(),
            (string) $request->getQueryString(),
            $snapshotSeq,
            $body
        );
        $sig = bin2hex(sodium_crypto_sign_detached($message, $secret));
        $did = 'did:web:'.parse_url(config('app.url'), PHP_URL_HOST);

        $response->headers->set('X-IICP-Replica-DID', $did);
        $response->headers->set('X-IICP-Replica-Sig', $sig);
        $response->headers->set('X-IICP-Snapshot-Seq', (string) $snapshotSeq);

        return $response;
    }

    private function shouldSign(string $path): bool
    {
        foreach (self::SIGNED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the canonical signing input per S.13 §6.5. Returns 32-byte SHA256.
     * Query parameters MUST be sorted by name for client/server agreement;
     * proxy/src/proxy/clients/replica_sig_verifier.py::canonicalize_query
     * does the same sort.
     */
    private function signingInput(string $method, string $path, string $query, int $snapshotSeq, string $body): string
    {
        $canonical = implode(':', [
            strtoupper($method),
            $path,
            $this->canonicalizeQuery($query),
            (string) $snapshotSeq,
            hash('sha256', $body),
        ]);

        return hash('sha256', $canonical, true);
    }

    private function canonicalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                $pairs[] = [urldecode($part), ''];
            } else {
                $pairs[] = [urldecode(substr($part, 0, $eq)), urldecode(substr($part, $eq + 1))];
            }
        }
        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $encoded = array_map(fn ($p) => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pairs);

        return implode('&', $encoded);
    }
}
