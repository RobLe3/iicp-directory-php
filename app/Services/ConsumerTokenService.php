<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Issues and verifies Ed25519-signed consumer tokens for third-party task authorization.
 *
 * Token format: <b64url_payload>.<sig_hex>
 * Signing message: "iicp:consumer-token:v1\n" + b64url_payload
 * Key: IICP_GENESIS_ED25519_SECRET_KEY (genesis keypair, 128 hex = 64 bytes)
 *
 * Spec: spec/iicp-dir.md §3.10 + spec/iicp-core.md §3.1 errata v1.5.2 (#496)
 */
class ConsumerTokenService
{
    private const DOMAIN = "iicp:consumer-token:v1\n";

    private const TTL_SECONDS = 300; // 5-minute token lifetime

    /** Returns the directory's Ed25519 public key as 64-char hex, or null if unconfigured. */
    public function publicKeyHex(): ?string
    {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }
        // Genesis key is stored as the 64-byte libsodium secret key (seed||pubkey), not a full 96-byte keypair.
        // sodium_crypto_sign_publickey() expects a keypair — use substr instead.
        $sk = sodium_hex2bin($secretKey);

        return bin2hex(substr($sk, 32, 32));
    }

    /**
     * Issues a consumer token authorizing $callerNodeId to call $targetNodeId for $intent.
     *
     * Returns ['token' => '...', 'expires_at' => <unix_sec>] or null if unconfigured.
     */
    public function issue(string $callerNodeId, string $targetNodeId, string $intent): ?array
    {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }

        $now = time();
        $exp = $now + self::TTL_SECONDS;

        $payloadJson = json_encode([
            'v' => 1,
            'iss' => config('app.url'),
            'sub' => $callerNodeId,
            'aud' => $targetNodeId,
            'intent' => $intent,
            'iat' => $now,
            'exp' => $exp,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $b64Payload = $this->b64url($payloadJson);
        $message = self::DOMAIN.$b64Payload;

        $sk = sodium_hex2bin($secretKey);
        $sigBytes = sodium_crypto_sign_detached($message, $sk);
        $sigHex = bin2hex($sigBytes);

        return [
            'token' => "{$b64Payload}.{$sigHex}",
            'expires_at' => $exp,
        ];
    }

    /**
     * Verifies a consumer token against the directory's public key.
     * Returns the decoded payload array on success, null on failure.
     */
    public function verify(string $token): ?array
    {
        $publicKeyHex = $this->publicKeyHex();
        if ($publicKeyHex === null) {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$b64Payload, $sigHex] = $parts;
        if (strlen($sigHex) !== 128) {
            return null;
        }

        $message = self::DOMAIN.$b64Payload;
        try {
            $sigBytes = sodium_hex2bin($sigHex);
            $pubKey = sodium_hex2bin($publicKeyHex);
            $valid = sodium_crypto_sign_verify_detached($sigBytes, $message, $pubKey);
        } catch (\SodiumException) {
            return null;
        }

        if (! $valid) {
            return null;
        }

        $payloadJson = base64_decode($this->b64pad($b64Payload));
        $payload = json_decode($payloadJson, associative: true);
        if (! is_array($payload) || ! isset($payload['exp']) || time() > $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private function secretKey(): ?string
    {
        $hexKey = config('app.genesis_ed25519_secret_key');

        return (is_string($hexKey) && strlen($hexKey) === 128) ? $hexKey : null;
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
