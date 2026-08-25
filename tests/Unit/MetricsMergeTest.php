<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\services\SyncCoordinator;

/**
 * A breakdowns retry can succeed on a different subset of dimension types than an earlier
 * attempt succeeded on. syncDailyStats() must merge new metrics onto the stored blob, not
 * overwrite it, or a later partial success silently deletes earlier successful dimensions.
 */
#[CoversClass(SyncCoordinator::class)]
class MetricsMergeTest extends TestCase
{
    private \ReflectionMethod $mergeMetrics;

    protected function setUp(): void
    {
        $this->mergeMetrics = new \ReflectionMethod(SyncCoordinator::class, '_mergeMetrics');
    }

    public function testPartialRetryPreservesEarlierSuccessfulDimensions(): void
    {
        $existing = json_encode(['country' => ['US' => 10], 'browser' => ['Chrome' => 5]]);
        $newMetrics = ['referrer' => ['google.com' => 3]];

        $result = json_decode($this->mergeMetrics->invoke(null, $existing, $newMetrics), true);

        $this->assertSame(
            ['country' => ['US' => 10], 'browser' => ['Chrome' => 5], 'referrer' => ['google.com' => 3]],
            $result,
        );
    }

    public function testNewValueForSameTypeOverridesTheOldOne(): void
    {
        $existing = json_encode(['country' => ['US' => 10]]);
        $newMetrics = ['country' => ['US' => 15]];

        $result = json_decode($this->mergeMetrics->invoke(null, $existing, $newMetrics), true);

        $this->assertSame(['country' => ['US' => 15]], $result);
    }

    public function testNoExistingMetricsJustStoresTheNewOnes(): void
    {
        $result = json_decode($this->mergeMetrics->invoke(null, null, ['country' => ['US' => 10]]), true);

        $this->assertSame(['country' => ['US' => 10]], $result);
    }

    public function testMalformedExistingJsonIsDiscardedNotFatal(): void
    {
        $result = json_decode($this->mergeMetrics->invoke(null, 'not json', ['country' => ['US' => 10]]), true);

        $this->assertSame(['country' => ['US' => 10]], $result);
    }
}
