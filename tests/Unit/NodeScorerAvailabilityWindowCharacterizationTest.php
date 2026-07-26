<?php

namespace Tests\Unit;

use App\Models\AvailabilityWindow;
use App\Models\Node;
use App\Services\CapabilityEvidencePolicy;
use App\Services\NodeHealthService;
use App\Services\NodeScorer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class NodeScorerAvailabilityWindowCharacterizationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('availabilityCases')]
    public function test_current_availability_window_contract(
        string $now,
        array $windows,
        float $expected,
    ): void {
        Carbon::setTestNow(Carbon::parse($now));
        $node = new Node;
        $node->setRelation('availabilityWindows', new Collection(array_map(
            fn (array $window) => new AvailabilityWindow($window),
            $windows,
        )));
        $scorer = new NodeScorer(
            $this->createMock(NodeHealthService::class),
            new CapabilityEvidencePolicy,
        );
        $method = new ReflectionMethod($scorer, 'computeAvailability');

        $this->assertSame($expected, $method->invoke($scorer, $node));
    }

    public static function availabilityCases(): array
    {
        return [
            'no windows defaults to fully available' => [
                '2026-07-26 12:00:00',
                [],
                1.0,
            ],
            'inside a window returns its share' => [
                '2026-07-26 12:00:00',
                [['start_time' => '11:00:00', 'end_time' => '13:00:00', 'share' => 0.75]],
                0.75,
            ],
            'start boundary is inclusive' => [
                '2026-07-26 11:00:00',
                [['start_time' => '11:00:00', 'end_time' => '13:00:00', 'share' => 0.4]],
                0.4,
            ],
            'end boundary is inclusive' => [
                '2026-07-26 13:00:00',
                [['start_time' => '11:00:00', 'end_time' => '13:00:00', 'share' => 0.4]],
                0.4,
            ],
            'outside every window uses the neutral fallback' => [
                '2026-07-26 14:00:00',
                [['start_time' => '11:00:00', 'end_time' => '13:00:00', 'share' => 0.75]],
                0.5,
            ],
            'first overlapping window wins' => [
                '2026-07-26 12:00:00',
                [
                    ['start_time' => '10:00:00', 'end_time' => '13:00:00', 'share' => 0.25],
                    ['start_time' => '11:00:00', 'end_time' => '14:00:00', 'share' => 0.9],
                ],
                0.25,
            ],
        ];
    }
}
