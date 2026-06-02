<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\Alerts;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\Alerts\AlertThresholds;
use SugarCraft\Toast\Position;

/**
 * @covers \SugarCraft\Query\Admin\Alerts\AlertThresholds
 */
final class AlertThresholdsTest extends TestCase
{
    public function testNewReturnsDefaultValues(): void
    {
        $t = AlertThresholds::new();

        // Default values from constructor
        $this->assertSame(0.6, $t->connectionWarningThreshold());
        $this->assertSame(0.8, $t->connectionCriticalThreshold());
        $this->assertSame(0.05, $t->abortedRateThreshold());
        $this->assertSame(5.0, $t->slowQueryThreshold());
        $this->assertSame(0.5, $t->threadRunningThreshold());
        $this->assertSame(100, $t->connectionErrorsThreshold());
        $this->assertSame([], $t->watchedMetrics());
        $this->assertTrue($t->toastEnabled());
        $this->assertSame(Position::TopRight, $t->toastPosition());
        $this->assertSame(5.0, $t->toastDuration());
    }

    public function testDefaultFactoryHasExpectedValues(): void
    {
        $t = AlertThresholds::default();

        $this->assertSame(0.6, $t->connectionWarningThreshold());
        $this->assertSame(0.8, $t->connectionCriticalThreshold());
        $this->assertSame(0.05, $t->abortedRateThreshold());
        $this->assertSame(5.0, $t->slowQueryThreshold());
        $this->assertSame(0.5, $t->threadRunningThreshold());
    }

    public function testStrictFactoryHasExpectedValues(): void
    {
        $t = AlertThresholds::strict();

        $this->assertSame(0.5, $t->connectionWarningThreshold());
        $this->assertSame(0.7, $t->connectionCriticalThreshold());
        $this->assertSame(0.01, $t->abortedRateThreshold());
        $this->assertSame(1.0, $t->slowQueryThreshold());
        $this->assertSame(0.3, $t->threadRunningThreshold());
    }

    public function testWithConnectionWarningThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withConnectionWarningThreshold(0.7);

