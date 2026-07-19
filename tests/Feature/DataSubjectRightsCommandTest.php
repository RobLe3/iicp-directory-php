<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Feature;

use App\Models\Credit;
use App\Models\CreditTransaction;
use App\Models\DataSubjectAction;
use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\Operator;
use App\Services\DataSubjectRightsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataSubjectRightsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $nodeId;

    private string $operatorPubkey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nodeId = (string) Str::uuid();
        $kp = sodium_crypto_sign_keypair();
        $this->operatorPubkey = base64_encode(sodium_crypto_sign_publickey($kp));

        Operator::create([
            'operator_pubkey' => $this->operatorPubkey,
            'display_name' => 'Privacy Tester',
            'attested_created_at' => '2026-07-02T10:00:00Z',
            'operator_integrity_hash' => hash('sha256', $this->operatorPubkey.':2026-07-02T10:00:00Z'),
            'first_seen_ms' => 1783000000000,
            'tier' => 'pioneer',
            'badge' => 'founder',
            'provenance' => ['source' => 'test'],
        ]);

        Node::create([
            'id' => $this->nodeId,
            'endpoint' => 'https://dsr-node.example.com',
            'region' => 'eu-central',
            'load' => 0.1,
            'active_jobs' => 0,
            'available' => true,
            'public_reachable' => true,
            'public_listing' => true,
            'operator_url' => 'https://operator.example.com',
            'operator_contact' => 'privacy@example.com',
            'operator_pubkey' => $this->operatorPubkey,
            'operator_verified' => true,
            'operator_trust_tier' => 'did_key',
            'observed_source_ip' => '203.0.113.10',
            'last_seen' => now(),
            'status' => 'active',
            'node_token_hash' => password_hash('node-token', PASSWORD_BCRYPT),
            'proxy_token_hash' => password_hash('proxy-token', PASSWORD_BCRYPT),
            'node_hmac_key' => hash('sha256', 'hmac'),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'transport_endpoint' => 'iicp://dsr-node.example.com:9484',
            'policy_manifest' => [
                'jurisdiction' => 'DE',
                'training_use' => 'none',
                'retention' => ['task_payload' => 'none', 'logs_days' => 7],
                'signature' => [
                    'algorithm' => 'Ed25519',
                    'public_key' => $this->operatorPubkey,
                    'signature' => 'public-proof',
                ],
            ],
            'cx_public_key' => ['algorithm' => 'X25519', 'key_id' => 'k1', 'key' => base64_encode(random_bytes(32))],
            'gossip_public_key' => base64_encode(random_bytes(32)),
            'credit_balance' => 12.5,
        ]);

        Credit::create(['node_id' => $this->nodeId, 'balance' => 12.5]);
        CreditTransaction::create([
            'node_id' => $this->nodeId,
            'amount' => 12.5,
            'type' => 'credit',
            'task_id' => 'task-1',
            'reason' => 'earned',
        ]);

        DB::table('node_address_history')->insert([
            'node_id' => $this->nodeId,
            'ip_address' => '203.0.113.10',
            'request_type' => 'register',
            'observed_at' => now(),
        ]);
        DB::table('iicp_telemetry_probes')->insert([
            'probe_token_id' => null,
            'node_id' => $this->nodeId,
            'run_id' => (string) Str::uuid(),
            'probe_id' => 'reach',
            'probe_type' => 'reachability',
            'test_id' => 'DIR-PROBE-NODE-01',
            'level' => 'MUST',
            'passed' => true,
            'latency_ms' => 10,
            'detail' => 'ok',
            'metadata' => json_encode(['endpoint' => 'https://dsr-node.example.com']),
            'probed_at' => now(),
        ]);
        DB::table('proxy_telemetry')->insert([
            'node_id' => $this->nodeId,
            'proxy_node_id' => (string) Str::uuid(),
            'time_bucket' => time(),
            'latency_ms_observed' => 12,
            'tokens_observed' => 42,
            'status' => 'success',
            'qos_met' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        NodeEvent::create([
            'event_id' => (string) Str::uuid(),
            'seq' => 1,
            'event_type' => 'REGISTER',
            'node_id' => $this->nodeId,
            'ts_ms' => (int) (microtime(true) * 1000),
            'payload' => [
                'endpoint' => 'https://dsr-node.example.com',
                'operator_pubkey' => $this->operatorPubkey,
                'node_token' => 'must-redact',
            ],
            'prev_hash' => hash('sha256', 'prev'),
            'signature' => 'sig',
        ]);
    }

    public function test_related_record_contract_is_complete_and_mirrored(): void
    {
        $seed = file_get_contents(base_path('parity/dsr-related-records-v1.json'));
        $this->assertNotFalse($seed);

        // The monorepo verifies the mirrored Rust fixture byte-for-byte.  The
        // dedicated PHP repository intentionally has no Rust sibling, so its
        // own contract validation must remain independently runnable there.
        $rustPath = base_path('../iicp-directory-rs/parity/dsr-related-records-v1.json');
        if (is_file($rustPath)) {
            $rust = file_get_contents($rustPath);
            $this->assertNotFalse($rust);
            $this->assertSame($seed, $rust);
        }

        $contract = json_decode($seed, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('iicp.directory.dsr-related-records-parity.v1', $contract['schema']);
        $this->assertSame(500, $contract['record_limit_per_family']);
        $this->assertSame('restricted', $contract['restricted_identity_status']);
        $this->assertCount(11, $contract['record_families']);
        $export = app(DataSubjectRightsService::class)->export(['node_id' => $this->nodeId]);
        $this->assertEqualsCanonicalizing(array_keys($contract['record_families']), array_keys($export['records']));
        foreach ($contract['record_families'] as $family => $fields) {
            if ($export['records'][$family] !== []) {
                $this->assertEqualsCanonicalizing($fields, array_keys($export['records'][$family][0]));
            }
        }
    }

    public function test_export_redacts_operator_key_and_secrets_but_lists_records(): void
    {
        $output = Artisan::call('iicp:dsr', [
            'action' => 'export',
            '--node-id' => $this->nodeId,
            '--tracking-id' => 'DSR-EXPORT-1',
        ]);
        $this->assertSame(0, $output);

        $json = json_decode(Artisan::output(), true);
        $this->assertSame('iicp.dsr.export.v1', $json['schema']);
        $this->assertSame($this->nodeId, $json['records']['nodes'][0]['id']);
        $this->assertTrue($json['records']['nodes'][0]['secret_fields_present']['node_token_hash']);
        $this->assertArrayNotHasKey('node_token_hash', $json['records']['nodes'][0]);
        $this->assertSame('[redacted]', $json['records']['nodes'][0]['policy_manifest']['signature']['public_key']);
        $this->assertSame(hash('sha256', $this->operatorPubkey), $json['records']['nodes'][0]['policy_manifest']['signature']['public_key_sha256']);
        $this->assertStringNotContainsString($this->operatorPubkey, json_encode($json));
        $this->assertSame('[redacted]', $json['records']['node_events'][0]['payload']['operator_pubkey']);
        $this->assertSame('[redacted]', $json['records']['node_events'][0]['payload']['node_token']);
        $this->assertCount(1, $json['records']['credit_transactions']);
        $this->assertCount(1, $json['records']['node_address_history']);
    }

    public function test_restrict_hides_public_processing_and_records_audit_action(): void
    {
        $this->artisan('iicp:dsr', [
            'action' => 'restrict',
            '--operator-pubkey' => $this->operatorPubkey,
            '--tracking-id' => 'DSR-RESTRICT-1',
        ])->assertSuccessful();

        $node = Node::findOrFail($this->nodeId);
        $this->assertFalse((bool) $node->available);
        $this->assertFalse((bool) $node->public_reachable);
        $this->assertFalse((bool) $node->public_listing);
        $this->assertSame('archived', $node->status);
        $this->assertNull($node->operator_contact);
        $this->assertNull($node->operator_url);
        $this->assertNull($node->transport_endpoint);

        $operator = Operator::where('operator_pubkey', $this->operatorPubkey)->firstOrFail();
        $this->assertSame('restricted', $operator->identity_status);
        $this->assertNull($operator->display_name);
        $this->assertSame(['dsr' => 'restricted'], $operator->provenance);

        $action = DataSubjectAction::where('tracking_id', 'DSR-RESTRICT-1')->firstOrFail();
        $this->assertSame('restrict', $action->action);
        $this->assertSame(1, $action->affected_counts['nodes']);
        $this->assertSame(1, NodeEvent::where('node_id', $this->nodeId)->count(), 'signed event log is retained');
    }

    public function test_anonymize_removes_active_identifiers_but_preserves_ledger(): void
    {
        $this->artisan('iicp:dsr', [
            'action' => 'anonymize',
            '--node-id' => $this->nodeId,
            '--tracking-id' => 'DSR-ANON-1',
        ])->assertSuccessful();

        $node = Node::findOrFail($this->nodeId);
        $this->assertStringStartsWith('https://dsr-anonymized.invalid/node-', $node->endpoint);
        $this->assertNull($node->operator_pubkey);
        $this->assertFalse((bool) $node->operator_verified);
        $this->assertNull($node->observed_source_ip);
        $this->assertNull($node->policy_manifest);
        $this->assertNull($node->cx_public_key);
        $this->assertNotNull($node->node_token_hash);

        $this->assertSame(0, DB::table('node_address_history')->where('node_id', $this->nodeId)->count());
        $this->assertSame(0, DB::table('iicp_telemetry_probes')->where('node_id', $this->nodeId)->count());
        $this->assertSame(0, DB::table('proxy_telemetry')->where('node_id', $this->nodeId)->count());
        $this->assertSame(1, CreditTransaction::where('node_id', $this->nodeId)->count(), 'credit ledger is retained');
        $this->assertSame(1, NodeEvent::where('node_id', $this->nodeId)->count(), 'signed event log is retained');

        $this->assertSame(0, Operator::where('operator_pubkey', $this->operatorPubkey)->count());
        $this->assertSame(1, Operator::where('operator_pubkey', 'like', 'dsr_%')->where('identity_status', 'restricted')->count());
        $this->assertSame(1, DataSubjectAction::where('tracking_id', 'DSR-ANON-1')->count());
    }

    public function test_dry_run_does_not_mutate_records(): void
    {
        $this->artisan('iicp:dsr', [
            'action' => 'anonymize',
            '--node-id' => $this->nodeId,
            '--tracking-id' => 'DSR-DRY-1',
            '--dry-run' => true,
        ])->assertSuccessful();

        $node = Node::findOrFail($this->nodeId);
        $this->assertSame('https://dsr-node.example.com', $node->endpoint);
        $this->assertSame($this->operatorPubkey, $node->operator_pubkey);
        $this->assertSame(1, DB::table('node_address_history')->where('node_id', $this->nodeId)->count());
        $this->assertSame(0, DataSubjectAction::where('tracking_id', 'DSR-DRY-1')->count());
    }
}
