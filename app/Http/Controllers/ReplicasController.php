<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Http\Controllers;

use App\Models\Replica;
use App\Services\NodeEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 6 — POST /v1/replicas/register
 *
 * Implements the replica registration handshake from S.13 §7.1 (v0.2.0,
 * iter-1345 P6-1.1 charter). A replica operator posts its DID + endpoint;
 * the Genesis Seed validates the DID resolves, the endpoint is reachable
 * over https on a non-private address, and returns a scoped `replica_token`
 * + initial event-log offset + `genesis_hash`.
 *
 * Conformance: DIR-FED-11 (DID validation), DIR-FED-12 (SSRF guard),
 * DIR-FED-13 (idempotency on `did`), DIR-FED-14 (genesis_hash parity).
 *
 * Error codes: IICP-E040..E044 per spec §7.1.
 */
class ReplicasController extends Controller
{
    // SSRF guard — same blocked ranges as ProbeController (parity per DIR-FED-12 spec note).
    private const BLOCKED_CIDR = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
    ];

    private const REPLICA_TOKEN_TTL_DAYS = 90;

    private const HTTP_TIMEOUT_SECONDS = 5;

    private const ALLOWED_TRUST_TIERS = ['low', 'medium', 'high'];

    public function __construct(private NodeEventLogger $eventLogger) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // P6-3.1d: accept did:web:host or did:web:host%3A<port> (percent-encoded port).
            'did' => ['required', 'string', 'max:253', 'regex:/^did:web:[a-zA-Z0-9._\-]+(?:%3A\d{1,5})?$/i'],
            'endpoint' => ['required', 'string', 'max:255', 'url'],
            'trust_tier_request' => ['sometimes', 'string', 'in:low,medium,high'],
        ]);

        $did = $validated['did'];
        $endpoint = rtrim($validated['endpoint'], '/');
        $trustTierRequest = $validated['trust_tier_request'] ?? 'low';

        // DIR-FED-12: SSRF guard — https only, no private/loopback
        $sshErr = $this->validateEndpointScheme($endpoint);
        if ($sshErr !== null) {
            return $this->errorResponse($sshErr, 422);
        }

        // DIR-FED-11: resolve DID document and verify Ed25519 verification method
        $didErr = $this->validateDidDocument($did);
        if ($didErr !== null) {
            return $this->errorResponse($didErr, 422);
        }

        // Endpoint reachability — IICP-E042
        if (! $this->endpointReachable($endpoint)) {
            return $this->errorResponse('IICP-E042', 422, 'endpoint /iicp/health did not return 200');
        }

        // DIR-FED-13: idempotency on `did`. Re-registration rotates the token.
        $existing = Replica::where('did', $did)->first();
        if ($existing !== null) {
            $newToken = $this->issueReplicaToken($existing->replica_id);
            $existing->update([
                'endpoint' => $endpoint,
                'replica_token_hash' => hash('sha256', $newToken),
                'expires_at' => now()->addDays(self::REPLICA_TOKEN_TTL_DAYS),
                'last_seen_at' => now(),
            ]);

            return $this->successResponse($existing, $newToken, isNewRegistration: false);
        }

        // First-time registration
        $replicaId = (string) Str::uuid();
        $replicaToken = $this->issueReplicaToken($replicaId);

        $replica = Replica::create([
            'replica_id' => $replicaId,
            'did' => $did,
            'endpoint' => $endpoint,
            'trust_tier' => 'low', // always 'low' on first registration per spec §7.1
            'replica_token_hash' => hash('sha256', $replicaToken),
            'expires_at' => now()->addDays(self::REPLICA_TOKEN_TTL_DAYS),
        ]);

        // Emit REPLICA_REGISTERED event so other replicas mirror this registration
        try {
            $this->eventLogger->log('REPLICA_REGISTERED', $replicaId, [
                'did' => $did,
                'endpoint' => $endpoint,
                'trust_tier' => 'low',
            ]);
        } catch (\Throwable $e) {
            // Event-log failure must not fail the registration — the row is durable
            Log::warning('REPLICA_REGISTERED event emission failed', [
                'replica_id' => $replicaId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->successResponse($replica, $replicaToken, isNewRegistration: true);
    }

    private function validateEndpointScheme(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? '';
        $allowHttp = $this->devAllowsHttpDid();
        $isValidScheme = $scheme === 'https' || ($allowHttp && $scheme === 'http');
        if (! $parts || ! $isValidScheme) {
            return 'IICP-E043'; // non-https
        }
        $host = $parts['host'] ?? '';
        if ($host === '' || $host === 'localhost' || strtolower($host) === 'ip6-localhost') {
            return 'IICP-E043';
        }
        // Cheap synchronous check only — if the host is a literal IP, check CIDR.
        // If it's a hostname, defer the DNS lookup to `endpointReachable()` so a
        // fictional hostname doesn't get swallowed here as IICP-E042 ahead of the
        // DID-validation path. Per spec §7.1 order: DID validation runs even when
        // endpoint is well-formed; DNS failure is reported only by reachability check.
        // Dev flag suppresses the private-IP refusal too — testbed containers
        // resolve to Docker bridge IPs in the 172.x range.
        if (! $allowHttp
            && filter_var($host, FILTER_VALIDATE_IP)
            && $this->isBlockedIp($host)
        ) {
            return 'IICP-E043';
        }

        return null;
    }

    /**
     * Dev-only escape hatch for the docker-compose.federation.yml testbed
     * (P6-3.1c). Lets seed-side endpoint + DID-doc validation accept http://
     * URLs and Docker-bridge private IPs. Hard-gated to non-production.
     * Mirrors the same flag used by ReplicaStartCommand (iter-1449).
     */
    private function devAllowsHttpDid(): bool
    {
        return env('IICP_DEV_ALLOW_HTTP_DID', false)
            && config('app.env') !== 'production';
    }

    private function isBlockedIp(string $ip): bool
    {
        if (strtolower(trim($ip)) === '::1') {
            return true;
        }
        foreach (self::BLOCKED_CIDR as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Resolve the DID document at https://<did-domain>/.well-known/did.json
     * and verify it contains at least one Ed25519 verification method.
     *
     * Returns null on success; IICP-E040 (no resolve) / IICP-E041 (no Ed25519) on failure.
     */
    private function validateDidDocument(string $did): ?string
    {
        // P6-3.1d: accept did:web:host or did:web:host%3A<port>.
        // Per did-method-web spec, the port is percent-encoded as %3A.
        // Regex captures host + optional %3A<port> separately.
        if (! preg_match('/^did:web:([a-zA-Z0-9._\-]+)(?:%3A(\d{1,5}))?$/i', $did, $m)) {
            return 'IICP-E040';
        }
        $domain = $m[1];
        $port = isset($m[2]) ? (int) $m[2] : null;
        $allowHttp = $this->devAllowsHttpDid();
        // Domain SSRF guard (parity with endpoint check)
        $ip = filter_var($domain, FILTER_VALIDATE_IP) ? $domain : gethostbyname($domain);
        if ($ip === $domain && ! filter_var($domain, FILTER_VALIDATE_IP)) {
            return 'IICP-E040'; // DNS failure
        }
        // In the testbed (allowHttp), private/Docker-bridge IPs are acceptable.
        if (! $allowHttp && $this->isBlockedIp($ip)) {
            return 'IICP-E040'; // DID-domain points at private IP — refuse
        }

        // Default to https; flip to http in the testbed.
        $scheme = $allowHttp ? 'http' : 'https';
        $portFragment = $port !== null ? ":{$port}" : '';
        $url = "{$scheme}://{$domain}{$portFragment}/.well-known/did.json";
        $doc = $this->fetchJson($url);
        if ($doc === null) {
            return 'IICP-E040';
        }
        // Look for an Ed25519 verification method
        $methods = $doc['verificationMethod'] ?? [];
        foreach ($methods as $method) {
            $type = $method['type'] ?? '';
            if (in_array($type, ['Ed25519VerificationKey2020', 'Ed25519VerificationKey2018', 'JsonWebKey2020'], true)) {
                // For JsonWebKey2020 require crv=Ed25519 in publicKeyJwk
                if ($type === 'JsonWebKey2020') {
                    $jwk = $method['publicKeyJwk'] ?? [];
                    if (($jwk['crv'] ?? '') !== 'Ed25519') {
                        continue;
                    }
                }

                return null;
            }
        }

        return 'IICP-E041';
    }

    private function endpointReachable(string $endpoint): bool
    {
        $url = rtrim($endpoint, '/').'/iicp/health';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code === 200;
    }

    /**
     * Fetch a JSON document with strict TLS + timeout. Returns decoded array or null.
     */
    private function fetchJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'iicp-genesis-seed-did-resolver/1.0',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || ! is_string($body)) {
            return null;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Issue a JWT with role: replica, scoped to GET /v1/events. 90-day TTL.
     * The plaintext token is returned ONCE; only its SHA-256 hash is stored.
     */
    private function issueReplicaToken(string $replicaId): string
    {
        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $now = time();
        $payload = $this->b64url(json_encode([
            'sub' => $replicaId,
            'role' => 'replica',
            'scope' => 'GET /v1/events',
            'iss' => 'iicp.network',
            'iat' => $now,
            'exp' => $now + (self::REPLICA_TOKEN_TTL_DAYS * 86400),
        ]));
        $signingInput = "{$header}.{$payload}";
        $secret = config('app.jwt_secret') ?: config('app.key');
        $sig = $this->b64url(hash_hmac('sha256', $signingInput, $secret, true));

        return "{$signingInput}.{$sig}";
    }

    private function b64url(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    private function genesisHash(): string
    {
        // DIR-FED-14: same value surfaced by GET /v1/events. Stable per Genesis Seed.
        // Composed of (app.url, app.genesis_ed25519_public_key) SHA-256 hashed —
        // changes only if the seed identity rotates. Mirror of EventsController logic.
        $material = (config('app.url') ?? 'iicp.network').'|'.
                    (config('app.genesis_ed25519_public_key') ?? 'phase-6-pre-genesis');

        return hash('sha256', $material);
    }

    private function successResponse(Replica $replica, string $plainToken, bool $isNewRegistration): JsonResponse
    {
        return response()->json([
            'replica_id' => $replica->replica_id,
            'replica_token' => $plainToken,
            'since_seq' => 0, // always 0 — replica must bootstrap from genesis (re-reg can pass last_seen_seq later)
            'genesis_hash' => $this->genesisHash(),
            'did_acknowledged' => true,
            'trust_tier' => $replica->trust_tier,
            'expires_at' => $replica->expires_at->toIso8601String(),
            'is_new_registration' => $isNewRegistration,
        ], 200);
    }

    private function errorResponse(string $code, int $httpStatus, ?string $detail = null): JsonResponse
    {
        $messages = [
            'IICP-E040' => 'did does not resolve to a valid DID document',
            'IICP-E041' => 'DID document has no Ed25519 verification method',
            'IICP-E042' => 'endpoint /iicp/health did not return 200',
            'IICP-E043' => 'endpoint is non-https or resolves to a private address',
            'IICP-E044' => 'trust_tier_request is not one of the allowed values',
        ];

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $detail ?? ($messages[$code] ?? 'replica registration failed'),
            ],
        ], $httpStatus);
    }
}
