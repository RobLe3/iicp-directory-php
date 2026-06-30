<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use App\Services\FounderLockinDetector;
use App\Services\JwtService;
use App\Services\OperatorDelegationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #310 — THE full-chain end-to-end (the path that silently never worked in prod: a real
 * operator-delegation register → operator_pubkey binds → operators row → founder detector →
 * #1 → public leaderboard → operator wallet). Exercises the *actual* register endpoint +
 * OperatorDelegationVerifier (the code that 500'd on prod) feeding the whole recognition stack,
 * not Operator::create fixtures. If any link breaks, this test breaks.
 */
class FounderRecognitionE2ETest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'endpoint' => 'https://node.example.com',
        'region' => 'eu-central',
        'capabilities' => [
            ['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['llama-3-8b'], 'max_tokens' => 4096],
        ],
        'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
    ];

    public function test_register_delegation_binds_then_detector_makes_founder_one_then_leaderboard_and_wallet(): void
    {
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
        $nowMs = (int) (microtime(true) * 1000);
        Config::set('app.iicp_genesis_ms', $nowMs - 86400_000);

        // 1. A real ed25519 operator key signs a real delegation for this node.
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $notAfter = time() + 3600;
        $msg = OperatorDelegationVerifier::canonicalBytes($nodeId, $pub, $notAfter);
        $sig = base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp)));
        Config::set('app.iicp_founder_one_pubkey', $pub); // this operator is the reserved founder

        // 2. Register through the REAL endpoint with the delegation + a public display_name.
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'operator_display_name' => 'ZeroKelvinMoralist',
            'operator_delegation' => ['node_id' => $nodeId, 'operator_pub' => $pub, 'not_after' => $notAfter, 'sig' => $sig],
        ]);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        // 3. The delegation verified → node bound + operators row created. THIS is what 500'd in prod.
        $node = Node::find($nodeId);
        $this->assertTrue((bool) $node->operator_verified, 'delegation must verify and bind the operator');
        $this->assertSame($pub, $node->operator_pubkey);
        $op = Operator::where('operator_pubkey', $pub)->first();
        $this->assertNotNull($op, 'operators row must be created on a verified delegation');
        $this->assertSame('ZeroKelvinMoralist', $op->display_name);
        $this->assertNull($op->ordinal, 'no ordinal until the detector runs');

        // 4. Founder detector → this operator becomes #1.
        app(FounderLockinDetector::class)->scan();
        $this->assertSame(1, Operator::where('operator_pubkey', $pub)->first()->ordinal);

        // 5. Public leaderboard shows #1 by display_name, and NEVER the key.
        $resp = $this->getJson('/api/v1/leaderboards/founders')->assertStatus(200)
            ->assertJsonPath('entries.0.ordinal', 1)
            ->assertJsonPath('entries.0.display_name', 'ZeroKelvinMoralist');
        $this->assertStringNotContainsString($pub, $resp->getContent());

        // 6. Operator wallet: give the bound node a balance → summary aggregates it by operator_id.
        $node->update(['credit_balance' => 5.0]);
        $jwt = app(JwtService::class)->issue($nodeId);
        $this->withToken($jwt)->getJson('/api/v1/credits/summary')->assertStatus(200)
            ->assertJsonPath('operator_wallet.total_balance', 5)
            ->assertJsonPath('operator_wallet.node_count', 1);
    }
}
