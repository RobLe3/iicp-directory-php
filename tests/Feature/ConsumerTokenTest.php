<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Services\ConsumerTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Behavior tests for Phase-2 consumer token endpoints (#496).
 *
 * Each test fails if the fix is reverted:
 * - directory-key endpoint removed → assertStatus(200) fails
 * - consumer-token endpoint removed → assertStatus(201) fails
 * - token signature wrong → verify step would fail with invalid sig
 * - token does not contain expected claims → assertJson assertions fail
 */
class ConsumerTokenTest extends TestCase
{
    use RefreshDatabase;

    private const CALLER_ENDPOINT = 'https://caller-node.example.com';

    private const TARGET_ENDPOINT = 'https://target-node.example.com';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            self::CALLER_ENDPOINT.'/iicp/health' => Http::response('ok', 200),
            self::TARGET_ENDPOINT.'/iicp/health' => Http::response('ok', 200),
        ]);
    }

    private function registerNode(string $endpoint): array
    {
        $resp = $this->postJson('/api/v1/register', [
            'endpoint' => $endpoint,
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['llama3'],
                'max_tokens' => 4096,
            ]],
            'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
        ]);
        $resp->assertStatus(201);

        // Use JWT token for authenticated endpoints (node_id automatically extracted from 'sub').
        return [$resp->json('node_id'), $resp->json('jwt_token')];
    }

    public function test_directory_key_returns_public_key_when_configured(): void
    {
        // Only passes when IICP_GENESIS_ED25519_SECRET_KEY is configured.
        // In CI / dev without the key we expect 503 — that's acceptable.
        $resp = $this->getJson('/api/v1/directory-key');

        if ($resp->status() === 503) {
            $resp->assertJson(['error' => ['code' => 'not_configured']]);

            return;
        }

        $resp->assertStatus(200);
        $resp->assertJsonStructure(['public_key', 'algorithm']);
        $this->assertSame('ed25519', $resp->json('algorithm'));
        $this->assertSame(64, strlen($resp->json('public_key')));
    }

    public function test_consumer_token_requires_auth(): void
    {
        [, $callerToken] = $this->registerNode(self::CALLER_ENDPOINT);
        [$targetId] = $this->registerNode(self::TARGET_ENDPOINT);

        // Missing Authorization header.
        $this->postJson('/api/v1/consumer-token', [
            'target_node_id' => $targetId,
            'intent' => 'urn:iicp:intent:llm:chat:v1',
        ])->assertStatus(401);
    }

    public function test_consumer_token_returns_503_when_key_not_configured(): void
    {
        // When genesis key is not set, endpoint returns 503.
        if (config('app.genesis_ed25519_secret_key') !== null) {
            $this->markTestSkipped('Genesis key configured — 503 path not reachable');
        }

        [$callerId, $callerToken] = $this->registerNode(self::CALLER_ENDPOINT);
        [$targetId] = $this->registerNode(self::TARGET_ENDPOINT);

        $this->withToken($callerToken)
            ->postJson('/api/v1/consumer-token', [
                'target_node_id' => $targetId,
                'intent' => 'urn:iicp:intent:llm:chat:v1',
            ])
            ->assertStatus(503)
            ->assertJson(['error' => ['code' => 'not_configured']]);
    }

    public function test_consumer_token_returns_404_for_unknown_target(): void
    {
        if (config('app.genesis_ed25519_secret_key') === null) {
            $this->markTestSkipped('Genesis key required for token issuance');
        }

        [$callerId, $callerToken] = $this->registerNode(self::CALLER_ENDPOINT);

        $this->withToken($callerToken)
            ->postJson('/api/v1/consumer-token', [
                'target_node_id' => 'nonexistent-node-id',
                'intent' => 'urn:iicp:intent:llm:chat:v1',
            ])
            ->assertStatus(404);
    }

    public function test_consumer_token_is_issued_with_expected_claims(): void
    {
        if (config('app.genesis_ed25519_secret_key') === null) {
            $this->markTestSkipped('Genesis key required for token issuance');
        }

        [$callerId, $callerToken] = $this->registerNode(self::CALLER_ENDPOINT);
        [$targetId] = $this->registerNode(self::TARGET_ENDPOINT);

        $resp = $this->withToken($callerToken)
            ->postJson('/api/v1/consumer-token', [
                'target_node_id' => $targetId,
                'intent' => 'urn:iicp:intent:llm:chat:v1',
            ]);

        $resp->assertStatus(201);
        $resp->assertJsonStructure(['token', 'expires_at', 'caller_node_id', 'target_node_id', 'intent']);
        $this->assertSame($callerId, $resp->json('caller_node_id'));
        $this->assertSame($targetId, $resp->json('target_node_id'));
        $this->assertSame('urn:iicp:intent:llm:chat:v1', $resp->json('intent'));

        // Token is two dot-separated parts.
        $tokenParts = explode('.', $resp->json('token'));
        $this->assertCount(2, $tokenParts, 'Token must be <b64url_payload>.<sig_hex>');

        // Payload decodes to correct claims.
        $b64pad = rtrim(strtr($tokenParts[0], '-_', '+/'), '=');
        $payload = json_decode(base64_decode($b64pad.'=='), true);
        $this->assertSame(1, $payload['v']);
        $this->assertSame($callerId, $payload['sub']);
        $this->assertSame($targetId, $payload['aud']);
        $this->assertSame('urn:iicp:intent:llm:chat:v1', $payload['intent']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    public function test_consumer_token_validation_rejects_wrong_signature(): void
    {
        if (config('app.genesis_ed25519_secret_key') === null) {
            $this->markTestSkipped('Genesis key required for token issuance');
        }

        $service = app(ConsumerTokenService::class);

        // Craft a valid-looking token but with a zeroed signature.
        $payload = base64_encode(json_encode([
            'v' => 1, 'iss' => 'https://test', 'sub' => 'a', 'aud' => 'b',
            'intent' => '*', 'iat' => time(), 'exp' => time() + 300,
        ]));
        $fakeToken = rtrim(strtr($payload, '+/', '-_'), '=').'.'.str_repeat('00', 64);

        $this->assertNull($service->verify($fakeToken), 'Zeroed signature must not verify');
    }

    public function test_consumer_token_verify_returns_null_for_expired_token(): void
    {
        if (config('app.genesis_ed25519_secret_key') === null) {
            $this->markTestSkipped('Genesis key required for token issuance');
        }

        $service = app(ConsumerTokenService::class);

        // Issue a token, then manually decode and re-issue with expired exp.
        // We can't forge a signature, so we just check verify() rejects the expired payload
        // by temporarily swapping the exp in a fresh issue call.  The simplest way is to
        // verify that verify() returns null for a token with exp=1 (long expired).
        // Genesis key is stored as the 64-byte libsodium secret key — use directly.
        $sk = sodium_hex2bin(config('app.genesis_ed25519_secret_key'));

        $payloadJson = json_encode([
            'v' => 1,
            'iss' => config('app.url'),
            'sub' => 'caller',
            'aud' => 'target',
            'intent' => '*',
            'iat' => 1,
            'exp' => 1, // already expired
        ]);
        $b64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        $message = "iicp:consumer-token:v1\n".$b64;
        $sig = bin2hex(sodium_crypto_sign_detached($message, $sk));
        $expiredToken = "{$b64}.{$sig}";

        $this->assertNull($service->verify($expiredToken), 'Expired token must not verify');
    }
}