        // Original unchanged
        $this->assertSame(0.6, $t1->connectionWarningThreshold());
        // New value set
        $this->assertSame(0.7, $t2->connectionWarningThreshold());
    }

    public function testWithConnectionWarningThresholdRejectsOutOfRange(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withConnectionWarningThreshold(1.5);
    }

    public function testWithConnectionWarningThresholdRejectsNegative(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withConnectionWarningThreshold(-0.1);
    }

    public function testWithConnectionCriticalThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withConnectionCriticalThreshold(0.9);

        $this->assertSame(0.8, $t1->connectionCriticalThreshold());
        $this->assertSame(0.9, $t2->connectionCriticalThreshold());
    }

    public function testWithAbortedRateThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withAbortedRateThreshold(0.1);

        $this->assertSame(0.05, $t1->abortedRateThreshold());
        $this->assertSame(0.1, $t2->abortedRateThreshold());
    }

    public function testWithAbortedRateThresholdAcceptsZero(): void
    {
        $t = AlertThresholds::new()->withAbortedRateThreshold(0.0);
        $this->assertSame(0.0, $t->abortedRateThreshold());
    }

    public function testWithAbortedRateThresholdRejectsNegative(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withAbortedRateThreshold(-0.01);
    }

    public function testWithSlowQueryThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withSlowQueryThreshold(10.0);

        $this->assertSame(5.0, $t1->slowQueryThreshold());
        $this->assertSame(10.0, $t2->slowQueryThreshold());
    }

    public function testWithSlowQueryThresholdAcceptsZero(): void
    {
        $t = AlertThresholds::new()->withSlowQueryThreshold(0.0);
        $this->assertSame(0.0, $t->slowQueryThreshold());
    }

    public function testWithSlowQueryThresholdRejectsNegative(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withSlowQueryThreshold(-1.0);
    }

    public function testWithThreadRunningThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withThreadRunningThreshold(0.75);

        $this->assertSame(0.5, $t1->threadRunningThreshold());
        $this->assertSame(0.75, $t2->threadRunningThreshold());
    }

    public function testWithThreadRunningThresholdRejectsOutOfRange(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withThreadRunningThreshold(1.5);
    }

    public function testWithConnectionErrorsThresholdReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withConnectionErrorsThreshold(200);

        $this->assertSame(100, $t1->connectionErrorsThreshold());
        $this->assertSame(200, $t2->connectionErrorsThreshold());
    }

    public function testWithConnectionErrorsThresholdAcceptsZero(): void
    {
        $t = AlertThresholds::new()->withConnectionErrorsThreshold(0);
        $this->assertSame(0, $t->connectionErrorsThreshold());
    }

    public function testWithConnectionErrorsThresholdRejectsNegative(): void
    {
        $t = AlertThresholds::new();

        $this->expectException(\InvalidArgumentException::class);
        $t->withConnectionErrorsThreshold(-1);
    }

    public function testWithWatchedMetricsReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withWatchedMetrics(['connection_usage', 'aborted_rate']);

        $this->assertSame([], $t1->watchedMetrics());
        $this->assertSame(['connection_usage', 'aborted_rate'], $t2->watchedMetrics());
    }

    public function testWatchesReturnsTrueWhenEmptyWatchedMetrics(): void
    {
        $t = AlertThresholds::new()->withWatchedMetrics([]);

        $this->assertTrue($t->watches('connection_usage'));
        $this->assertTrue($t->watches('aborted_rate'));
        $this->assertTrue($t->watches('anything'));
    }

    public function testWatchesReturnsTrueForListedMetric(): void
    {
        $t = AlertThresholds::new()->withWatchedMetrics(['connection_usage', 'slow_query']);

        $this->assertTrue($t->watches('connection_usage'));
        $this->assertTrue($t->watches('slow_query'));
    }

    public function testWatchesReturnsFalseForUnlistedMetric(): void
    {
        $t = AlertThresholds::new()->withWatchedMetrics(['connection_usage']);

        $this->assertFalse($t->watches('slow_query'));
        $this->assertFalse($t->watches('aborted_rate'));
    }

    public function testWithToastEnabledReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withToastEnabled(false);

        $this->assertTrue($t1->toastEnabled());
        $this->assertFalse($t2->toastEnabled());
    }

    public function testWithToastPositionReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withToastPosition(Position::BottomLeft);

        $this->assertSame(Position::TopRight, $t1->toastPosition());
        $this->assertSame(Position::BottomLeft, $t2->toastPosition());
    }

    public function testWithToastDurationReturnsNewInstance(): void
    {
        $t1 = AlertThresholds::new();
        $t2 = $t1->withToastDuration(10.0);
        $t3 = $t1->withToastDuration(null);

        $this->assertSame(5.0, $t1->toastDuration());
        $this->assertSame(10.0, $t2->toastDuration());
        $this->assertNull($t3->toastDuration());
    }

    public function testImmutabilityWithChainedWithMethods(): void
    {
        $original = AlertThresholds::new();
        $chained = $original
            ->withConnectionWarningThreshold(0.75)
            ->withConnectionCriticalThreshold(0.9)
            ->withAbortedRateThreshold(0.1)
            ->withConnectionErrorsThreshold(200)
            ->withToastEnabled(false)
            ->withToastPosition(Position::BottomCenter)
            ->withToastDuration(15.0);

        // Original unchanged
        $this->assertSame(0.6, $original->connectionWarningThreshold());
        $this->assertSame(0.8, $original->connectionCriticalThreshold());
        $this->assertSame(0.05, $original->abortedRateThreshold());
        $this->assertSame(100, $original->connectionErrorsThreshold());
        $this->assertTrue($original->toastEnabled());
        $this->assertSame(Position::TopRight, $original->toastPosition());
        $this->assertSame(5.0, $original->toastDuration());

        // New values set
        $this->assertSame(0.75, $chained->connectionWarningThreshold());
        $this->assertSame(0.9, $chained->connectionCriticalThreshold());
        $this->assertSame(0.1, $chained->abortedRateThreshold());
        $this->assertSame(200, $chained->connectionErrorsThreshold());
        $this->assertFalse($chained->toastEnabled());
        $this->assertSame(Position::BottomCenter, $chained->toastPosition());
        $this->assertSame(15.0, $chained->toastDuration());
    }

    public function testDefaultAndStrictFactoriesAreDistinct(): void
    {
        $default = AlertThresholds::default();
        $strict = AlertThresholds::strict();

        $this->assertNotSame($default->connectionWarningThreshold(), $strict->connectionWarningThreshold());
        $this->assertNotSame($default->connectionCriticalThreshold(), $strict->connectionCriticalThreshold());
        $this->assertNotSame($default->abortedRateThreshold(), $strict->abortedRateThreshold());
        $this->assertNotSame($default->slowQueryThreshold(), $strict->slowQueryThreshold());
        $this->assertNotSame($default->threadRunningThreshold(), $strict->threadRunningThreshold());

        // Strict should always be <= default for each threshold
        $this->assertLessThanOrEqual($default->connectionWarningThreshold(), $strict->connectionWarningThreshold());
        $this->assertLessThanOrEqual($default->connectionCriticalThreshold(), $strict->connectionCriticalThreshold());
    }
}
