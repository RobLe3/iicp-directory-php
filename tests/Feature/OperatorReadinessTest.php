<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperatorReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_is_true_only_with_a_current_database(): void
    {
        $this->getJson('/iicp/ready')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'ok' => true,
                'role' => 'directory',
                'ready' => true,
            ]);
    }

    public function test_readiness_fails_closed_without_leaking_database_details(): void
    {
        Schema::drop('migrations');

        $response = $this->getJson('/iicp/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'ok' => false,
                'role' => 'directory',
                'ready' => false,
            ]);

        $rendered = $response->getContent();
        $this->assertStringNotContainsString('database', strtolower($rendered));
        $this->assertStringNotContainsString('migration', strtolower($rendered));
        $this->assertStringNotContainsString('exception', strtolower($rendered));
    }
}
