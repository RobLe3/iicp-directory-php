<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationLimitMeasurementTest extends TestCase
{
    use RefreshDatabase;

    private array $scenarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('iicp.registry.max_active_nodes_per_source_ip', 100);
        Http::fake(['*/iicp/health' => Http::response('ok', 200)]);
    }

    public function test_disposable_registration_and_heartbeat_boundaries(): void
    {
        $this->scenarios['fresh_registration'] = $this->measureFreshRegistration();
        $this->scenarios['authenticated_reregistration'] = $this->measureReregistration();
        $this->scenarios['heartbeat'] = $this->measureHeartbeat();
        $this->scenarios['malformed_registration'] = $this->measureMalformedRegistration();

        foreach ($this->scenarios as $scenario) {
            $this->assertTrue($scenario['expected_boundary_observed']);
        }
        $this->writeEvidence();
    }

    private function measureFreshRegistration(): array
    {
        Cache::flush();
        $started = hrtime(true);
        $responses = [];
        for ($index = 1; $index <= 61; $index++) {
            $responses[] = $this->postJson('/api/v1/register', $this->payload("fresh-$index"));
        }

        return $this->summary($responses, $started, ['201' => 60, '429' => 1]);
    }

    private function measureReregistration(): array
    {
        Cache::flush();
        $first = $this->postJson('/api/v1/register', $this->payload('reregister'));
        $first->assertStatus(201);
        $token = $first->json('node_token');
        Cache::flush();
        $started = hrtime(true);
        $responses = [];
        for ($index = 1; $index <= 61; $index++) {
            $response = $this->postJson('/api/v1/register', array_merge(
                $this->payload('reregister'),
                ['current_node_token' => $token],
            ));
            $responses[] = $response;
            if ($response->status() === 201) {
                $token = $response->json('node_token');
            }
        }

        return $this->summary($responses, $started, ['201' => 60, '429' => 1]);
    }

    private function measureHeartbeat(): array
    {
        Cache::flush();
        $first = $this->postJson('/api/v1/register', $this->payload('heartbeat'));
        $first->assertStatus(201);
        Cache::flush();
        $started = hrtime(true);
        $responses = [];
        for ($index = 1; $index <= 61; $index++) {
            $responses[] = $this->withToken($first->json('node_token'))->postJson('/api/v1/heartbeat', [
                'node_id' => 'measure-heartbeat',
                'load' => 0.1,
                'active_jobs' => 0,
            ]);
        }

        return $this->summary($responses, $started, ['200' => 60, '429' => 1]);
    }

    private function measureMalformedRegistration(): array
    {
        Cache::flush();
        $started = hrtime(true);
        $responses = [];
        for ($index = 1; $index <= 61; $index++) {
            $responses[] = $this->postJson('/api/v1/register', ['region' => 'invalid only']);
        }

        return $this->summary($responses, $started, ['422' => 60, '429' => 1]);
    }

    private function payload(string $suffix): array
    {
        return [
            'node_id' => "measure-$suffix",
            'endpoint' => "https://$suffix.measurement.invalid",
            'region' => 'measurement',
            'capabilities' => [[
                'intent' => 'urn:iicp:intent:llm:chat:v1',
                'models' => ['synthetic-model'],
                'max_tokens' => 1024,
            ]],
            'limits' => ['max_concurrent' => 1, 'tokens_per_min' => 100],
        ];
    }

    /** @param list<TestResponse> $responses */
    private function summary(array $responses, int $started, array $expected): array
    {
        $counts = [];
        foreach ($responses as $response) {
            $status = (string) $response->status();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);

        return [
            'attempts' => count($responses),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'status_counts' => $counts,
            'expected_boundary_observed' => $counts === $expected,
        ];
    }

    private function writeEvidence(): void
    {
        $output = getenv('IICP_REGISTRATION_MEASUREMENT_OUTPUT');
        if (! is_string($output) || $output === '') {
            return;
        }
        $record = json_decode(file_get_contents(base_path('evidence/registration-limit-measurement-v1.json')), true, 512, JSON_THROW_ON_ERROR);
        $record['status'] = 'disposable-project-measurement';
        $record['result_present'] = true;
        $record['measured_at_utc'] = now('UTC')->toIso8601ZuluString();
        $record['source_commit'] = trim((string) shell_exec('git rev-parse HEAD'));
        $record['scenarios'] = $this->scenarios;
        $record['observations'] = [
            'fresh_and_authenticated_reregistration_share_the_same_sixty_per_minute_source_ip_counter',
            'malformed_requests_consume_the_registration_counter_before_validation',
            'heartbeat_uses_a_separate_sixty_per_minute_node_counter',
            'shared_nat_fairness_and_external_operator_load_remain_unmeasured',
        ];
        $record['recommendation'] = 'collect_representative_evidence';
        file_put_contents($output, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
