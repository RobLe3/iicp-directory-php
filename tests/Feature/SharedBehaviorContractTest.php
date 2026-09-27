<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Capability;
use App\Models\Node;
use App\Models\Operator;
use App\Models\Reputation;
use App\Rules\RoutableEndpoint;
use App\Services\AvailabilityWindowPolicy;
use App\Services\CapabilityEvidencePolicy;
use App\Services\NodeEligibilityPolicy;
use App\Services\NodePricingPolicy;
use App\Services\NodeRankingPolicy;
use App\Services\NodeReadinessPolicy;
use App\Services\OperatorDelegationVerifier;
use App\Services\TrustDomainMembershipService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SharedBehaviorContractTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            file_get_contents(base_path('parity/behavior-contract-v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_v11080_manifest_pins_the_shared_fixture_bytes(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('parity/contract-v1.10.80.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('v1.10.80', $manifest['contract_version']);
        $this->assertSame('v1.10.80.1', $manifest['authority']['runtime_version']);
        foreach ($manifest['fixtures'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_v11081_manifest_preserves_the_reviewed_contract_bytes(): void
    {
        $manifest = json_decode(
            file_get_contents(base_path('parity/contract-v1.10.81.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame('v1.10.81', $manifest['contract_version']);
        $this->assertSame('v1.10.81.2', $manifest['authority']['runtime_version']);
        foreach ($manifest['fixtures'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_v11083_manifest_pins_replica_lifecycle_contracts(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.83.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.83', $manifest['contract_version']);
        foreach ($manifest['fixtures'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_v11084_manifest_pins_discovery_evidence_contracts(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.84.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.84', $manifest['contract_version']);
        foreach ($manifest['fixtures'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_v11085_manifest_pins_sdk_release_currency(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.85.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.85', $manifest['contract_version']);
        $this->assertSame('v1.10.85', $manifest['authority']['runtime_version']);
        $this->assertContains('sdk_release_currency_correction', $manifest['scope']);
    }

    public function test_v11090_manifest_pins_bounded_stats_lookup_and_unchanged_wire(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.90.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.90', $manifest['contract_version']);
        $this->assertSame('v1.10.90', $manifest['authority']['runtime_version']);
        $this->assertContains('bounded_stats_aggregate_lookup', $manifest['scope']);
        $this->assertContains('deterministic_latest_metric_selection', $manifest['scope']);
        $this->assertContains('unchanged_wire_contract', $manifest['scope']);
        $this->assertContains('no_schema_migration', $manifest['scope']);
    }

    public function test_v11091_manifest_pins_effective_capability_contract(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.91.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.91', $manifest['contract_version']);
        $this->assertSame('v1.10.91', $manifest['authority']['runtime_version']);
        $this->assertContains('effective_capability_v1', $manifest['scope']);
        $this->assertContains('same_intent_variant_matching', $manifest['scope']);
        $this->assertContains('additive_schema_migration', $manifest['scope']);
        foreach (array_filter(
            $manifest['fixtures'],
            static fn (string $file): bool => str_starts_with($file, 'effective-capability-v1/'),
            ARRAY_FILTER_USE_KEY,
        ) as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_v11093_manifest_pins_outcome_and_restricted_domain_contracts(): void
    {
        $manifest = json_decode(file_get_contents(base_path('parity/contract-v1.10.93.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('v1.10.93', $manifest['contract_version']);
        $this->assertSame('v1.10.93', $manifest['authority']['runtime_version']);
        $this->assertContains('sdk_release_currency_0_7_106', $manifest['scope']);
        $this->assertContains('restricted_trust_domain_preview_disabled_by_default', $manifest['scope']);
        $this->assertContains('reputation_outcome_v2_epoch', $manifest['scope']);
        $this->assertContains('schema_migration_2026_08_20_130000', $manifest['scope']);
        foreach ($manifest['fixtures'] as $file => $digest) {
            $this->assertSame($digest, hash_file('sha256', base_path('parity/'.$file)), $file);
        }
    }

    public function test_shared_ranking_cases_match_authoritative_policy(): void
    {
        $fixture = $this->fixture();
        $this->assertSame('iicp.directory.behavior-contract.v1', $fixture['schema']);
        $ranking = new NodeRankingPolicy(
            new CapabilityEvidencePolicy,
            new AvailabilityWindowPolicy,
            new NodeReadinessPolicy,
        );

        foreach ($fixture['ranking_cases'] as $case) {
            $node = $this->nodeFromFixture($case['node']);
            $actual = $ranking->score(
                $node,
                $case['requested_region'],
                $case['requested_model'],
            );
            $this->assertEqualsWithDelta(
                $case['expected'],
                $actual,
                0.000001,
                $case['name'],
            );
        }
    }

    public function test_shared_eligibility_cases_match_authoritative_policy(): void
    {
        $policy = new NodeEligibilityPolicy;
        foreach ($this->fixture()['eligibility_cases'] as $case) {
            $nodes = new Collection(array_map(
                fn (array $candidate): Node => $this->eligibilityNode($candidate),
                $case['candidates'],
            ));
            $actual = $policy
                ->filter($nodes, $case['model'], $case['qos'], $case['min_reputation'])
                ->pluck('id')
                ->values()
                ->all();
            $this->assertSame($case['expected_ids'], $actual, $case['name']);
        }
    }

    public function test_shared_pricing_and_endpoint_cases_match_authoritative_policies(): void
    {
        $fixture = $this->fixture();
        $pricing = new NodePricingPolicy;
        foreach ($fixture['pricing_cases'] as $case) {
            $actual = $pricing->resolve(
                ['credit_cost_multiplier' => $case['declared']],
                'unused-key',
                $case['models'],
            );
            $this->assertEqualsWithDelta(
                $case['expected'],
                $actual['credit_cost_multiplier'],
                0.000001,
                $case['name'],
            );
        }
        foreach ($fixture['endpoint_cases'] as $case) {
            $this->assertSame(
                $case['blocked'],
                RoutableEndpoint::ipIsBlocked($case['ip']),
                $case['name'],
            );
        }
    }

    public function test_shared_registration_cases_preserve_recovery_and_rollback(): void
    {
        $mode = getenv('IICP_PRE1_REGISTRATION_MODE') ?: 'local-only';
        $this->assertContains($mode, ['local-only', 'public', 'restricted']);
        config()->set('iicp.restricted_domain.enabled', $mode === 'restricted');
        if ($mode === 'restricted') {
            config()->set('iicp.restricted_domain.domain_id', 'example.internal');
            config()->set('iicp.restricted_domain.authority_id', 'did:key:directory');
            config()->set('iicp.restricted_domain.authority_key_id', 'did:key:directory#key-1');
            config()->set('iicp.restricted_domain.membership_epoch', 1);
            config()->set('app.genesis_ed25519_secret_key', sodium_bin2hex(
                sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
            ));
        }
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);
        $cases = collect($this->fixture()['registration_cases'])->keyBy('name');

        $recovery = $cases->get('recovery_replaces_relations');
        $nodeId = (string) Str::uuid();
        if ($mode === 'restricted') {
            $this->postJson('/api/v1/register', $this->payload($nodeId, 'model-a', '08:00'))
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'restricted_domain_denied');
            $this->assertDatabaseCount('nodes', 0);
        }
        $first = $this->registrationRequest($this->payload($nodeId, 'model-a', '08:00'))
            ->assertCreated();
        $second = $this->registrationRequest([
            ...$this->payload($nodeId, 'model-b', '09:00'),
            'current_node_token' => $first->json('node_token'),
        ])->assertCreated();
        $this->assertSame($recovery['expected']['recovered'], $second->json('recovered'));
        $this->assertDatabaseCount('nodes', $recovery['expected']['node_rows']);
        $this->assertDatabaseCount('capabilities', $recovery['expected']['capability_rows']);
        $this->assertDatabaseCount('availability_windows', $recovery['expected']['availability_rows']);
        $observedRecovery = [
            'node_rows' => DB::table('nodes')->count(),
            'capability_rows' => DB::table('capabilities')->count(),
            'availability_rows' => DB::table('availability_windows')->count(),
            'recovered' => $second->json('recovered'),
        ];
        $recovered = Node::findOrFail($nodeId);
        $this->assertSame(['model-b'], $recovered->capabilities()->firstOrFail()->models);
        $this->assertSame('09:00', $recovered->availabilityWindows()->firstOrFail()->start_time);

        Node::query()->delete();
        $rollback = $cases->get('revoked_operator_rolls_back');
        $rollbackId = (string) Str::uuid();
        $keypair = sodium_crypto_sign_keypair();
        $public = base64_encode(sodium_crypto_sign_publickey($keypair));
        $notAfter = time() + 3600;
        $signature = base64_encode(sodium_crypto_sign_detached(
            OperatorDelegationVerifier::canonicalBytes($rollbackId, $public, $notAfter),
            sodium_crypto_sign_secretkey($keypair),
        ));
        $operator = Operator::create([
            'operator_pubkey' => $public,
            'identity_status' => Operator::IDENTITY_ACTIVE,
        ]);
        $claim = [
            ...$this->payload($rollbackId, 'model-a', '08:00'),
            'operator_delegation' => [
                'node_id' => $rollbackId,
                'operator_pub' => $public,
                'not_after' => $notAfter,
                'sig' => $signature,
            ],
        ];

        // A revoked refusal is meaningful only after this exact signed claim
        // successfully binds the active identity in the installed application.
        $this->registrationRequest($claim)
            ->assertCreated()
            ->assertJsonPath('node_id', $rollbackId);
        $bound = Node::findOrFail($rollbackId);
        $this->assertSame(1, $bound->operator_verified);
        $this->assertSame($public, $bound->operator_pubkey);
        Node::query()->delete();
        $operator->refresh()->update(['identity_status' => Operator::IDENTITY_REVOKED]);
        $this->assertDatabaseHas('operators', [
            'operator_pubkey' => $public,
            'identity_status' => Operator::IDENTITY_REVOKED,
        ]);

        $refused = $this->registrationRequest($claim)
            ->assertStatus($rollback['expected']['status'])
            ->assertJsonPath('error.code', 'validation_error')
            ->assertJsonPath('error.fields.operator_delegation', [
                'operator identity is rotated or revoked and cannot make new delegation claims (IICP-E063)',
            ]);
        $this->assertDatabaseCount('nodes', $rollback['expected']['node_rows']);
        $this->assertDatabaseCount('capabilities', $rollback['expected']['capability_rows']);
        $this->assertDatabaseCount('availability_windows', $rollback['expected']['availability_rows']);
        if (getenv('IICP_PRE1_REGISTRATION_MODE') !== false) {
            fwrite(STDOUT, 'IICP_PRE1_REGISTRATION_OBSERVATION '.json_encode([
                'recovery_replaces_relations' => $observedRecovery,
                'revoked_operator_rolls_back' => [
                    'node_rows' => DB::table('nodes')->count(),
                    'capability_rows' => DB::table('capabilities')->count(),
                    'availability_rows' => DB::table('availability_windows')->count(),
                    'status' => $refused->status(),
                ],
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }

    }

    /** @param array<string,mixed> $payload */
    private function registrationRequest(array $payload): TestResponse
    {
        $headers = [];
        if (config('iicp.restricted_domain.enabled')) {
            $issued = app(TrustDomainMembershipService::class)->issue(
                'node', $payload['node_id'], ['registration'], 3600,
            );
            $headers = [
                'X-IICP-Membership' => $issued['token'],
                'X-IICP-Subject-Id' => $payload['node_id'],
            ];
        }

        return $this->postJson('/api/v1/register', $payload, $headers);
    }

    /** @param array<string,mixed> $data */
    private function nodeFromFixture(array $data): Node
    {
        $node = new Node;
        $node->load = $data['load'];
        $node->max_concurrent = $data['max_concurrent'];
        $node->active_jobs = $data['active_jobs'];
        $node->region = $data['region'];
        $node->pricing_credits_per_1000 = $data['pricing'];
        $node->sdk_version = $data['sdk_current'] ? NodeReadinessPolicy::SDK_BASELINE_VERSION : null;
        $node->cx_public_key = $data['cx_key'] ? 'fixture-key' : null;
        $node->setRelation(
            'reputation',
            $data['reputation'] === null ? null : new Reputation(['score' => $data['reputation']]),
        );
        $node->setRelation('availabilityWindows', new Collection);
        $node->setRelation('capabilities', new Collection([
            new Capability([
                'models' => $data['models'],
                'max_tokens' => 4096,
                'input_modalities' => ['text'],
            ]),
        ]));

        return $node;
    }

    /** @param array<string,mixed> $data */
    private function eligibilityNode(array $data): Node
    {
        $node = new Node;
        $node->id = $data['id'];
        $node->health_models = $data['health_models'];
        $node->backend_stability = isset($data['backend_state'])
            ? ['backend_state' => $data['backend_state'], 'reason_class' => 'ok']
            : null;
        $node->setRelation('reputation', new Reputation([
            'score' => $data['reputation'],
            'completed_tasks_count' => $data['tasks'],
        ]));
        $node->setRelation('capabilities', new Collection([
            new Capability(['models' => $data['models']]),
        ]));

        return $node;
    }

    /** @return array<string,mixed> */
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
            'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
        ];
    }
}
