<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Issues and verifies Ed25519-signed relay bind tickets (#510 / DIR-RELAY-03).
 *
 * Token format: <b64url_payload>.<sig_hex>
 * Signing message: "iicp:relay-bind-ticket:v1\n" + b64url_payload
 * Key: IICP_GENESIS_ED25519_SECRET_KEY (same directory signing key advertised by
 * /v1/directory-key). Tickets are short-lived and scoped to one worker node_id
 * plus an optional relay audience.
 */
class RelayBindTicketService
{
    private const DOMAIN = "iicp:relay-bind-ticket:v1\n";

    private const TTL_SECONDS = 120; // short bind window; worker can retry

    public function publicKeyHex(): ?string
    {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }
        $sk = sodium_hex2bin($secretKey);

        return bin2hex(substr($sk, 32, 32));
    }

    public function issue(string $workerNodeId, string $relayAudience = '*'): ?array
    {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }

        $now = time();
        $exp = $now + self::TTL_SECONDS;
        $payloadJson = json_encode([
            'v' => 1,
            'typ' => 'relay-bind-ticket',
            'iss' => config('app.url'),
            'sub' => $workerNodeId,
            'aud' => $relayAudience ?: '*',
            'iat' => $now,
            'exp' => $exp,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $b64Payload = $this->b64url($payloadJson);
        $message = self::DOMAIN.$b64Payload;
        $sigHex = bin2hex(sodium_crypto_sign_detached($message, sodium_hex2bin($secretKey)));

        return [
            'token' => "{$b64Payload}.{$sigHex}",
            'expires_at' => $exp,
        ];
    }

    public function verify(string $token, string $workerNodeId, string $relayAudience = '*'): ?array
    {
        $payload = $this->verifiedPayload($token);
        if ($payload === null) {
            return null;
        }

        return $this->ticketMatches($payload, $workerNodeId, $relayAudience) ? $payload : null;
    }

    private function verifiedPayload(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$b64Payload, $sigHex] = $parts;

        if (! $this->signatureMatches($b64Payload, $sigHex)) {
            return null;
        }

        $payload = json_decode(base64_decode($this->b64pad($b64Payload)), associative: true);

        return is_array($payload) ? $payload : null;
    }

    private function signatureMatches(string $b64Payload, string $sigHex): bool
    {
        $publicKeyHex = $this->publicKeyHex();
        if ($publicKeyHex === null || strlen($sigHex) !== 128) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                sodium_hex2bin($sigHex),
                self::DOMAIN.$b64Payload,
                sodium_hex2bin($publicKeyHex)
            );
        } catch (\SodiumException) {
            return false;
        }
    }

    private function ticketMatches(array $payload, string $workerNodeId, string $relayAudience): bool
    {
        if (! is_array($payload) || ($payload['typ'] ?? null) !== 'relay-bind-ticket') {
            return false;
        }
        if (($payload['sub'] ?? null) !== $workerNodeId || time() > (int) ($payload['exp'] ?? 0)) {
            return false;
        }
        $aud = (string) ($payload['aud'] ?? '');

        return $aud === '*' || $aud === $relayAudience;
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
