<?php

namespace Tests\Feature;

use App\Http\Controllers\AuditReportController;
use App\Models\Node;
use App\Models\NodeEvent;
use App\Models\Reputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditReportTest extends TestCase
{
    use RefreshDatabase;

    private string $reporterToken;

    private Node $reporter;

    private Node $target;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->reporterToken = Str::random(40);
        $this->reporter = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://reporter.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($this->reporterToken, PASSWORD_BCRYPT),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now()->subSeconds(10),
            'reputation_score' => 0.6, // >= AUDIT_MIN_REPUTATION (0.55)
        ]);
        // Backdate to satisfy AUDIT_MIN_AGE_DAYS (RT-05b, #383)
        DB::table('nodes')->where('id', $this->reporter->id)->update([
            'created_at' => now()->subDays(AuditReportController::AUDIT_MIN_AGE_DAYS + 1),
        ]);

        $this->target = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://target.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash(Str::random(40), PASSWORD_BCRYPT),
            'max_concurrent' => 2,
            'tokens_per_min' => 5000,
            'available' => true,
            'last_seen' => now()->subSeconds(10),
        ]);
    }

    public function test_audit_report_is_accepted_without_changing_outcome_reputation(): void
    {
        Reputation::create([
            'node_id' => $this->target->id,
            'score' => 0.80,
            'tasks_total' => 0,
            'tasks_failed' => 0,
            'completed_tasks_count' => 0,
            'avg_latency_ms' => 0.0,
        ]);

        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202)
            ->assertJson(['accepted' => true]);

        $rep = Reputation::where('node_id', $this->target->id)->first();
        $this->assertEqualsWithDelta(0.80, $rep->score, 0.001);
    }

    public function test_audit_report_emits_event(): void
    {
        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202);

        $event = NodeEvent::where('event_type', 'AUDIT_REPORT')
            ->where('node_id', $this->target->id)
            ->first();

        $this->assertNotNull($event);
        $payload = $event->payload;
        $this->assertEquals($this->reporter->id, $payload['reporter_node_id']);
        $this->assertEquals('declaration_divergence', $payload['finding']);
        $this->assertEqualsWithDelta(0.0, $payload['reputation_delta'], 0.001);
        $this->assertTrue($payload['integrity_evidence_accepted']);
    }

    public function test_audit_report_preserves_low_outcome_reputation(): void
    {
        Reputation::create([
            'node_id' => $this->target->id,
            'score' => 0.02,
            'tasks_total' => 0,
            'tasks_failed' => 0,
            'completed_tasks_count' => 0,
            'avg_latency_ms' => 0.0,
        ]);

        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202);

        $rep = Reputation::where('node_id', $this->target->id)->first();
        $this->assertEquals(0.02, $rep->score);
    }

    public function test_audit_report_rate_limited_on_second_report(): void
    {
        // First report should succeed
        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202);

        // Second report about same target within 24h should be rate-limited
        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(429)
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('reason', 'rate_limited');
    }

    public function test_audit_report_self_report_rejected(): void
    {
        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->reporter->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_target');
    }

    public function test_audit_report_requires_auth(): void
    {
        $this->postJson('/api/v1/audit-report', [
            'target_node_id' => $this->target->id,
            'finding' => 'declaration_divergence',
        ])
            ->assertStatus(401);
    }

    public function test_audit_report_rejects_unknown_finding(): void
    {
        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'made_up_finding',
            ])
            ->assertStatus(422);
    }

    /** RT-05: only two distinct reporters per day may contribute integrity evidence. */
    public function test_audit_report_griefing_cap_suppresses_integrity_evidence_beyond_limit(): void
    {
        Reputation::create([
            'node_id' => $this->target->id,
            'score' => 0.80,
            'tasks_total' => 0, 'tasks_failed' => 0,
            'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0,
        ]);

        // Two distinct eligible reporters each file once; outcome reputation is unchanged.
        foreach (['reporter-b-token-40-chars-exactly!!', 'reporter-c-token-40-chars-exactly!!'] as $token) {
            $node = Node::create([
                'id' => (string) Str::uuid(),
                'endpoint' => 'https://extra.example.com',
                'region' => 'eu-central',
                'node_token_hash' => password_hash($token, PASSWORD_BCRYPT),
                'max_concurrent' => 1, 'tokens_per_min' => 1000,
                'available' => true, 'last_seen' => now()->subSeconds(5),
                'reputation_score' => 0.6,
            ]);
            // Backdate to satisfy AUDIT_MIN_AGE_DAYS (RT-05b)
            DB::table('nodes')->where('id', $node->id)->update([
                'created_at' => now()->subDays(AuditReportController::AUDIT_MIN_AGE_DAYS + 1),
            ]);
            $this->withToken($token)
                ->postJson('/api/v1/audit-report', [
                    'node_id' => $node->id,
                    'target_node_id' => $this->target->id,
                    'finding' => 'declaration_divergence',
                ])
                ->assertStatus(202);
        }

        $scoreAfterTwo = (float) Reputation::where('node_id', $this->target->id)->value('score');
        $this->assertEquals(0.80, $scoreAfterTwo);

        // Third eligible reporter — accepted (202) but delta suppressed (cap reached).
        $thirdToken = 'reporter-d-token-40-chars-exactly!!';
        $thirdNode = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://third.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($thirdToken, PASSWORD_BCRYPT),
            'max_concurrent' => 1, 'tokens_per_min' => 1000,
            'available' => true, 'last_seen' => now()->subSeconds(5),
            'reputation_score' => 0.6,
        ]);
        DB::table('nodes')->where('id', $thirdNode->id)->update([
            'created_at' => now()->subDays(AuditReportController::AUDIT_MIN_AGE_DAYS + 1),
        ]);
        $this->withToken($thirdToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $thirdNode->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true);

        // Score must NOT decrease further — RT-05 cap enforced.
        $scoreAfterThree = (float) Reputation::where('node_id', $this->target->id)->value('score');
        $this->assertEquals($scoreAfterTwo, $scoreAfterThree,
            'RT-05: score must not decrease past the 2-reporter-per-day cap');
    }

    /**
     * RT-05b bypass 1 (#383): fresh reporter (0 days old) must not carry weight.
     * Rotation attack: register new node, file audit — report accepted but delta suppressed.
     */
    public function test_rt05b_fresh_reporter_delta_suppressed(): void
    {
        Reputation::create([
            'node_id' => $this->target->id,
            'score' => 0.80,
            'tasks_total' => 0, 'tasks_failed' => 0,
            'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0,
        ]);

        // Create a fresh reporter (0 days old, default rep 0.5) — not eligible
        $freshToken = 'fresh-reporter-token-40-chars-xxx!!';
        $freshNode = Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://fresh-reporter.example.com',
            'region' => 'eu-central',
            'node_token_hash' => password_hash($freshToken, PASSWORD_BCRYPT),
            'max_concurrent' => 1, 'tokens_per_min' => 1000,
            'available' => true, 'last_seen' => now(),
            // reputation_score NOT set: default 0.5 < AUDIT_MIN_REPUTATION (0.55)
            // created_at NOT backdated: 0 days old < AUDIT_MIN_AGE_DAYS (3)
        ]);

        $this->withToken($freshToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $freshNode->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true); // accepted but delta suppressed

        $rep = Reputation::where('node_id', $this->target->id)->first();
        $this->assertEquals(0.80, round((float) $rep->score, 4),
            'RT-05b: fresh reporter must not apply delta (report accepted but weight=0)');
    }

    public function test_integrity_audit_does_not_mutate_outcome_reputation(): void
    {
        $this->target->update(['reputation_score' => 0.80]);
        Reputation::create([
            'node_id' => $this->target->id,
            'score' => 0.80,
            'tasks_total' => 0, 'tasks_failed' => 0,
            'completed_tasks_count' => 0, 'avg_latency_ms' => 0.0,
        ]);

        $this->withToken($this->reporterToken)
            ->postJson('/api/v1/audit-report', [
                'node_id' => $this->reporter->id,
                'target_node_id' => $this->target->id,
                'finding' => 'declaration_divergence',
            ])
            ->assertStatus(202)
            ->assertJsonPath('accepted', true);

        // outcome-v2 records the integrity finding separately.
        $repScore = (float) Reputation::where('node_id', $this->target->id)->value('score');
        $nodeScore = (float) $this->target->fresh()->reputation_score;
        $this->assertEqualsWithDelta(0.80, $repScore, 0.001);
        $this->assertEqualsWithDelta(0.80, $nodeScore, 0.001);
        $this->assertEquals($repScore, $nodeScore,
            'RT-05b: reputations.score and nodes.reputation_score must stay in sync');
    }
}
