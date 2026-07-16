<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Services;

/**
 * Issues and verifies Ed25519-signed dispatch route tickets (#612 / DIR-DISPATCH-01).
 *
 * Token format: <b64url_payload>.<sig_hex>
 * Signing message: "iicp:dispatch-route-ticket:v1\n" + b64url_payload
 * Key: IICP_GENESIS_ED25519_SECRET_KEY (same directory signing key advertised by
 * /v1/directory-key). Tickets are short-lived and scoped to one node, one intent,
 * and this directory audience. They never contain task prompts or payloads.
 */
class DispatchRouteTicketService
{
    private const DOMAIN = "iicp:dispatch-route-ticket:v1\n";

    private const TTL_SECONDS = 120;

    public function publicKeyHex(): ?string
    {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }

        $sk = sodium_hex2bin($secretKey);

        return bin2hex(substr($sk, 32, 32));
    }

    /**
     * @return array{token: string, expires_at: int, ticket_id: string}|null
     */
    public function issue(
        string $nodeId,
        string $intent,
        string $audience = 'iicp.directory.dispatch',
        ?string $policyManifestSha256 = null,
    ): ?array {
        $secretKey = $this->secretKey();
        if ($secretKey === null) {
            return null;
        }

        $now = time();
        $exp = $now + self::TTL_SECONDS;
        $ticketId = bin2hex(random_bytes(12));
        $payload = [
            'v' => 1,
            'typ' => 'dispatch-route-ticket',
            'iss' => config('app.url'),
            'aud' => $audience,
            'jti' => $ticketId,
            'node_id' => $nodeId,
            'intent' => $intent,
            'iat' => $now,
            'exp' => $exp,
        ];
        if (is_string($policyManifestSha256) && preg_match('/^[0-9a-f]{64}$/', $policyManifestSha256) === 1) {
            $payload['policy_manifest_sha256'] = $policyManifestSha256;
        }
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $b64Payload = $this->b64url($payloadJson);
        $message = self::DOMAIN.$b64Payload;
        $sigHex = bin2hex(sodium_crypto_sign_detached($message, sodium_hex2bin($secretKey)));

        return [
            'token' => "{$b64Payload}.{$sigHex}",
            'expires_at' => $exp,
            'ticket_id' => $ticketId,
        ];
    }

    public function verify(string $token, string $nodeId, string $intent, string $audience = 'iicp.directory.dispatch'): ?array
    {
        $payload = $this->verifiedPayload($token);
        if ($payload === null) {
            return null;
        }

        if (($payload['typ'] ?? null) !== 'dispatch-route-ticket') {
            return null;
        }
        if (($payload['node_id'] ?? null) !== $nodeId || ($payload['intent'] ?? null) !== $intent) {
            return null;
        }
        if (($payload['aud'] ?? null) !== $audience || time() > (int) ($payload['exp'] ?? 0)) {
            return null;
        }

        return $payload;
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
