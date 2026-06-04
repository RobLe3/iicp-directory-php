<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Minimal HS256 JWT issuer/verifier for IICP node tokens (ADR-006).
 *
 * WHY a hand-rolled JWT rather than a library: the only JWT operations needed are issue (on
 * registration) and verify (on every authenticated request). A full JWT library adds 50+ KB of
 * dependency surface for two methods. The implementation follows RFC 7519 exactly:
 * base64url(header) + "." + base64url(payload) + "." + base64url(HMAC-SHA256(header.payload)).
 *
 * WHY HS256 (symmetric) rather than RS256 (asymmetric): node tokens are only verified by the
 * directory itself (not by third parties). Symmetric HMAC is adequate when the verifier is the
 * same party that issued the token — and is significantly cheaper on shared-hosting PHP.
 *
 * WHY TTL_SECONDS = 3600 (1h): heartbeat interval is 30s; a 1h token gives the adapter
 * ~120 heartbeats before it needs to re-authenticate. Short enough to limit exposure on key
 * rotation; long enough to not require re-registration on transient clock drift.
 *
 * Secret: derived from Laravel's APP_KEY (may be base64-encoded with 'base64:' prefix per
 * Laravel convention — b64pad() handles the unpadded base64url round-trip).
 *
 * Spec: spec/iicp-dir.md §register §node_token. ADR: ADR-006 (node token auth model).
 */
class JwtService
{
    private const TTL_SECONDS = 3600;

    public function issue(string $nodeId): string
    {
        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $now = time();
        $payload = $this->b64url(json_encode([
            'sub' => $nodeId,
            'iss' => 'iicp.network',
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
        ]));

        $signingInput = "{$header}.{$payload}";
        $sig = $this->b64url(hash_hmac('sha256', $signingInput, $this->secret(), true));

        return "{$signingInput}.{$sig}";
    }

    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $sig] = $parts;

        $expected = $this->b64url(hash_hmac('sha256', "{$header}.{$payload}", $this->secret(), true));
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $claims = json_decode(base64_decode($this->b64pad($payload)), associative: true);
        if (! is_array($claims) || ! isset($claims['exp']) || time() > $claims['exp']) {
            return null;
        }

        return $claims;
    }

    /**
     * Returns true iff the token has a valid signature but has expired.
     * Used by middleware to distinguish token_expired from unauthorized.
     */
    public function isExpiredJwt(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$header, $payload, $sig] = $parts;

        $expected = $this->b64url(hash_hmac('sha256', "{$header}.{$payload}", $this->secret(), true));
        if (! hash_equals($expected, $sig)) {
            return false; // invalid signature — not an expiry case
        }

        $claims = json_decode(base64_decode($this->b64pad($payload)), associative: true);

        return is_array($claims) && isset($claims['exp']) && time() > $claims['exp'];
    }

    private function secret(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            return (string) base64_decode(substr($key, 7));
        }

        return $key;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64pad(string $b64url): string
    {
        $padded = strtr($b64url, '-_', '+/');
        $remainder = strlen($padded) % 4;

        return $remainder ? str_pad($padded, strlen($padded) + (4 - $remainder), '=') : $padded;
    }
}
