<?php

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\Replica;
use App\Services\JwtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SnapshotEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $validToken;

    private string $expiredToken;

    private string $invalidRoleToken;

    protected function setUp(): void
    {
        parent::setUp();

        $replicaId = 'rep-'.str_repeat('a', 32);
        $this->validToken = app(JwtService::class)->issueReplica($replicaId);
        $this->expiredToken = $this->issueJwt([
            'sub' => $replicaId,
            'role' => 'replica',
            'scope' => 'GET /v1/events',
            'iss' => 'iicp.network',
            'iat' => time() - 7200,
            'exp' => time() - 60,
        ]);
        $this->invalidRoleToken = app(JwtService::class)->issueNode('some-node-id');

        Replica::create([
            'replica_id' => $replicaId,
            'did' => 'did:web:r1.test',
            'endpoint' => 'https://r1.test',
            'trust_tier' => 'verified',
            'replica_token_hash' => hash('sha256', $this->validToken),
            'expires_at' => Carbon::now()->addDays(90),
        ]);
    }

    private function issueJwt(array $claims): string
    {
        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64url(json_encode($claims));
        $signingInput = "{$header}.{$payload}";
        $secret = config('app.key');
        if (str_starts_with($secret, 'base64:')) {
            $secret = base64_decode(substr($secret, 7));
        }
        $sig = $this->b64url(hash_hmac('sha256', $signingInput, $secret, true));

        return "{$signingInput}.{$sig}";
    }

    private function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function test_returns_401_when_no_auth(): void
    {
        $this->getJson('/api/v1/snapshot')->assertStatus(401);
    }

    public function test_returns_401_with_invalid_token(): void
    {
        $resp = $this->withHeaders(['Authorization' => 'Bearer not.a.jwt'])->getJson('/api/v1/snapshot');
        $resp->assertStatus(401);
    }

    public function test_returns_token_expired_for_expired_jwt(): void
    {
        $resp = $this->withHeaders(['Authorization' => "Bearer {$this->expiredToken}"])->getJson('/api/v1/snapshot');
        $resp->assertStatus(401);
        $resp->assertJsonPath('error.code', 'token_expired');
    }

    public function test_returns_401_for_node_role_token(): void
    {
        $resp = $this->withHeaders(['Authorization' => "Bearer {$this->invalidRoleToken}"])->getJson('/api/v1/snapshot');
        $resp->assertStatus(401);
        $resp->assertJsonPath('error.message', 'Invalid replica token');
    }

    public function test_returns_snapshot_for_authenticated_replica(): void
    {
        Node::create([
            'id' => '550e8400-e29b-41d4-a716-446655440001',
            'endpoint' => 'https://node-a.test', 'region' => 'eu-central',
            'available' => true, 'load' => 0.3, 'active_jobs' => 0,
            'node_token_hash' => 'h', 'max_concurrent' => 4, 'tokens_per_min' => 10000,
            'reputation_score' => 0.85, 'credit_balance' => 12.5,
            'allow_remote_inference' => true,
            'last_seen' => now(),
        ]);
        Capability::create([
            'node_id' => '550e8400-e29b-41d4-a716-446655440001',
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'models' => ['gpt-test'], 'max_tokens' => 4096,
        ]);
        NodeEvent::create([
            'event_id' => 'evt-1', 'seq' => 42, 'event_type' => 'REGISTER',
            'node_id' => '550e8400-e29b-41d4-a716-446655440001',
            'ts_ms' => (int) (microtime(true) * 1000),
            'payload' => ['endpoint' => 'https://node-a.test'],
        ]);

        $resp = $this->withHeaders(['Authorization' => "Bearer {$this->validToken}"])->getJson('/api/v1/snapshot');

        $resp->assertStatus(200);
        $resp->assertJsonPath('schema_version', 'v0.3.0');
        $resp->assertJsonPath('snapshot_seq', 42);
        $resp->assertJsonStructure([
            'schema_version', 'snapshot_seq', 'snapshot_ts_ms', 'genesis_hash',
            'nodes' => [['node_id', 'endpoint', 'region', 'available', 'reputation_score',
                'credit_balance', 'cip_policy', 'pricing']],
            'capabilities' => [['node_id', 'intent', 'models', 'max_tokens']],
        ]);
        $resp->assertJsonPath('nodes.0.node_id', '550e8400-e29b-41d4-a716-446655440001');
        $resp->assertJsonPath('nodes.0.reputation_score', 0.85);
        $resp->assertJsonPath('nodes.0.cip_policy.allow_remote_inference', true);
        $resp->assertJsonPath('capabilities.0.intent', 'urn:iicp:intent:llm:chat:v1');
    }

    public function test_snapshot_seq_zero_when_no_events(): void
    {
        $resp = $this->withHeaders(['Authorization' => "Bearer {$this->validToken}"])->getJson('/api/v1/snapshot');
        $resp->assertStatus(200);
        $resp->assertJsonPath('snapshot_seq', 0);
        $resp->assertJsonPath('genesis_hash', null);
        $resp->assertJsonCount(0, 'nodes');
    }

    public function test_genesis_hash_matches_events_endpoint(): void
    {
        NodeEvent::create([
            'event_id' => 'evt-genesis', 'seq' => 1, 'event_type' => 'REGISTER',
            'node_id' => '550e8400-e29b-41d4-a716-446655440009',
            'ts_ms' => (int) (microtime(true) * 1000),
            'payload' => ['endpoint' => 'https://genesis.test'],
        ]);

        $snap = $this->withHeaders(['Authorization' => "Bearer {$this->validToken}"])->getJson('/api/v1/snapshot');
        $events = $this->getJson('/api/v1/events?limit=1');

        $this->assertSame(
            $snap->json('genesis_hash'),
            $events->json('genesis_hash'),
            'DIR-FED-17: snapshot.genesis_hash MUST match events.genesis_hash'
        );
    }
}
