<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Profile-aware HS256 JWT issuer/verifier for IICP node and replica tokens.
 *
 * WHY a hand-rolled JWT rather than a library: the directory only issues and verifies two
 * fixed HS256 profiles. A full JWT library adds dependency surface for this narrow use. The
 * implementation validates the fixed header and claims before accepting either profile:
 * base64url(header) + "." + base64url(payload) + "." + base64url(HMAC-SHA256(header.payload)).
 *
 * WHY HS256 (symmetric) rather than RS256 (asymmetric): node tokens are only verified by the
 * directory itself (not by third parties). Symmetric HMAC is adequate when the verifier is the
 * same party that issued the token — and is significantly cheaper on shared-hosting PHP.
 *
 * Node TTL is 3600 seconds (1h): heartbeat interval is 30s; a 1h token gives the adapter
 * ~120 heartbeats before it needs to re-authenticate. Short enough to limit exposure on key
 * rotation; long enough to not require re-registration on transient clock drift.
 *
 * Secret: derived from Laravel's APP_KEY, including strict decoding of the standard
 * `base64:` representation. Both profiles use the same normalized key path.
 *
 * Spec: spec/iicp-dir.md §register §node_token. ADR: ADR-006 (node token auth model).
 */
class JwtService
{
    public const REPLICA_TTL_SECONDS = 90 * 86400;

    private const NODE_TTL_SECONDS = 3600;

    private const ISSUER = 'iicp.network';

    private const PROFILE_NODE = 'node';

    private const PROFILE_REPLICA = 'replica';

    public function issueNode(string $nodeId): string
    {
        return $this->issueProfile(self::PROFILE_NODE, $nodeId, self::NODE_TTL_SECONDS);
    }

    public function issueReplica(string $replicaId): string
    {
        return $this->issueProfile(self::PROFILE_REPLICA, $replicaId, self::REPLICA_TTL_SECONDS);
    }

    public function verifyNode(string $token): JwtVerificationResult
    {
        return $this->verifyProfile($token, self::PROFILE_NODE);
    }

    public function verifyReplica(string $token): JwtVerificationResult
    {
        return $this->verifyProfile($token, self::PROFILE_REPLICA);
    }

    private function issueProfile(string $profile, string $subject, int $ttl): string
    {
        if ($subject === '') {
            throw new \InvalidArgumentException('JWT subject must not be empty.');
        }

        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $now = time();
        $claims = [
            'sub' => $subject,
            'iss' => self::ISSUER,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];
        if ($profile === self::PROFILE_REPLICA) {
            $claims['role'] = 'replica';
            $claims['scope'] = 'GET /v1/events';
            // Re-registration must rotate the token even inside the same second.
            $claims['jti'] = bin2hex(random_bytes(16));
        }
        $payload = $this->b64url(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = "{$header}.{$payload}";
        $signature = $this->b64url(hash_hmac('sha256', $signingInput, $this->secret(), true));

        return "{$signingInput}.{$signature}";
    }

    private function verifyProfile(string $token, string $profile): JwtVerificationResult
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return JwtVerificationResult::invalid('malformed');
        }

        [$encodedHeader, $encodedClaims, $encodedSignature] = $parts;
        $header = $this->decodeObject($encodedHeader);
        $claims = $this->decodeObject($encodedClaims);
        $signature = $this->decode($encodedSignature);
        if ($header === null || $claims === null || $signature === null || strlen($signature) !== 32) {
            return JwtVerificationResult::invalid('malformed');
        }
        if (($header['alg'] ?? null) !== 'HS256'
            || ($header['typ'] ?? null) !== 'JWT'
            || array_key_exists('crit', $header)
        ) {
            return JwtVerificationResult::invalid('invalid_header');
        }

        $expected = hash_hmac(
            'sha256',
            "{$encodedHeader}.{$encodedClaims}",
            $this->secret(),
            true,
        );
        if (! hash_equals($expected, $signature)) {
            return JwtVerificationResult::invalid('invalid_signature');
        }
        if (! $this->hasCommonClaims($claims)) {
            return JwtVerificationResult::invalid('invalid_claims');
        }
        if (! $this->matchesProfile($claims, $profile)) {
            return JwtVerificationResult::invalid('invalid_profile');
        }
        if (time() > $claims['exp']) {
            return JwtVerificationResult::invalid('expired');
        }

        return JwtVerificationResult::valid($claims);
    }

    private function hasCommonClaims(array $claims): bool
    {
        return isset($claims['sub'], $claims['iss'], $claims['iat'], $claims['exp'])
            && is_string($claims['sub'])
            && $claims['sub'] !== ''
            && $claims['iss'] === self::ISSUER
            && is_int($claims['iat'])
            && is_int($claims['exp'])
            && $claims['exp'] >= $claims['iat'];
    }

    private function matchesProfile(array $claims, string $profile): bool
    {
        if ($profile === self::PROFILE_NODE) {
            return ! array_key_exists('role', $claims)
                && ! array_key_exists('scope', $claims);
        }

        return ($claims['role'] ?? null) === 'replica'
            && ($claims['scope'] ?? null) === 'GET /v1/events';
    }

    private function secret(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new \LogicException('A valid application key is required for JWT operations.');
            }

            return $decoded;
        }
        if ($key === '') {
            throw new \LogicException('An application key is required for JWT operations.');
        }

        return $key;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function decodeObject(string $encoded): ?array
    {
        $decoded = $this->decode($encoded);
        if ($decoded === null) {
            return null;
        }

        try {
            $value = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    private function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }

        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded = str_pad($padded, strlen($padded) + (4 - $remainder), '=');
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
