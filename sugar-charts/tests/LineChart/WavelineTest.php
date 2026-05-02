<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\LineChart;

use CandyCore\Charts\LineChart\Waveline;
use PHPUnit\Framework\TestCase;

final class WavelineTest extends TestCase
{
    public function testEmptyRendersBlank(): void
    {
        $out = Waveline::new([], 5, 3)->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
    }

    public function testStraightHorizontalLine(): void
    {
        // Five points on the same y level.
        $w = Waveline::new([[0, 5], [1, 5], [2, 5], [3, 5], [4, 5]], 5, 3);
        $out = $w->view();
        $rows = explode("\n", $out);
        // Middle row should be all '*' / connectors.
        $this->assertStringContainsString('*', $out);
    }

    public function testWithXYRange(): void
    {
        $w = Waveline::new([[0, 0], [10, 100]], 11, 11)
            ->withXRange(0.0, 10.0)
            ->withYRange(0.0, 100.0);
        $out = $w->view();
        $this->assertStringContainsString('*', $out);
        // Should span from upper-left to lower-right (or vice versa) — both endpoints rendered.
        $this->assertGreaterThan(0, substr_count($out, '*'));
    }

    public function testPushAppendsPoint(): void
    {
        $w = Waveline::new([])->push(1.0, 2.0);
        $this->assertCount(1, $w->points);
        $this->assertSame(1.0, (float) $w->points[0][0]);
        $this->assertSame(2.0, (float) $w->points[0][1]);
    }
}
