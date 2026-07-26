<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Operator;
use App\Services\OperatorDelegationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class NodeRegistrationPersistenceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    public function test_recovery_reuses_node_and_replaces_capabilities_and_availability(): void
    {
        $nodeId = (string) Str::uuid();
        $first = $this->postJson('/api/v1/register', $this->payload($nodeId, 'model-a', '08:00'))
            ->assertCreated()
            ->assertJsonPath('recovered', false);

        $this->postJson('/api/v1/register', [
            ...$this->payload($nodeId, 'model-b', '09:00'),
            'current_node_token' => $first->json('node_token'),
        ])->assertCreated()
            ->assertJsonPath('node_id', $nodeId)
            ->assertJsonPath('recovered', true);

        $node = Node::findOrFail($nodeId);
        $this->assertSame(['model-b'], $node->capabilities()->firstOrFail()->models);
        $this->assertSame('09:00', $node->availabilityWindows()->firstOrFail()->start_time);
        $this->assertDatabaseCount('nodes', 1);
        $this->assertDatabaseCount('capabilities', 1);
        $this->assertDatabaseCount('availability_windows', 1);
    }

    public function test_operator_rejection_rolls_back_node_and_relation_writes(): void
    {
        $nodeId = (string) Str::uuid();
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $notAfter = time() + 3600;
        $signature = base64_encode(sodium_crypto_sign_detached(
            OperatorDelegationVerifier::canonicalBytes($nodeId, $public, $notAfter),
            sodium_crypto_sign_secretkey($keypair),
        ));
        Operator::create([
            'operator_pubkey' => $public,
            'identity_status' => Operator::IDENTITY_REVOKED,
        ]);

        $this->postJson('/api/v1/register', [
            ...$this->payload($nodeId, 'model-a', '08:00'),
            'operator_delegation' => [
                'node_id' => $nodeId,
                'operator_pub' => $public,
                'not_after' => $notAfter,
                'sig' => $signature,
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('nodes', ['id' => $nodeId]);
        $this->assertDatabaseCount('capabilities', 0);
        $this->assertDatabaseCount('availability_windows', 0);
    }

    private function payload(string $nodeId, string $model, string $start): array
    {
        return [
            'node_id' => $nodeId,
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => [$model],
                'max_tokens' => 4096,
            ]],
            'availability' => [[
                'start' => $start,
                'end' => '17:00',
                'share' => 1.0,
            ]],
            'limits' => [
                'max_concurrent' => 4,
                'tokens_per_min' => 10000,
            ],
        ];
    }
}
