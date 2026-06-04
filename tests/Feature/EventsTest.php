<?php

namespace Tests\Feature;

use App\Models\NodeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6 prerequisite: GET /api/v1/events event log tests.
 *
 * Spec: spec/iicp-federated-directory.md §3.4
 */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    private function createEvent(array $overrides = []): NodeEvent
    {
        static $seq = 0;
        $seq++;

        return NodeEvent::create(array_merge([
            'event_id' => (string) Str::uuid(),
            'seq' => $seq,
            'event_type' => 'REGISTER',
            'node_id' => (string) Str::uuid(),
            'ts_ms' => now()->timestamp * 1000,
            'payload' => ['endpoint' => 'https://node.example.com'],
            'signature' => null,
        ], $overrides));
    }

    public function test_returns_empty_events_when_none_exist(): void
    {
        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200)
            ->assertJsonPath('count', 0)
            ->assertJsonPath('has_more', false)
            ->assertJsonStructure(['events', 'count', 'next_seq', 'has_more', 'genesis_hash', 'replica_lag_ms']);
    }

    public function test_returns_all_events_in_seq_order(): void
    {
        $this->createEvent(['event_type' => 'REGISTER']);
        $this->createEvent(['event_type' => 'HEARTBEAT']);
        $this->createEvent(['event_type' => 'DEREGISTER']);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200)->assertJsonPath('count', 3);
        $events = $response->json('events');
        $this->assertGreaterThan($events[0]['seq'], $events[1]['seq']);
        $this->assertGreaterThan($events[1]['seq'], $events[2]['seq']);
    }

    public function test_since_seq_filters_older_events(): void
    {
        $e1 = $this->createEvent(['seq' => 10]);
        $e2 = $this->createEvent(['seq' => 20]);
        $this->createEvent(['seq' => 30]);

        $response = $this->getJson('/api/v1/events?since_seq=20');

        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame(30, $response->json('events.0.seq'));
    }

    public function test_limit_parameter_caps_results(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->createEvent(['seq' => $i]);
        }

        $response = $this->getJson('/api/v1/events?limit=3');

        $response->assertStatus(200)
            ->assertJsonPath('count', 3)
            ->assertJsonPath('has_more', true);
    }

    public function test_event_types_filter_works(): void
    {
        $this->createEvent(['event_type' => 'REGISTER']);
        $this->createEvent(['event_type' => 'HEARTBEAT']);
        $this->createEvent(['event_type' => 'CREDIT_AWARD']);

        $response = $this->getJson('/api/v1/events?event_types=HEARTBEAT,CREDIT_AWARD');

        $response->assertStatus(200)->assertJsonPath('count', 2);
        $types = array_column($response->json('events'), 'event_type');
        $this->assertNotContains('REGISTER', $types);
    }

    public function test_invalid_event_type_returns_422(): void
    {
        $this->getJson('/api/v1/events?event_types=MADE_UP_TYPE')
            ->assertStatus(422);
    }

    // S.13 §5.1: event envelope MUST have sig + signer_did (spec-compliant field names)
    public function test_response_includes_spec_compliant_event_envelope_fields(): void
    {
        $this->createEvent([
            'event_type' => 'REGISTER',
            'signature' => str_repeat('ab', 64),
        ]);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200);
        $event = $response->json('events.0');
        $this->assertArrayHasKey('event_id', $event);
        $this->assertArrayHasKey('seq', $event);
        $this->assertArrayHasKey('event_type', $event);
        $this->assertArrayHasKey('node_id', $event);
        $this->assertArrayHasKey('ts_ms', $event);
        $this->assertArrayHasKey('payload', $event);
        // S.13 §5.1: field names are sig + signer_did (not signature)
        $this->assertArrayHasKey('sig', $event, 'S.13 §5.1: envelope field must be sig, not signature');
        $this->assertArrayHasKey('signer_did', $event, 'S.13 §5.1: signer_did must be present for replica verification');
        $this->assertArrayNotHasKey('signature', $event, 'S.13 §5.1: legacy signature field must not appear in output');
        $this->assertStringStartsWith('did:web:', $event['signer_did']);
    }

    public function test_next_seq_matches_highest_seq_returned(): void
    {
        $this->createEvent(['seq' => 5]);
        $this->createEvent(['seq' => 10]);
        $this->createEvent(['seq' => 15]);

        $response = $this->getJson('/api/v1/events?limit=2');

        $response->assertStatus(200);
        $this->assertSame(10, $response->json('next_seq'));
    }

    // DIR-FED-07: genesis_hash is present and stable across requests
    public function test_genesis_hash_is_sha256_of_first_event_payload(): void
    {
        $payload = ['endpoint' => 'https://genesis.example.com'];
        $this->createEvent(['seq' => 1, 'payload' => $payload]);
        $this->createEvent(['seq' => 2]);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200);
        $genesisHash = $response->json('genesis_hash');
        $this->assertNotNull($genesisHash, 'DIR-FED-07: genesis_hash must be present');
        $expected = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame($expected, $genesisHash, 'DIR-FED-07: genesis_hash must be SHA256 of seq=1 payload');
    }

    // replica_lag_ms: present and non-negative
    public function test_replica_lag_ms_is_present_and_non_negative(): void
    {
        $this->createEvent(['ts_ms' => now()->timestamp * 1000]);

        $response = $this->getJson('/api/v1/events');

        $response->assertStatus(200);
        $lag = $response->json('replica_lag_ms');
        $this->assertIsInt($lag, 'replica_lag_ms must be an integer');
        $this->assertGreaterThanOrEqual(0, $lag, 'replica_lag_ms must be non-negative');
    }

    // replica_lag_ms must reflect the absolute latest event, not just the returned page
    public function test_replica_lag_ms_uses_db_wide_latest_not_page_latest(): void
    {
        $oldTs = (now()->timestamp - 3600) * 1000; // 1 hour ago
        $nowTs = now()->timestamp * 1000;

        $this->createEvent(['seq' => 1, 'ts_ms' => $oldTs]);   // genesis
        $this->createEvent(['seq' => 2, 'ts_ms' => $nowTs]);   // latest in DB

        // Paginate — limit=1 returns only seq=1 (the old event)
        $response = $this->getJson('/api/v1/events?limit=1');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('count'));

        // lag must reflect DB-wide latest (seq=2, ts=now), NOT seq=1 (1 hour ago)
        // If it used the page's last event, lag would be ~3600000ms — a regression
        $lag = $response->json('replica_lag_ms');
        $this->assertLessThan(5000, $lag, 'replica_lag_ms must use DB-wide latest (seq=2 ts=now), not page last (seq=1 ts=1h ago)');
    }

    public function test_since_seq_validation_rejects_negative(): void
    {
        $this->getJson('/api/v1/events?since_seq=-1')->assertStatus(422);
    }

    public function test_limit_validation_rejects_over_max(): void
    {
        $this->getJson('/api/v1/events?limit=1001')->assertStatus(422);
    }

    public function test_register_emits_register_event(): void
    {
        Http::fake(['https://node.example.com/iicp/health' => Http::response('ok', 200)]);

        $payload = [
            'endpoint' => 'https://node.example.com',
            'region' => 'eu-central',
            'capabilities' => [['intent' => 'urn:iicp:intent:llm:chat:v1', 'models' => ['m'], 'max_tokens' => 4096]],
            'limits' => ['max_concurrent' => 4, 'tokens_per_min' => 10000],
        ];

        $this->postJson('/api/v1/register', $payload)->assertStatus(201);

        $response = $this->getJson('/api/v1/events?event_types=REGISTER');
        $response->assertStatus(200)->assertJsonPath('count', 1);
        $this->assertSame('REGISTER', $response->json('events.0.event_type'));
    }
}
