<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\NodeEvent;
use App\Models\Replica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestAppKey;
use Tests\TestCase;

class ReplicaTokenRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $server;

    private string $router;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();

        $this->port = $this->availablePort();
        $this->router = tempnam(sys_get_temp_dir(), 'iicp-replica-router-');
        file_put_contents($this->router, <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/.well-known/did.json') {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'did:web:127.0.0.1',
        'verificationMethod' => [[
            'id' => 'did:web:127.0.0.1#key-1',
            'type' => 'Ed25519VerificationKey2020',
            'controller' => 'did:web:127.0.0.1',
            'publicKeyMultibase' => 'z6Mktest',
        ]],
    ]);
    return;
}
if ($path === '/iicp/health') {
    http_response_code(200);
    return;
}
http_response_code(404);
PHP);

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->router],
            $descriptors,
            $pipes,
        );
        $this->assertIsResource($this->server, 'Failed to start loopback replica fixture.');
        $this->waitForServer();

        config([
            'app.env' => 'local',
            'app.key' => TestAppKey::base64(),
        ]);
        config(['iicp.replica.dev_allow_http_did' => true]);
    }

    protected function tearDown(): void
    {
        config(['iicp.replica.dev_allow_http_did' => false]);
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->router);

        parent::tearDown();
    }

    public function test_registration_token_authenticates_snapshot_and_rotation_revokes_it(): void
    {
        $payload = [
            'did' => "did:web:127.0.0.1%3A{$this->port}",
            'endpoint' => "http://127.0.0.1:{$this->port}",
        ];

        $registration = $this->postJson('/api/v1/replicas/register', $payload);
        $registration->assertOk()
            ->assertJsonPath('is_new_registration', true);
        $firstToken = $registration->json('replica_token');
        $this->assertIsString($firstToken);
        $this->assertNotSame('', $firstToken);

        $this->withToken($firstToken)
            ->getJson('/api/v1/snapshot')
            ->assertOk()
            ->assertJsonStructure(['schema_version', 'snapshot_seq', 'genesis_hash']);

        $rotation = $this->postJson('/api/v1/replicas/register', $payload);
        $rotation->assertOk()
            ->assertJsonPath('is_new_registration', false)
            ->assertJsonPath('replica_id', $registration->json('replica_id'));
        $secondToken = $rotation->json('replica_token');
        $this->assertIsString($secondToken);
        $this->assertNotSame($firstToken, $secondToken);

        $this->withToken($firstToken)
            ->getJson('/api/v1/snapshot')
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Replica token has been rotated; re-register to obtain a fresh token');
        $this->withToken($secondToken)
            ->getJson('/api/v1/snapshot')
            ->assertOk();
    }

    public function test_deregister_revokes_bearer_emits_event_and_same_did_reactivates_at_low_trust(): void
    {
        $payload = [
            'did' => "did:web:127.0.0.1%3A{$this->port}",
            'endpoint' => "http://127.0.0.1:{$this->port}",
        ];
        $registration = $this->postJson('/api/v1/replicas/register', $payload)->assertOk();
        $token = $registration->json('replica_token');
        $replicaId = $registration->json('replica_id');
        Replica::where('replica_id', $replicaId)->update(['trust_tier' => 'high']);

        $this->withToken($token)
            ->postJson('/api/v1/replicas/deregister')
            ->assertOk()
            ->assertExactJson(['status' => 'decommissioned']);

        $replica = Replica::where('replica_id', $replicaId)->firstOrFail();
        $this->assertSame(Replica::STATUS_DECOMMISSIONED, $replica->status);
        $this->assertTrue($replica->expires_at->isPast());
        $this->assertTrue(NodeEvent::where('event_type', 'REPLICA_DEREGISTERED')
            ->where('node_id', $replicaId)->exists());
        $this->withToken($token)->getJson('/api/v1/snapshot')->assertUnauthorized();

        $reactivation = $this->postJson('/api/v1/replicas/register', $payload)
            ->assertOk()
            ->assertJsonPath('replica_id', $replicaId)
            ->assertJsonPath('trust_tier', 'low');
        $this->assertSame(Replica::STATUS_ACTIVE, Replica::where('replica_id', $replicaId)->value('status'));
        $this->withToken($reactivation->json('replica_token'))->getJson('/api/v1/snapshot')->assertOk();
    }

    public function test_database_lifecycle_and_expiry_are_enforced_independently_of_jwt(): void
    {
        $payload = [
            'did' => "did:web:127.0.0.1%3A{$this->port}",
            'endpoint' => "http://127.0.0.1:{$this->port}",
        ];
        $registration = $this->postJson('/api/v1/replicas/register', $payload)->assertOk();
        $token = $registration->json('replica_token');
        $id = $registration->json('replica_id');

        foreach ([Replica::STATUS_DORMANT, Replica::STATUS_ARCHIVED, Replica::STATUS_DECOMMISSIONED] as $status) {
            Replica::where('replica_id', $id)->update(['status' => $status, 'expires_at' => now()->addHour()]);
            $this->withToken($token)->getJson('/api/v1/snapshot')
                ->assertUnauthorized()
                ->assertJsonPath('error.message', 'Replica is not active; re-register to reactivate');
        }

        Replica::where('replica_id', $id)->update(['status' => Replica::STATUS_ACTIVE, 'expires_at' => now()->subSecond()]);
        $this->withToken($token)->getJson('/api/v1/snapshot')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'token_expired');
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertIsResource($socket, "Unable to reserve loopback port: {$errorCode} {$errorMessage}");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForServer(): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.05);
            if (is_resource($connection)) {
                fclose($connection);

                return;
            }
            usleep(20_000);
        }

        $this->fail('Loopback replica fixture did not become ready.');
    }
}
