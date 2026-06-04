<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetch + cache the Genesis Seed's DID document and extract its Ed25519
 * verification key. Used by replicas during P6-1.3b-iv signature verification.
 *
 * The seed's public key MUST come from a DID document over HTTPS — pin-on-
 * first-use means a replica that's been bootstrapped trusts a specific key
 * for the lifetime of its cache. If the seed rotates keys, the operator
 * must clear the cache (and re-verify).
 *
 * Spec: S.13 §4 (DID document), §3.4 (signing input).
 * Charter: P6-1.3b-iv.
 */
class SeedDidResolver
{
    public const CACHE_KEY_PREFIX = 'iicp:seed_did_pubkey:';

    public const CACHE_TTL_SECONDS = 86400; // 24h

    /**
     * Returns the 32-byte raw Ed25519 public key for the seed's first
     * Ed25519 verification method, or null if unavailable.
     */
    public function publicKey(string $seedUrl): ?string
    {
        $key = self::CACHE_KEY_PREFIX.md5($seedUrl);

        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($seedUrl): ?string {
            return $this->fetchAndExtract($seedUrl);
        });
    }

    public function forget(string $seedUrl): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX.md5($seedUrl));
    }

    private function fetchAndExtract(string $seedUrl): ?string
    {
        // P6-3.1d: seedUrl may carry a port (e.g. http://seed-directory:8080
        // in the federation testbed). rtrim + append is port-safe.
        $url = rtrim($seedUrl, '/').'/.well-known/did.json';
        $resp = Http::timeout(10)->acceptJson()->get($url);
        if (! $resp->successful()) {
            return null;
        }
        $doc = $resp->json();
        $methods = $doc['verificationMethod'] ?? [];
        foreach ($methods as $m) {
            $jwk = $m['publicKeyJwk'] ?? null;
            if (! $jwk) {
                continue;
            }
            if (($jwk['kty'] ?? null) !== 'OKP' || ($jwk['crv'] ?? null) !== 'Ed25519') {
                continue;
            }
            $x = $jwk['x'] ?? '';
            if ($x === '' || $x === 'GENESIS_KEY_PENDING') {
                continue;
            }
            $padded = str_pad(strtr($x, '-_', '+/'), strlen($x) + (4 - strlen($x) % 4) % 4, '=');
            $raw = base64_decode($padded, true);
            if ($raw === false || strlen($raw) !== 32) {
                continue;
            }

            return $raw;
        }

        return null;
    }
}
