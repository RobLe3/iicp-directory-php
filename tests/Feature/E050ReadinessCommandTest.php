<?php

namespace Tests\Feature;

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

class E050ReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_is_aggregate_read_only_and_default_disabled(): void
    {
        $this->node('0.7.94', true);
        $this->node('0.7.58', false);
        $before = Node::count();
        Artisan::call('iicp:e050-readiness', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('iicp.e050_readiness.v1', $payload['schema']);
        $this->assertFalse($payload['strict_mode_enabled']);
        $this->assertSame(2, $payload['total_heartbeating']);
        $this->assertSame(1, $payload['token_capable']);
        $this->assertSame(1, $payload['secured_nodes']);
        $this->assertSame(1, $payload['hypothetical_tokenless_secured_reregistration_rejections']);
        $this->assertFalse($payload['authorizes_cutover']);
        $this->assertSame($before, Node::count());
        foreach (['node_id', 'endpoint', 'operator_pubkey', 'cx_public_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
    }

    private function node(string $version, bool $secured): void
    {
        Node::create([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://'.Str::random(8).'.example.com',
            'region' => 'eu-central',
            'available' => true,
            'status' => 'active',
            'last_seen' => now(),
            'sdk_version' => $version,
            'cx_public_key' => $secured ? ['algorithm' => 'X25519', 'key' => 'opaque'] : null,
            'node_token_hash' => password_hash('test', PASSWORD_BCRYPT),
            'max_concurrent' => 1,
            'tokens_per_min' => 1,
        ]);
    }
}
