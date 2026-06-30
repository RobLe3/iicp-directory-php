<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\ConsumerTokenService;
use Tests\TestCase;

/**
 * Behavior tests for ConsumerTokenService key-handling (#507 fix).
 *
 * These tests fail without the fix:
 * - publicKeyHex() used sodium_crypto_sign_publickey() on the 64-byte secret key (not a keypair)
 *   → SodiumException → controller returned HTTP 500 instead of HTTP 200/503.
 * - issue() used sodium_crypto_sign_secretkey() on the 64-byte secret key → same SodiumException.
 *
 * The genesis key is stored as SODIUM_CRYPTO_SIGN_SECRETKEYBYTES (64 bytes, 128 hex):
 * seed(32) || public_key(32). The fix extracts the public key via substr($sk,32,32) and
 * uses the secret key directly with sodium_crypto_sign_detached, matching NodeEventLogger.
 */
class ConsumerTokenServiceTest extends TestCase
{
    /** Returns [public_key_bytes, secret_key_hex] using the libsodium 64-byte secret key format. */
    private function makeKey(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $pk = sodium_crypto_sign_publickey($keypair);
        $sk = sodium_crypto_sign_secretkey($keypair);

        return [$pk, bin2hex($sk)];
    }

    public function test_public_key_hex_returns_correct_public_key(): void
    {
        [$expectedPk, $skHex] = $this->makeKey();
        config(['app.genesis_ed25519_secret_key' => $skHex]);

        $service = new ConsumerTokenService;
        $hexResult = $service->publicKeyHex();

        $this->assertNotNull($hexResult, 'publicKeyHex() must not return null when key is configured');
        $this->assertSame(64, strlen($hexResult), 'Public key hex must be 64 chars (32 bytes)');
        $this->assertSame(bin2hex($expectedPk), $hexResult, 'Extracted public key must match keypair public key');
    }

    public function test_public_key_hex_returns_null_when_unconfigured(): void
    {
        config(['app.genesis_ed25519_secret_key' => null]);

        $service = new ConsumerTokenService;

        $this->assertNull($service->publicKeyHex());
    }

    public function test_issue_produces_verifiable_token(): void
    {
        [$expectedPk, $skHex] = $this->makeKey();
        config(['app.genesis_ed25519_secret_key' => $skHex]);

        $service = new ConsumerTokenService;
        $result = $service->issue('caller-id', 'target-id', 'urn:iicp:intent:llm:chat:v1');

        $this->assertNotNull($result, 'issue() must not return null when key is configured');
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);

        // Token must verify successfully (proves the secret key is used correctly).
        $verified = $service->verify($result['token']);
        $this->assertNotNull($verified, 'Issued token must verify with the same service');
        $this->assertSame('caller-id', $verified['sub']);
        $this->assertSame('target-id', $verified['aud']);
    }

    public function test_verify_uses_extracted_public_key_correctly(): void
    {
        [$expectedPk, $skHex] = $this->makeKey();
        config(['app.genesis_ed25519_secret_key' => $skHex]);

        $service = new ConsumerTokenService;

        // Manually craft a token signed with the raw secret key (64 bytes).
        $sk = sodium_hex2bin($skHex);
        $payloadJson = json_encode([
            'v' => 1, 'iss' => 'https://test', 'sub' => 'a', 'aud' => 'b',
            'intent' => 'urn:iicp:intent:llm:chat:v1', 'iat' => time(), 'exp' => time() + 300,
        ]);
        $b64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $message = "iicp:consumer-token:v1\n".$b64;
        $sig = bin2hex(sodium_crypto_sign_detached($message, $sk));
        $token = "{$b64}.{$sig}";

        $verified = $service->verify($token);
        $this->assertNotNull($verified, 'Token signed with raw secret key must verify');
        $this->assertSame('a', $verified['sub']);
    }
}
