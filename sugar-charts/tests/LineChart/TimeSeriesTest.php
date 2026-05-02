<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\LineChart;

use CandyCore\Charts\LineChart\TimeSeries;
use PHPUnit\Framework\TestCase;

final class TimeSeriesTest extends TestCase
{
    public function testEmptyRendersEmptyCanvas(): void
    {
        $out = TimeSeries::new([], 10, 4)->view();
        $this->assertSame(4, substr_count($out, "\n") + 1);
    }

    public function testRendersAxesAndTimeLabels(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 12:00:00');
        $points = [];
        for ($i = 0; $i < 5; $i++) {
            $points[] = [$base->modify("+$i hours"), (float) ($i % 3)];
        }
        $out = TimeSeries::new($points, 30, 8)
            ->withXLabelCount(3)
            ->withTimeFormat('H:i')
            ->view();
        // Axis emitted (so '└' should appear).
        $this->assertStringContainsString('└', $out);
        // First and last labels appear.
        $this->assertStringContainsString('12:00', $out);
        $this->assertStringContainsString('16:00', $out);
    }

    public function testPushAppendsSample(): void
    {
        $base = new \DateTimeImmutable('2026-01-01 00:00:00');
        $ts = TimeSeries::new([])
            ->push($base, 1.0)
            ->push($base->modify('+1 minute'), 2.0);
        $this->assertCount(2, $ts->points);
        $this->assertSame(2.0, (float) $ts->points[1][1]);
    }
}
