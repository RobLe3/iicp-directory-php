<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\NodeEvent;
use App\Services\NodePolicyManifestVerifier;
use App\Services\OperatorDelegationVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RegisterTest extends TestCase
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

    // ── ADR-045 Phase A — verifiable operator→node delegation ──────────────────

    public function test_valid_operator_delegation_binds_verified_operator(): void
    {
        $nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $pub = base64_encode(sodium_crypto_sign_publickey($kp));
        $notAfter = time() + 3600;
        $msg = OperatorDelegationVerifier::canonicalBytes($nodeId, $pub, $notAfter);
        $sig = base64_encode(sodium_crypto_sign_detached($msg, sodium_crypto_sign_secretkey($kp)));

        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'operator_delegation' => ['node_id' => $nodeId, 'operator_pub' => $pub, 'not_after' => $notAfter, 'sig' => $sig],
        ]);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::find($nodeId);
        $this->assertTrue((bool) $node->operator_verified);
        $this->assertSame($pub, $node->operator_pubkey);
        $this->assertSame('did_key', $node->operator_trust_tier);
    }

    public function test_invalid_operator_delegation_registers_but_unverified(): void
    {
        // Bad signature → node still registers (lenient), but no verified binding.
        $nodeId = (string) Str::uuid();
        $pub = base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES));
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'operator_delegation' => [
                'node_id' => $nodeId, 'operator_pub' => $pub,
                'not_after' => time() + 3600,
                'sig' => base64_encode(random_bytes(SODIUM_CRYPTO_SIGN_BYTES)),
            ],
        ]);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::find($nodeId);
        $this->assertFalse((bool) $node->operator_verified);
        $this->assertNull($node->operator_pubkey);
    }

    public function test_register_without_delegation_is_unverified_backcompat(): void
    {
        $this->postJson('/api/v1/register', $this->validPayload)->assertStatus(201);
        $node = Node::where('endpoint', 'https://node.example.com')->first();
        $this->assertFalse((bool) $node->operator_verified);
    }

    // ── backend server flavor (node-detail field) ──────────────────────────────

    public function test_register_stores_and_exposes_backend_flavor(): void
    {
        $nodeId = (string) Str::uuid();
        $payload = array_merge($this->validPayload, ['node_id' => $nodeId, 'backend' => 'ollama']);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);
        $this->assertSame('ollama', Node::find($nodeId)->backend);
        // surfaced in the node-detail endpoint
        $this->getJson("/api/v1/registry/nodes/{$nodeId}")
            ->assertStatus(200)
            ->assertJsonPath('backend', 'ollama');
    }

    public function test_register_accepts_meshllm_as_informational_backend_flavor(): void
    {
        $nodeId = (string) Str::uuid();
        $payload = array_merge($this->validPayload, ['node_id' => $nodeId, 'backend' => 'meshllm']);
        $this->postJson('/api/v1/register', $payload)->assertStatus(201);
        $this->getJson("/api/v1/registry/nodes/{$nodeId}")
            ->assertStatus(200)
            ->assertJsonPath('backend', 'meshllm');
    }

    public function test_register_stores_only_supported_receipt_profile_and_projects_it_to_event(): void
    {
        $nodeId = (string) Str::uuid();
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'supported_receipt_profiles' => ['consumer_cosignature_v1'],
        ]);
        $first = $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $this->assertSame(['consumer_cosignature_v1'], Node::findOrFail($nodeId)->supported_receipt_profiles);
        $event = NodeEvent::where('node_id', $nodeId)->where('event_type', 'REGISTER')->latest('seq')->firstOrFail();
        $this->assertSame(['consumer_cosignature_v1'], $event->payload['supported_receipt_profiles']);

        $withdrawn = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'current_node_token' => $first->json('node_token'),
        ]);
        $this->postJson('/api/v1/register', $withdrawn)->assertStatus(201);
        $this->assertNull(Node::findOrFail($nodeId)->supported_receipt_profiles);

        $invalid = array_merge($this->validPayload, [
            'endpoint' => 'https://another.example.com',
            'supported_receipt_profiles' => ['unknown_v1'],
        ]);
        $this->postJson('/api/v1/register', $invalid)->assertStatus(422);
    }

    public function test_reregister_replaces_stale_capability_models(): void
    {
        $nodeId = (string) Str::uuid();
        $initial = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['stable-model', 'mesh'],
                'max_tokens' => 4096,
            ]],
        ]);
        $this->postJson('/api/v1/register', $initial)->assertStatus(201);

        $current = array_merge($initial, [
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['stable-model', 'replacement-model'],
                'max_tokens' => 4096,
            ]],
        ]);
        $this->postJson('/api/v1/register', $current)->assertStatus(201);

        $this->getJson("/api/v1/registry/nodes/{$nodeId}")
            ->assertStatus(200)
            ->assertJsonPath('models', ['stable-model', 'replacement-model']);
        $this->assertDatabaseCount('capabilities', 1);
    }

    public function test_register_rejects_unknown_backend(): void
    {
        $payload = array_merge($this->validPayload, ['backend' => 'not-a-backend']);
        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_register_stores_public_node_policy_manifest(): void
    {
        $nodeId = (string) Str::uuid();
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'policy_manifest' => [
                'version' => '2026-07-02',
                'jurisdiction' => 'DE',
                'policy_url' => 'https://node.example.com/policy',
                'remote_executor_can_read_prompt' => true,
                'training_use' => 'none',
                'retention' => ['task_payload' => 'none', 'logs_days' => 7],
                'subprocessors' => ['self-hosted'],
                'unsupported_intents' => ['urn:iicp:intent:biometric:protected-trait:v1'],
            ],
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $this->assertSame('DE', $node->policy_manifest['jurisdiction']);
        $this->assertSame('none', $node->policy_manifest['training_use']);
        $event = NodeEvent::where('node_id', $nodeId)->where('event_type', 'REGISTER')->latest('seq')->first();
        $this->assertSame('DE', $event->payload['policy_manifest']['jurisdiction']);
    }

    public function test_register_accepts_signed_node_policy_manifest(): void
    {
        $nodeId = (string) Str::uuid();
        $manifest = $this->signedPolicyManifest();
        $payload = array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'policy_manifest' => $manifest,
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $verification = NodePolicyManifestVerifier::verify($node->policy_manifest);
        $this->assertSame(NodePolicyManifestVerifier::STATUS_SIGNED_VALID, $verification['status']);
        $this->assertSame('policy-key-1', $verification['key_id']);
    }

    public function test_register_rejects_invalid_signed_node_policy_manifest(): void
    {
        $manifest = $this->signedPolicyManifest();
        $manifest['jurisdiction'] = 'US'; // mutate after signing
        $payload = array_merge($this->validPayload, ['policy_manifest' => $manifest]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('Invalid node policy manifest signature', $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_register_rejects_mutated_policy_manifest_signature_expiry(): void
    {
        $manifest = $this->signedPolicyManifest();
        $manifest['signature']['expires_at'] = now()->addYear()->toIso8601String(); // mutate signed metadata
        $payload = array_merge($this->validPayload, ['policy_manifest' => $manifest]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('Invalid node policy manifest signature', $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_register_rejects_expired_signed_node_policy_manifest(): void
    {
        $manifest = $this->signedPolicyManifest(expiresAt: now()->subMinute()->toIso8601String());
        $payload = array_merge($this->validPayload, ['policy_manifest' => $manifest]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('signed_expired', $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_register_rejects_revoked_signed_node_policy_manifest(): void
    {
        $manifest = $this->signedPolicyManifest(signatureOverrides: [
            'revoked_at' => now()->subMinute()->toIso8601String(),
            'revocation_reason_class' => 'operator_request',
            'rotation_epoch' => 2,
        ]);
        $payload = array_merge($this->validPayload, ['policy_manifest' => $manifest]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('signed_revoked', $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_registers_node_and_returns_token(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure(['node_id', 'node_token', 'proxy_token', 'expires_at', 'directory'])
            ->assertJsonPath('expires_at', null);

        // proxy_token must be distinct from node_token (#114 Sybil gate)
        $this->assertNotSame($response->json('node_token'), $response->json('proxy_token'));

        $this->assertDatabaseHas('nodes', ['endpoint' => 'https://node.example.com']);
        $this->assertDatabaseHas('capabilities', ['intent' => 'urn:iicp:intent:llm:chat:v1']);
    }

    private function signedPolicyManifest(?string $expiresAt = null, array $signatureOverrides = []): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $manifest = [
            'version' => '2026-07-02',
            'jurisdiction' => 'DE',
            'policy_url' => 'https://node.example.com/policy',
            'remote_executor_can_read_prompt' => true,
            'training_use' => 'none',
            'retention' => ['task_payload' => 'none', 'logs_days' => 7],
            'subprocessors' => ['self-hosted'],
            'unsupported_intents' => [],
        ];
        $manifest['signature'] = array_merge([
            'algorithm' => 'Ed25519',
            'key_id' => 'policy-key-1',
            'public_key' => base64_encode($publicKey),
            'signed_at' => now()->subMinute()->toIso8601String(),
            'expires_at' => $expiresAt ?? now()->addDay()->toIso8601String(),
        ], $signatureOverrides);
        $manifest['signature']['signature'] = base64_encode(sodium_crypto_sign_detached(
            NodePolicyManifestVerifier::canonicalPayload($manifest),
            $secretKey,
        ));

        return $manifest;
    }

    public function test_returns_node_id_when_provided(): void
    {
        $nodeId = '550e8400-e29b-41d4-a716-446655440000';
        $payload = array_merge($this->validPayload, ['node_id' => $nodeId]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201)->assertJsonPath('node_id', $nodeId);
    }

    public function test_generates_node_id_when_not_provided(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201);
        $this->assertNotEmpty($response->json('node_id'));
    }

    public function test_rejects_missing_endpoint(): void
    {
        $payload = $this->validPayload;
        unset($payload['endpoint']);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_rejects_invalid_intent_format(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['intent'] = 'invalid-intent';

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_rejects_prohibited_capability_intent(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:emotion:workplace-monitoring:v1';

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('IICP directory policy', $response->getContent());
        $this->assertDatabaseCount('nodes', 0);
    }

    public function test_rejects_high_risk_capability_intent_on_public_mesh(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:credit:decision:v1';

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString('high_risk', $response->getContent());
    }

    // Custom intent URN namespace — x.<vendor> prefix (#244)
    public function test_accepts_custom_intent_x_prefix(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:x.acme:invoice-classify:v1';

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertStatus(201);
        $this->assertDatabaseHas('capabilities', ['intent' => 'urn:iicp:intent:x.acme:invoice-classify:v1']);
    }

    public function test_accepts_custom_intent_with_hyphenated_vendor(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:x.my-platform:sentiment-score:v3';

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);
    }

    public function test_rejects_custom_intent_without_vendor_label(): void
    {
        $payload = $this->validPayload;
        // x. without a vendor label is invalid
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:x.:action:v1';

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_accepts_standard_intent_alongside_custom(): void
    {
        $payload = $this->validPayload;
        // Standard domain 'xcustom' matches [a-z][a-z0-9_]* — valid standard intent
        $payload['capabilities'][0]['intent'] = 'urn:iicp:intent:xcustom:action:v1';

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);
    }

    public function test_rejects_missing_capabilities(): void
    {
        $payload = $this->validPayload;
        unset($payload['capabilities']);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_rejects_max_concurrent_out_of_range(): void
    {
        $payload = $this->validPayload;
        $payload['limits']['max_concurrent'] = 0;

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    public function test_accepts_optional_availability_windows(): void
    {
        $payload = array_merge($this->validPayload, [
            'availability' => [
                ['start' => '09:00', 'end' => '17:00', 'share' => 0.8],
            ],
        ]);

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseCount('availability_windows', 1);
    }

    public function test_rejects_unreachable_endpoint(): void
    {
        Http::fake(['https://dead.example.com/iicp/health' => Http::response('', 503)]);

        $payload = array_merge($this->validPayload, ['endpoint' => 'https://dead.example.com']);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    /**
     * RT-04 (#378): a node declaring nat_type='unknown' must NOT bypass the
     * liveness probe. 'unknown' is the absence of a topology assertion — if the
     * endpoint fails the probe, the node must be rejected (or not marked public),
     * not waved through to public_reachable=true on declaration alone.
     */
    public function test_unknown_nat_type_does_not_bypass_liveness_probe(): void
    {
        // Endpoint is dead; node claims nat_type=unknown + transport_method=direct.
        Http::fake(['https://phantom.example.com/iicp/health' => Http::response('', 503)]);

        $payload = array_merge($this->validPayload, [
            'endpoint' => 'https://phantom.example.com',
            'nat_type' => 'unknown',
            'transport_method' => 'direct',
        ]);

        // With the RT-04 fix, 'unknown' falls through to assertLive() → 422 (dead endpoint).
        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    /**
     * RT-04 control: a genuine un-probeable topology (UPnP) still bypasses the
     * probe and is marked public_reachable — the fix must not break legitimate
     * NATted nodes.
     */
    public function test_real_nat_topology_still_bypasses_probe(): void
    {
        // Endpoint would fail a probe, but upnp_mapped + full_cone is a real
        // declaration the directory cannot verify from outside → trusted.
        Http::fake(['https://upnp.example.com/iicp/health' => Http::response('', 503)]);

        $payload = array_merge($this->validPayload, [
            'endpoint' => 'https://upnp.example.com',
            'nat_type' => 'full_cone',
            'transport_method' => 'upnp_mapped',
        ]);

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertStatus(201);
        $node = Node::where('endpoint', 'https://upnp.example.com')->first();
        $this->assertTrue((bool) $node->public_reachable);
    }

    public function test_token_is_not_stored_in_plaintext(): void
    {
        $this->postJson('/api/v1/register', $this->validPayload)->assertStatus(201);

        $node = Node::first();
        $this->assertNotNull($node->node_token_hash);
        $this->assertTrue(strlen($node->node_token_hash) > 20);
        // proxy_token also stored as bcrypt hash, never in plaintext (#114)
        $this->assertNotNull($node->proxy_token_hash);
        $this->assertTrue(strlen($node->proxy_token_hash) > 20);
    }

    public function test_structured_error_on_validation_failure(): void
    {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['error' => ['code', 'message', 'fields']]);
    }

    // ADR-001 / D2 Security: REGISTER event must not expose auth credentials.
    // Events are distributed to replicas via /api/v1/events — leaking tokens
    // would give every replica full access to all registered nodes.
    public function test_register_event_does_not_leak_authentication_credentials(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);
        $response->assertStatus(201);
        $nodeId = $response->json('node_id');

        $event = NodeEvent::where('event_type', 'REGISTER')
            ->where('node_id', $nodeId)
            ->first();

        $this->assertNotNull($event, 'REGISTER event must be logged');
        $payload = $event->payload;

        $this->assertArrayNotHasKey('node_token', $payload);
        $this->assertArrayNotHasKey('node_token_hash', $payload);
        $this->assertArrayNotHasKey('node_hmac_key', $payload);
        $this->assertArrayNotHasKey('proxy_token', $payload);
        $this->assertArrayNotHasKey('proxy_token_hash', $payload);
    }

    // D2 Security: REGISTER event cip_conformance_level must be CIP-None by default.
    // Replicas use this field for CIP-Full consumer discovery routing (spec §9).
    public function test_register_event_cip_conformance_level_is_cip_none_by_default(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);
        $response->assertStatus(201);
        $nodeId = $response->json('node_id');

        $event = NodeEvent::where('event_type', 'REGISTER')
            ->where('node_id', $nodeId)
            ->first();

        $this->assertNotNull($event, 'REGISTER event must be logged');
        $this->assertSame('CIP-None', $event->payload['cip_conformance_level']);
        $this->assertFalse($event->payload['cip_policy']['allow_remote_inference']);
        $this->assertFalse($event->payload['cip_policy']['allow_tool_execution']);
        $this->assertFalse($event->payload['cip_policy']['allow_file_access']);
    }

    // D2 Security: nodes that opt in to remote inference must be tagged CIP-Provider
    // in the REGISTER event so replicas correctly route CIP-Full consumer discovery.
    public function test_register_event_cip_conformance_level_is_cip_provider_when_opted_in(): void
    {
        $payload = array_merge($this->validPayload, [
            'policy' => ['allow_remote_inference' => true],
        ]);

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertStatus(201);
        $nodeId = $response->json('node_id');

        $event = NodeEvent::where('event_type', 'REGISTER')
            ->where('node_id', $nodeId)
            ->first();

        $this->assertNotNull($event, 'REGISTER event must be logged');
        $this->assertSame('CIP-Provider', $event->payload['cip_conformance_level']);
        $this->assertTrue($event->payload['cip_policy']['allow_remote_inference']);
    }

    // DIR-REG-08 (iicp-core.md §2.1 v1.2.4): directory MUST NOT reject an unrecognised quantization value.
    public function test_accepts_unrecognised_quantization_value(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['quantization'] = 'fp64';  // not in the known list

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('capabilities', ['quantization' => 'fp64']);
    }

    // DIR-REG-09 (iicp-core.md §2.1 v1.2.4): directory MUST NOT reject an unrecognised inference_engine value.
    public function test_accepts_unrecognised_inference_engine_value(): void
    {
        $payload = $this->validPayload;
        $payload['capabilities'][0]['inference_engine'] = 'tensorrt';  // not in the known list

        $response = $this->postJson('/api/v1/register', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('capabilities', ['inference_engine' => 'tensorrt']);
    }

    // Advisory fields are optional — registration without them must succeed.
    public function test_advisory_capability_fields_are_optional(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('capabilities', [
            'intent' => 'urn:iicp:intent:llm:chat:v1',
            'quantization' => null,
            'inference_engine' => null,
        ]);
    }

    // #215: active node re-registration with same node_id must update tokens, not fail.
    // Adapter restart within heartbeat window previously caused a primary-key collision.
    public function test_re_register_active_node_updates_token_without_creating_duplicate(): void
    {
        // First registration — creates active node
        $first = $this->postJson('/api/v1/register', $this->validPayload);
        $first->assertStatus(201);
        $nodeId = $first->json('node_id');
        $firstToken = $first->json('node_token');

        $this->assertDatabaseCount('nodes', 1);

        // Second registration with same node_id (adapter restart while still active)
        $second = $this->postJson('/api/v1/register', array_merge(
            $this->validPayload,
            ['node_id' => $nodeId]
        ));
        $second->assertStatus(201)
            ->assertJsonPath('node_id', $nodeId);

        // Must still be exactly 1 node — no duplicate created
        $this->assertDatabaseCount('nodes', 1);

        // Token must have changed (new credentials issued)
        $this->assertNotSame($firstToken, $second->json('node_token'));
    }

    /** @test W-033: region field rejects injection characters while allowing safe free-form values */
    public function test_rejects_region_with_injection_characters(): void
    {
        foreach (['<script>alert(1)</script>', '../../etc/passwd', 'eu central', 'region"xss'] as $badRegion) {
            $payload = $this->validPayload;
            $payload['region'] = $badRegion;
            $this->postJson('/api/v1/register', $payload)
                ->assertStatus(422);
        }
    }

    /** @test W-033: safe free-form region values (not in canonical map) must still be accepted */
    public function test_accepts_safe_unknown_region(): void
    {
        foreach (['ap-custom-1', 'MY_REGION', 'local-dc'] as $safeRegion) {
            $payload = $this->validPayload;
            $payload['region'] = $safeRegion;
            $this->postJson('/api/v1/register', $payload)
                ->assertStatus(201);
        }
    }

    /** @test #346 Bug A: transport_method='direct' counts as a declared-reachable claim */
    public function test_direct_transport_marks_public_reachable(): void
    {
        Cache::flush();
        $payload = array_merge($this->validPayload, [
            'nat_type' => 'unknown',
            'transport_method' => 'direct',
        ]);
        $resp = $this->postJson('/api/v1/register', $payload);
        $resp->assertStatus(201);
        $this->assertDatabaseHas('nodes', [
            'endpoint' => 'https://node.example.com',
            'public_reachable' => true,
        ]);
    }

    /** @test #346 Bug B: IICP_SKIP_LIVENESS_CHECK=true should not silently demote
     *  RoutableEndpoint-validated nodes to internal-only */
    public function test_skip_liveness_check_keeps_routable_node_public(): void
    {
        Cache::flush();
        $original = config('iicp.registry.skip_liveness_check');
        try {
            Config::set('iicp.registry.skip_liveness_check', true);
            $payload = $this->validPayload;
            // No nat_type/transport_method — falls to skip-mode branch
            $resp = $this->postJson('/api/v1/register', $payload);
            $resp->assertStatus(201);
            $this->assertDatabaseHas('nodes', [
                'endpoint' => 'https://node.example.com',
                'public_reachable' => true,
            ]);
        } finally {
            Config::set('iicp.registry.skip_liveness_check', $original);
        }
    }

    /** @test #346: SDK-default `sdk-<model>-<hex>` identifiers are accepted on register */
    public function test_accepts_sdk_default_node_id_format(): void
    {
        Cache::flush();
        $payload = array_merge($this->validPayload, [
            'node_id' => 'sdk-qwen2.5-0.5b-1a2b3c4d',
            'sdk_language' => 'python',
            'sdk_version' => '0.5.6',
        ]);
        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('node_id', 'sdk-qwen2.5-0.5b-1a2b3c4d');
        $this->assertDatabaseHas('nodes', ['id' => 'sdk-qwen2.5-0.5b-1a2b3c4d']);
    }

    /** @test #346: deregister releases one slot from the per-IP register rate window */
    public function test_deregister_releases_register_rate_slot(): void
    {
        Cache::flush();

        // Register one node (counter = 1)
        $register = $this->postJson('/api/v1/register', $this->validPayload);
        $register->assertStatus(201);
        $nodeId = $register->json('node_id');
        $nodeToken = $register->json('node_token');

        $key = 'reg_rate:127.0.0.1';
        $this->assertSame(1, Cache::get($key));

        // Deregister (NodeTokenAuth via Bearer token) → counter back to 0
        $deregister = $this->withHeaders([
            'Authorization' => 'Bearer '.$nodeToken,
        ])->deleteJson('/api/v1/register', [
            'node_id' => $nodeId,
        ]);
        $deregister->assertStatus(200);
        $this->assertSame(0, (int) Cache::get($key, 0));
    }

    /** @test W-033 / #346: 61st registration from same IP within window returns 429 IICP-E034 */
    public function test_rate_limits_excess_registrations_from_same_ip(): void
    {
        Cache::flush();
        // Pre-saturate the counter — 60 attempts already consumed (raised
        // from 10 in #346 along with the TTL drop from 900s → 60s)
        $rateLimitKey = 'reg_rate:127.0.0.1';
        Cache::put($rateLimitKey, 60, 60);

        $response = $this->postJson('/api/v1/register', $this->validPayload);
        $response->assertStatus(429)
            ->assertJson(['error' => 'IICP-E034', 'message' => 'TooManyRegistrationAttempts']);
    }

    /** @test #525: fresh registrations from one source IP have an active-node capacity gate */
    public function test_rejects_fresh_registration_when_source_ip_active_node_capacity_is_reached(): void
    {
        $previous = config('iicp.registry.max_active_nodes_per_source_ip');
        Config::set('iicp.registry.max_active_nodes_per_source_ip', 1);

        try {
            Node::create([
                'id' => (string) Str::uuid(),
                'endpoint' => 'https://existing.example.com',
                'region' => 'eu-central',
                'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
                'proxy_token_hash' => password_hash('proxy', PASSWORD_BCRYPT),
                'node_hmac_key' => bin2hex(random_bytes(32)),
                'max_concurrent' => 1,
                'tokens_per_min' => 1000,
                'available' => true,
                'status' => 'active',
                'identity_key' => hash('sha256', 'existing'),
                'observed_source_ip' => '127.0.0.1',
            ]);

            $this->postJson('/api/v1/register', $this->validPayload)
                ->assertStatus(422)
                ->assertJsonPath('error.fields.registration_ip.0', 'Too many active nodes registered from this source IP (IICP-E052)');
        } finally {
            Config::set('iicp.registry.max_active_nodes_per_source_ip', $previous);
        }
    }

    // --- IICP-CX S.16 §3.1 — X25519 public key advertisement (#360) ---

    private array $cxKey = [
        'algorithm' => 'X25519',
        'encoding' => 'base64url',
        'key' => 'k7Hx2Yb9QnJ3vF1mZ0pLd8RtWcXeSgUaNoBhKiMjPw',
        'key_id' => 'a1b2c3d4e5f60718',
        'not_after' => '2026-08-27T00:00:00Z',
        'hybrid_pq' => null,
        'features' => ['response_encryption_v1'],
    ];

    /** @test CX-01: CX-Provider advertises public_key; directory stores it verbatim */
    public function test_stores_cx_public_key_on_register(): void
    {
        $payload = array_merge($this->validPayload, ['cx_public_key' => $this->cxKey]);

        $response = $this->postJson('/api/v1/register', $payload);
        $response->assertStatus(201);

        $node = Node::firstWhere('id', $response->json('node_id'));
        $this->assertSame('X25519', $node->cx_public_key['algorithm']);
        $this->assertSame($this->cxKey['key'], $node->cx_public_key['key']);
        $this->assertSame($this->cxKey['key_id'], $node->cx_public_key['key_id']);
        $this->assertSame(['response_encryption_v1'], $node->cx_public_key['features']);
    }

    /** @test CX-01: registration without cx_public_key leaves the column null (back-compat) */
    public function test_cx_public_key_is_optional(): void
    {
        $response = $this->postJson('/api/v1/register', $this->validPayload);
        $response->assertStatus(201);

        $node = Node::firstWhere('id', $response->json('node_id'));
        $this->assertNull($node->cx_public_key);
    }

    /** @test rejects a cx_public_key with an unsupported algorithm */
    public function test_rejects_cx_public_key_with_bad_algorithm(): void
    {
        $payload = array_merge($this->validPayload, [
            'cx_public_key' => array_merge($this->cxKey, ['algorithm' => 'RSA']),
        ]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    /** @test rejects a cx_public_key missing the required key field */
    public function test_rejects_cx_public_key_missing_key(): void
    {
        $bad = $this->cxKey;
        unset($bad['key']);
        $payload = array_merge($this->validPayload, ['cx_public_key' => $bad]);

        $this->postJson('/api/v1/register', $payload)->assertStatus(422);
    }

    /** @test RT-6-1 (#390): re-registration that changes cx_public_key requires valid current_node_token */
    public function test_cx_key_substitution_blocked_without_ownership_proof(): void
    {
        // Register initial node with a cx_public_key
        $first = $this->postJson('/api/v1/register', array_merge($this->validPayload, ['cx_public_key' => $this->cxKey]));
        $first->assertStatus(201);
        $nodeId = $first->json('node_id');

        $attackerKey = array_merge($this->cxKey, ['key' => str_repeat('B', 43).'=', 'key_id' => 'attacker-key']);

        // Re-register with a DIFFERENT cx_public_key but NO current_node_token → IICP-E049
        $attack = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $attackerKey,
        ]));
        $attack->assertStatus(403)
            ->assertJsonPath('error.code', 'IICP-E049');

        // Original key must still be stored
        $node = Node::findOrFail($nodeId);
        $this->assertSame($this->cxKey['key'], $node->cx_public_key['key']);
    }

    /** @test RT-6-1 (#390): re-registration with valid current_node_token CAN update cx_public_key */
    public function test_cx_key_rotation_allowed_with_ownership_proof(): void
    {
        $first = $this->postJson('/api/v1/register', array_merge($this->validPayload, ['cx_public_key' => $this->cxKey]));
        $first->assertStatus(201);
        $nodeId = $first->json('node_id');
        $token = $first->json('node_token');

        $newKey = array_merge($this->cxKey, ['key' => str_repeat('C', 43).'=', 'key_id' => 'rotated-key']);

        // Re-register with new cx_public_key + correct current_node_token → allowed
        $rotation = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'current_node_token' => $token,
            'cx_public_key' => $newKey,
        ]));
        $rotation->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $this->assertSame($newKey['key'], $node->cx_public_key['key']);
    }

    /** @test RT-6-1 (#390): re-registration WITHOUT cx_public_key change does NOT require current_node_token (backward compat) */
    public function test_re_registration_without_cx_key_change_is_backward_compatible(): void
    {
        $first = $this->postJson('/api/v1/register', $this->validPayload);
        $first->assertStatus(201);
        $nodeId = $first->json('node_id');

        // Re-register with same node_id, no cx_public_key, no current_node_token → still OK
        $second = $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]));
        $second->assertStatus(201)
            ->assertJsonPath('node_id', $nodeId);
    }

    /** @test #418-A: an explicit null transport_endpoint on re-register CLEARS a stale value. */
    public function test_reregister_with_explicit_null_clears_stale_transport_endpoint(): void
    {
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201);

        // Simulate a stale native-transport endpoint stored by an earlier SDK session.
        Node::where('id', $nodeId)->update(['transport_endpoint' => 'iicp://node.example.com:9484']);
        $this->assertSame('iicp://node.example.com:9484', Node::find($nodeId)->transport_endpoint);

        // The current SDK no longer advertises native transport → sends explicit null.
        // Before #418-A the `?? existing` merge pinned the stale value forever.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'transport_endpoint' => null,
        ]))->assertStatus(201);

        $this->assertNull(Node::find($nodeId)->transport_endpoint, 'explicit null must clear the stale transport_endpoint');
    }

    /** @test #418-A: transport_endpoint is declaration-authoritative — a re-register that no
     * longer declares native transport resets the stored value (the SDK always sends the field). */
    public function test_reregister_resets_transport_endpoint_to_latest_declaration(): void
    {
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201);
        Node::where('id', $nodeId)->update(['transport_endpoint' => 'iicp://node.example.com:9484']);

        // A re-register whose payload does not carry native transport → the node's current
        // declaration wins → the stale iicp:// endpoint is cleared (not pinned forever).
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201);

        $this->assertNull(Node::find($nodeId)->transport_endpoint);
    }

    // ── IICP-E050 (F2/#529) — endpoint-substitution hijack guard, approach E ──

    /** @test hijack: changing endpoint while the OLD endpoint is still alive, with no token → 403 E050 */
    public function test_e050_rejects_endpoint_change_when_old_alive_and_no_token(): void
    {
        Http::fake([
            'https://node.example.com/iicp/health' => Http::response('ok', 200),
            'https://attacker.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201);

        // Attacker re-registers the same node_id pointing at their own (live) server, no token.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://attacker.example.com',
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');

        // The hijack did not take effect.
        $this->assertSame('https://node.example.com', Node::find($nodeId)->endpoint);
    }

    /** @test legitimate rotation: changing endpoint while the OLD endpoint is GONE → 201 (no token needed) */
    public function test_e050_allows_endpoint_change_when_old_endpoint_is_gone(): void
    {
        // old-tunnel: ALIVE at the first register's liveness probe, then GONE
        // (502) at the E050 old-endpoint probe during re-registration.
        Http::fake([
            'https://old-tunnel.example.com/iicp/health' => Http::sequence()
                ->push('ok', 200)
                ->push('gone', 502),
            'https://new-tunnel.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://old-tunnel.example.com',
        ]))->assertStatus(201);

        // The tunnel rotated: old URL now 502s, node re-registers the new URL
        // without a token — the dead old endpoint is the legitimate-rotation signal.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://new-tunnel.example.com',
        ]))->assertStatus(201);

        $this->assertSame('https://new-tunnel.example.com', Node::find($nodeId)->endpoint);
    }

    /** @test ownership: changing endpoint with a valid current_node_token → 201 even if old is alive */
    public function test_e050_allows_endpoint_change_with_valid_current_node_token(): void
    {
        Http::fake([
            'https://node.example.com/iicp/health' => Http::response('ok', 200),
            'https://moved.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $token = $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201)->json('node_token');

        // Legitimate move with ownership proof — old endpoint still alive, but token proves control.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://moved.example.com',
            'current_node_token' => $token,
        ]))->assertStatus(201);

        $this->assertSame('https://moved.example.com', Node::find($nodeId)->endpoint);
    }

    // ── Migration safety matrix (#529/#55) — does the deployed directory keep
    // DOWNLEVEL clients (no current_node_token) working during the transition,
    // and does an UPDATED client solve the rotation problem? ──────────────────

    /** @test DOWNLEVEL: first registration (no current_node_token) → 201 */
    public function test_downlevel_first_register_works(): void
    {
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId]))
            ->assertStatus(201);
    }

    /** @test DOWNLEVEL: re-register with the SAME endpoint (restart, no token) → 201 */
    public function test_downlevel_reregister_same_endpoint_works(): void
    {
        $nodeId = (string) Str::uuid();
        $p = array_merge($this->validPayload, ['node_id' => $nodeId]);
        $this->postJson('/api/v1/register', $p)->assertStatus(201);
        $this->postJson('/api/v1/register', $p)->assertStatus(201); // no E050 gate (endpoint unchanged)
    }

    /** @test DOWNLEVEL: legitimate rotation (endpoint changed, OLD DEAD, no token) → 201
     *  This is the dominant tunnel/CGNAT restart case — downlevel clients keep working. */
    public function test_downlevel_rotation_with_dead_old_endpoint_works(): void
    {
        Http::fake([
            'https://old.example.com/iicp/health' => Http::sequence()->push('ok', 200)->push('gone', 502),
            'https://new.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://old.example.com']))->assertStatus(201);
        // No current_node_token (downlevel) — accepted because the old endpoint is gone.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://new.example.com']))->assertStatus(201);
        $this->assertSame('https://new.example.com', Node::find($nodeId)->endpoint);
    }

    /** @test UPDATED client SOLVES the hard case: rotation while OLD STILL ALIVE,
     *  using current_node_token → 201 (the downlevel path would 403 here). */
    public function test_updated_client_rotates_even_when_old_alive(): void
    {
        Http::fake([
            'https://a.example.com/iicp/health' => Http::response('ok', 200),
            'https://b.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $token = $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://a.example.com']))
            ->assertStatus(201)->json('node_token');
        // Updated client sends current_node_token → accepted even though old is alive.
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://b.example.com', 'current_node_token' => $token]))
            ->assertStatus(201);
        $this->assertSame('https://b.example.com', Node::find($nodeId)->endpoint);
    }

    /** @test The ONLY rejection: endpoint change while OLD ALIVE + no token (the
     *  hijack signature). Closing the live hijack requires this; legitimate
     *  downlevel rotation almost never hits it (old endpoint is dead). */
    public function test_endpoint_change_old_alive_no_token_is_rejected(): void
    {
        Http::fake([
            'https://live.example.com/iicp/health' => Http::response('ok', 200),
            'https://evil.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://live.example.com']))->assertStatus(201);
        $this->postJson('/api/v1/register', array_merge($this->validPayload, ['node_id' => $nodeId, 'endpoint' => 'https://evil.example.com']))
            ->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');
    }

    public function test_strict_e050_secured_node_rejects_dead_endpoint_fallback(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        Http::fake([
            'https://old.example.com/iicp/health' => Http::response('ok', 200),
            'https://new.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://old.example.com',
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(201);

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://new.example.com',
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');
    }

    public function test_strict_e050_secured_node_rejects_secondary_route_change_without_token(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'transport_endpoint' => 'iicp://node.example.com:9484',
            'relay_endpoint' => 'https://relay-a.example.com',
        ]))->assertStatus(201);

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'transport_endpoint' => 'iicp://node-b.example.com:9484',
            'relay_endpoint' => 'https://relay-b.example.com',
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');
    }

    public function test_strict_e050_secured_node_accepts_routing_changes_with_token(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        $nodeId = (string) Str::uuid();
        $token = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'transport_endpoint' => 'iicp://node.example.com:9484',
            'relay_endpoint' => 'https://relay-a.example.com',
        ]))->assertStatus(201)->json('node_token');

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'transport_endpoint' => 'iicp://node-b.example.com:9484',
            'relay_endpoint' => 'https://relay-b.example.com',
            'current_node_token' => $token,
        ]))->assertStatus(201);
    }

    public function test_strict_e050_secured_node_rejects_same_route_token_rotation_without_token(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        $nodeId = (string) Str::uuid();
        $first = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(201);

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');

        // The rejected refresh must not rotate the stored credential.
        $this->assertTrue(password_verify($first->json('node_token'), Node::findOrFail($nodeId)->node_token_hash));
    }

    public function test_strict_e050_secured_node_accepts_same_route_refresh_with_token(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        $nodeId = (string) Str::uuid();
        $token = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(201)->json('node_token');

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'current_node_token' => $token,
        ]))->assertStatus(201);
    }

    public function test_strict_e050_rejects_malformed_and_stale_tokens_without_damaging_current_credential(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        $nodeId = (string) Str::uuid();
        $firstToken = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
        ]))->assertStatus(201)->json('node_token');

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'current_node_token' => 'malformed-token',
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');
        $this->assertTrue(password_verify($firstToken, Node::findOrFail($nodeId)->node_token_hash));

        $secondToken = $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'current_node_token' => $firstToken,
        ]))->assertStatus(201)->json('node_token');

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'cx_public_key' => $this->cxKey,
            'current_node_token' => $firstToken,
        ]))->assertStatus(403)->assertJsonPath('error.code', 'IICP-E050');
        $this->assertFalse(password_verify($firstToken, Node::findOrFail($nodeId)->node_token_hash));
        $this->assertTrue(password_verify($secondToken, Node::findOrFail($nodeId)->node_token_hash));
    }

    public function test_strict_e050_unsecured_node_keeps_soft_dead_endpoint_fallback(): void
    {
        config(['app.iicp_e050_strict_secured' => true]);
        Http::fake([
            'https://old.example.com/iicp/health' => Http::sequence()->push('ok', 200)->push('gone', 502),
            'https://new.example.com/iicp/health' => Http::response('ok', 200),
        ]);
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://old.example.com',
        ]))->assertStatus(201);

        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'endpoint' => 'https://new.example.com',
        ]))->assertStatus(201);
    }

    public function test_registration_separates_implementation_and_sdk_compatibility_versions(): void
    {
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'implementation_name' => 'iicp-web-node',
            'implementation_version' => '0.2.2',
            'sdk_compatibility_version' => '0.7.101',
        ]))->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $this->assertSame('iicp-web-node', $node->implementation_name);
        $this->assertSame('0.2.2', $node->implementation_version);
        $this->assertSame('0.7.101', $node->sdk_compatibility_version);
        $this->assertSame('0.7.101', $node->sdk_version);
    }

    public function test_registration_backfills_preferred_compatibility_from_legacy_sdk_version(): void
    {
        $nodeId = (string) Str::uuid();
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'node_id' => $nodeId,
            'sdk_version' => '0.7.99',
        ]))->assertStatus(201);

        $node = Node::findOrFail($nodeId);
        $this->assertSame('0.7.99', $node->effectiveSdkCompatibilityVersion());
        $this->assertSame('0.7.99', $node->sdk_compatibility_version);
    }

    public function test_registration_rejects_conflicting_sdk_compatibility_versions(): void
    {
        $this->postJson('/api/v1/register', array_merge($this->validPayload, [
            'sdk_version' => '0.7.99',
            'sdk_compatibility_version' => '0.7.101',
        ]))->assertStatus(422)
            ->assertJsonPath('error.fields.sdk_compatibility_version.0', 'Must match sdk_version when both fields are supplied.');
    }

    public function test_shared_implementation_metadata_fixture(): void
    {
        $fixture = json_decode(
            file_get_contents(base_path('parity/directory-implementation-metadata-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($fixture['cases'] as $case) {
            $response = $this->postJson('/api/v1/register', array_merge(
                $this->validPayload,
                ['node_id' => (string) Str::uuid()],
                $case['input'],
            ));
            $response->assertStatus($case['accepted'] ? 201 : $case['status']);
            if ($case['accepted']) {
                $node = Node::findOrFail($response->json('node_id'));
                $this->assertSame(
                    $case['effective_sdk_compatibility_version'],
                    $node->effectiveSdkCompatibilityVersion(),
                    $case['name'],
                );
            }
        }
    }
}
