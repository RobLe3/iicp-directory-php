<?php

namespace Tests\Unit;

use App\Services\EndpointTlsPolicy;
use Tests\TestCase;

class EndpointTlsPolicyTest extends TestCase
{
    public function test_production_cannot_enable_insecure_tls(): void
    {
        config([
            'app.env' => 'production',
            'iicp.registry.dev_allow_insecure_tls' => true,
        ]);

        $this->assertFalse(app(EndpointTlsPolicy::class)->allowInsecureTestbed());
    }

    public function test_explicit_non_production_testbed_can_enable_insecure_tls(): void
    {
        config([
            'app.env' => 'testing',
            'iicp.registry.dev_allow_insecure_tls' => true,
        ]);

        $this->assertTrue(app(EndpointTlsPolicy::class)->allowInsecureTestbed());
    }

    public function test_non_production_default_still_verifies_tls(): void
    {
        config([
            'app.env' => 'testing',
            'iicp.registry.dev_allow_insecure_tls' => false,
        ]);

        $this->assertFalse(app(EndpointTlsPolicy::class)->allowInsecureTestbed());
    }
}
