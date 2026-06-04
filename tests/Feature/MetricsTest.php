<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    private function createNode(string $region, bool $active = true): Node
    {
        return Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://node.example.com',
            'region' => $region,
            'node_token_hash' => password_hash('tok', PASSWORD_BCRYPT),
            'max_concurrent' => 4,
            'tokens_per_min' => 10000,
            'available' => true,
            'load' => 0.1,
            'active_jobs' => 0,
            'last_seen' => $active ? now() : now()->subMinutes(5),
        ]);
    }

    public function test_metrics_returns_200_with_prometheus_content_type(): void
    {
        $response = $this->get('/api/metrics');
        $response->assertStatus(200);
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_metrics_contains_required_gauge_families(): void
    {
        $this->createNode('eu-central');
        $response = $this->get('/api/metrics');
        $body = $response->getContent();

        $this->assertStringContainsString('iicp_nodes_registered', $body);
        $this->assertStringContainsString('iicp_nodes_available', $body);
        $this->assertStringContainsString('iicp_heartbeat_age_seconds', $body);
        $this->assertStringContainsString('iicp_discovery_score_p50', $body);
    }

    public function test_metrics_nodes_registered_reflects_db(): void
    {
        $this->createNode('us-east');
        $this->createNode('us-east');
        $this->createNode('eu-central');

        $response = $this->get('/api/metrics');
        $body = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/iicp_nodes_registered\{region="us-east"\} 2/',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/iicp_nodes_registered\{region="eu-central"\} 1/',
            $body
        );
    }

    public function test_metrics_nodes_available_counts_only_recently_seen(): void
    {
        $this->createNode('eu-central', active: true);
        $this->createNode('eu-central', active: false); // stale — 5 min old

        $response = $this->get('/api/metrics');
        $body = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/iicp_nodes_available\{region="eu-central"\} 1/',
            $body
        );
    }

    public function test_metrics_has_help_and_type_lines(): void
    {
        $response = $this->get('/api/metrics');
        $body = $response->getContent();

        $this->assertStringContainsString('# HELP iicp_nodes_registered', $body);
        $this->assertStringContainsString('# TYPE iicp_nodes_registered gauge', $body);
        $this->assertStringContainsString('# HELP iicp_nodes_available', $body);
        $this->assertStringContainsString('# TYPE iicp_heartbeat_age_seconds summary', $body);
    }

    public function test_metrics_empty_db_returns_valid_prometheus_output(): void
    {
        $response = $this->get('/api/metrics');
        $response->assertStatus(200);
        $body = $response->getContent();

        // Must still have required metric families even with no data
        $this->assertStringContainsString('iicp_discovery_score_p50 0', $body);
    }

    public function test_metrics_region_label_newline_is_escaped(): void
    {
        // Regression: addslashes() does not escape \n — a malicious region could inject
        // arbitrary Prometheus metric lines. escapeLabelValue() must sanitize this.
        $this->createNode("eu-central\n# TYPE injected counter\ninjected 1");

        $response = $this->get('/api/metrics');
        $body = $response->getContent();

        // The injected metric line must NOT appear raw in the output
        $this->assertStringNotContainsString("injected 1\n", $body);
        // The newline must be escaped as \n
        $this->assertStringContainsString('\\n', $body);
    }
}
