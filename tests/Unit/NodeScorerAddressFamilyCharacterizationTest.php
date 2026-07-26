<?php

namespace Tests\Unit;

use App\Services\AvailabilityWindowPolicy;
use App\Services\CapabilityEvidencePolicy;
use App\Services\NodeEligibilityPolicy;
use App\Services\NodeHealthService;
use App\Services\NodeReadinessPolicy;
use App\Services\NodeScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NodeScorerAddressFamilyCharacterizationTest extends TestCase
{
    #[DataProvider('endpointFamilies')]
    public function test_current_endpoint_family_contract(
        ?string $endpoint,
        ?string $transportEndpoint,
        string $expected,
    ): void {
        $scorer = new NodeScorer(
            $this->createMock(NodeHealthService::class),
            new CapabilityEvidencePolicy,
            new AvailabilityWindowPolicy,
            new NodeReadinessPolicy,
            new NodeEligibilityPolicy,
        );
        $method = new ReflectionMethod($scorer, 'detectAddressFamily');

        $this->assertSame($expected, $method->invoke($scorer, $endpoint, $transportEndpoint));
    }

    public static function endpointFamilies(): array
    {
        return [
            'no endpoints' => [null, null, 'unknown'],
            'malformed endpoints' => ['not a URL', '', 'unknown'],
            'IPv4 primary' => ['https://192.0.2.10:8080', null, 'ipv4'],
            'bracketed IPv6 primary' => ['https://[2001:db8::10]:8080', null, 'ipv6'],
            'same family' => ['https://192.0.2.10', 'tcp://198.51.100.4:9000', 'ipv4'],
            'opposite families' => ['https://192.0.2.10', 'tcp://[2001:db8::10]:9000', 'dual'],
            'DNS dominates a numeric transport' => ['https://node.example', 'tcp://192.0.2.10:9000', 'hostname'],
            'transport only' => [null, 'tcp://[2001:db8::10]:9000', 'ipv6'],
        ];
    }
}
