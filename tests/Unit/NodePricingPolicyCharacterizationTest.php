<?php

// SPDX-License-Identifier: Apache-2.0

namespace Tests\Unit;

use App\Services\JwtService;
use App\Services\NodeEndpointVerifier;
use App\Services\NodePricingPolicy;
use App\Services\NodeRegistrationPersistence;
use App\Services\NodeRegistry;
use App\Services\OperatorDelegationVerifier;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NodePricingPolicyCharacterizationTest extends TestCase
{
    public function test_empty_pricing_preserves_defaults(): void
    {
        $this->assertSame([
            'credit_cost_multiplier' => 1.0,
            'pricing_model' => 'per_token',
            'declaration_signature' => null,
            'attested' => false,
            'effective_from' => null,
            'effective_until' => null,
        ], $this->registry()->resolvePricingBlock([], 'unused-key'));
    }

    public function test_valid_canonical_signature_and_effective_dates_are_preserved(): void
    {
        $key = 'pricing-hmac-key';
        $body = ['credit_cost_multiplier' => 2.0, 'pricing_model' => 'per_request'];
        ksort($body);
        $signature = hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), $key);

        $this->assertSame([
            'credit_cost_multiplier' => 2.0,
            'pricing_model' => 'per_request',
            'declaration_signature' => $signature,
            'attested' => true,
            'effective_from' => '2026-07-01T00:00:00Z',
            'effective_until' => '2026-08-01T00:00:00Z',
        ], $this->registry()->resolvePricingBlock([
            'pricing_model' => 'per_request',
            'credit_cost_multiplier' => 2,
            'declaration_signature' => $signature,
            'effective_from' => '2026-07-01T00:00:00Z',
            'effective_until' => '2026-08-01T00:00:00Z',
        ], $key));
    }

    public function test_invalid_signature_preserves_validation_path_and_message(): void
    {
        try {
            $this->registry()->resolvePricingBlock([
                'credit_cost_multiplier' => 1.0,
                'pricing_model' => 'per_token',
                'declaration_signature' => 'invalid',
            ], 'pricing-hmac-key');
            $this->fail('Expected invalid pricing signature to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['pricing.declaration_signature' => ['Invalid declaration signature (IICP-E010)']],
                $exception->errors(),
            );
        }
    }

    #[DataProvider('modelTierCases')]
    public function test_model_tier_boundaries_and_fallback_are_preserved(array $models, string $expected): void
    {
        $this->assertSame($expected, NodeRegistry::classifyModelTier($models));
    }

    public static function modelTierCases(): array
    {
        return [
            'empty fallback' => [[], '7b'],
            'unparseable fallback' => [['custom-model'], '7b'],
            'sub one billion' => [['qwen:0.5b'], 'sub_1b'],
            'one billion boundary' => [['model-1b'], '7b'],
            'below ten billion' => [['model-9.9B'], '7b'],
            'ten billion boundary' => [['model-10b'], '13b'],
            'twenty billion boundary' => [['model-20b'], '30b'],
            'fifty billion boundary' => [['model-50b'], '70b'],
            'eighty five billion boundary' => [['model-85b'], '100b_plus'],
            'highest recognized model wins' => [['model-0.5b', 'model-13b', 'model-70b'], '70b'],
        ];
    }

    #[DataProvider('ceilingCases')]
    public function test_compute_tier_multiplier_ceilings_are_preserved(
        array $models,
        float $declared,
        float $expected,
    ): void {
        $resolved = $this->registry()->resolvePricingBlock([
            'credit_cost_multiplier' => $declared,
        ], 'unused-key', $models);

        $this->assertEqualsWithDelta($expected, $resolved['credit_cost_multiplier'], 0.000001);
    }

    public static function ceilingCases(): array
    {
        return [
            'sub one billion clamps to point fifteen' => [['model-0.5b'], 100.0, 0.15],
            'seven billion clamps to three' => [['model-7b'], 100.0, 3.0],
            'thirteen billion clamps to six' => [['model-13b'], 100.0, 6.0],
            'thirty billion clamps to nineteen point five' => [['model-30b'], 100.0, 19.5],
            'seventy billion clamps to ninety six' => [['model-70b'], 100.0, 96.0],
            'hundred billion remains below ceiling' => [['model-100b'], 100.0, 100.0],
            'value below tier ceiling is unchanged' => [['model-0.5b'], 0.1, 0.1],
            'unrecognized model uses fallback tier ceiling' => [['custom-model'], 100.0, 3.0],
        ];
    }

    private function registry(): NodeRegistry
    {
        return new NodeRegistry(
            $this->createMock(JwtService::class),
            $this->createMock(OperatorDelegationVerifier::class),
            new NodePricingPolicy,
            new NodeEndpointVerifier,
            app(NodeRegistrationPersistence::class),
        );
    }
}
