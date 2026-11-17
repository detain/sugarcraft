<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use SugarCraft\Charts\LineChart\TimeSeries;
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

    public function testWithTimeRangeStoresEndpoints(): void
    {
        $start = new \DateTimeImmutable('2026-01-01 09:00:00');
        $end   = new \DateTimeImmutable('2026-01-01 17:00:00');
        $ts = TimeSeries::new([])->withTimeRange($start, $end);
        [$rs, $re] = $ts->timeRange();
        $this->assertEquals($start, $rs);
        $this->assertEquals($end, $re);
    }

    public function testWithTimeRangeNullClears(): void
    {
        $ts = TimeSeries::new([])
            ->withTimeRange(new \DateTimeImmutable('2026-01-01 09:00:00'), null)
            ->withTimeRange(null, null);
        $this->assertSame([null, null], $ts->timeRange());
    }

    public function testWithTimeRangeFiltersOutOfRangePoints(): void
    {
        $a = new \DateTimeImmutable('2026-01-01 09:00:00');
        $b1 = new \DateTimeImmutable('2026-01-01 11:30:00');
        $b2 = new \DateTimeImmutable('2026-01-01 12:00:00');
        $b3 = new \DateTimeImmutable('2026-01-01 12:30:00');
        $c = new \DateTimeImmutable('2026-01-01 18:00:00');
        $ts = TimeSeries::new([[$a, 1], [$b1, 2], [$b2, 3], [$b3, 4], [$c, 5]], 30, 6)
            ->withTimeFormat('H:i')
            ->withTimeRange(
                new \DateTimeImmutable('2026-01-01 11:00:00'),
                new \DateTimeImmutable('2026-01-01 13:00:00'),
            );
        $out = $ts->view();
        // Range labels span the explicit endpoints, not the data extent.
        $this->assertStringContainsString('11:00', $out);
        $this->assertStringContainsString('13:00', $out);
        // The 09:00 / 18:00 points are filtered out so their labels
        // never appear.
        $this->assertStringNotContainsString('09:00', $out);
        $this->assertStringNotContainsString('18:00', $out);
    }
}
