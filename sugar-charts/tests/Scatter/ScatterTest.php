<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Scatter;

use CandyCore\Charts\Scatter\Scatter;
use PHPUnit\Framework\TestCase;

final class ScatterTest extends TestCase
{
    public function testEmptyPointsRendersBlankCanvas(): void
    {
        $out = Scatter::new([], 5, 3)->view();
        $this->assertSame("\n\n", $out);
    }

    public function testZeroSizeIsEmpty(): void
    {
        $this->assertSame('', Scatter::new([[1, 1]], 0, 0)->view());
    }

    public function testEndpointsLandAtCornerCells(): void
    {
        // Two points covering the full range: (0,0) bottom-left,
        // (10,10) top-right.
        $out = Scatter::new([[0, 0], [10, 10]], 5, 3)->view();
        $rows = explode("\n", $out);
        // Top-right cell carries one '*'.
        $this->assertSame('*', substr($rows[0], -1));
        // Bottom-left cell carries one '*'.
        $this->assertSame('*', substr($rows[2], 0, 1));
    }

    public function testNoConnectorsBetweenPoints(): void
    {
        // Two diagonal points: nothing between them should be drawn.
        $out = Scatter::new([[0, 0], [4, 2]], 5, 3)->view();
        // Total '*' must equal point count.
        $this->assertSame(2, substr_count($out, '*'));
    }

    public function testCustomRune(): void
    {
        $out = Scatter::new([[1, 1]], 3, 3)->withRune('o')->view();
        $this->assertStringContainsString('o', $out);
        $this->assertStringNotContainsString('*', $out);
    }

    public function testExplicitRangePinsAxes(): void
    {
        // Force the range so the single point lands at the midpoint.
        $out  = Scatter::new([[5.0, 5.0]], 5, 3)
            ->withXRange(0.0, 10.0)
            ->withYRange(0.0, 10.0)
            ->view();
        $rows = explode("\n", $out);
        $this->assertSame('*', $rows[1][2] ?? '');
    }

    public function testSinglePointPlotsAtZeroZeroByDefault(): void
    {
        // Single point degenerate range — should still plot somewhere
        // without crashing.
        $out = Scatter::new([[3.5, 7.0]], 4, 2)->view();
        $this->assertSame(1, substr_count($out, '*'));
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scatter::new([], -1, 5);
    }
}
