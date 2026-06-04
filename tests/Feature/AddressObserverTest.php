<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AddressObserverTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload = [
        'endpoint' => 'https://node.example.com',
        'region' => 'eu-central',
        'capabilities' => [
            [
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['llama-3-8b'],
                'max_tokens' => 4096,
            ],
        ],
        'limits' => [
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
    }

    // DIR-ADDR-02: ACK includes observed_source_ip
    public function test_ack_includes_observed_source_ip(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure(['node_id', 'node_token', 'observed_source_ip']);

        $this->assertNotNull($response->json('observed_source_ip'));
    }

    // DIR-ADDR-01: source IP stored on registration
    public function test_observed_ip_stored_on_registration(): void
    {
        $this->postJson('/api/v1/register', $this->validPayload)
            ->assertStatus(201);

        $node = Node::first();
        $this->assertNotNull($node->observed_source_ip);
    }

    // DIR-ADDR-05: CF-Connecting-IP header is preferred
    public function test_cf_connecting_ip_header_is_preferred(): void
    {
        $response = $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.42',
        ])->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonPath('observed_source_ip', '198.51.100.42');

        $this->assertDatabaseHas('nodes', ['observed_source_ip' => '198.51.100.42']);
    }

    // DIR-ADDR-05: X-Forwarded-For fallback
    public function test_x_forwarded_for_fallback_when_no_cf_header(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.77, 10.0.0.1',
        ])->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201);
        // First value used
        $this->assertSame('203.0.113.77', $response->json('observed_source_ip'));
    }

    // DIR-ADDR-06: address history written on registration
    public function test_address_history_written_on_registration(): void
    {
        $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.42',
        ])->postJson('/api/v1/register', $this->validPayload)
            ->assertStatus(201);

        $node = Node::first();

        $this->assertDatabaseHas('node_address_history', [
            'node_id' => $node->id,
            'ip_address' => '198.51.100.42',
            'request_type' => 'register',
        ]);
    }

    // DIR-ADDR-03: IP updated on heartbeat
    public function test_observed_ip_updated_on_heartbeat(): void
    {
        $ack = $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.10',
        ])->postJson('/api/v1/register', $this->validPayload)
            ->assertStatus(201)->json();

        $node = Node::find($ack['node_id']);
        $this->assertSame('198.51.100.10', $node->observed_source_ip);

        // Heartbeat from a different IP (simulating IP change) — JWT for auth
        $this->withHeaders([
            'Authorization' => "Bearer {$ack['jwt_token']}",
            'CF-Connecting-IP' => '198.51.100.99',
        ])->postJson('/api/v1/heartbeat', ['node_id' => $ack['node_id']])
            ->assertStatus(200);

        $node->refresh();
        $this->assertSame('198.51.100.99', $node->observed_source_ip);

        $this->assertDatabaseHas('node_address_history', [
            'node_id' => $node->id,
            'ip_address' => '198.51.100.99',
            'request_type' => 'heartbeat',
        ]);
    }

    // DIR-ADDR-04: GET /v1/me returns current observed IP (uses JWT from ACK)
    public function test_me_endpoint_returns_observed_ip(): void
    {
        $ack = $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.42',
        ])->postJson('/api/v1/register', $this->validPayload)
            ->assertStatus(201)->json();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$ack['jwt_token']}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonPath('node_id', $ack['node_id'])
            ->assertJsonPath('observed_source_ip', '198.51.100.42')
            ->assertJsonPath('endpoint', 'https://node.example.com');
    }

    // DIR-ADDR-04: GET /v1/me requires auth
    public function test_me_endpoint_requires_auth(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    // DIR-ADDR-07: history not exposed via /v1/me (uses JWT from ACK)
    public function test_me_does_not_expose_full_history(): void
    {
        $ack = $this->postJson('/api/v1/register', $this->validPayload)
            ->assertStatus(201)->json();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$ack['jwt_token']}",
        ])->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('history', $response->json());
        $this->assertArrayNotHasKey('address_history', $response->json());
    }
}
