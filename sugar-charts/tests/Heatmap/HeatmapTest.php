<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Heatmap;

use CandyCore\Charts\Heatmap\Heatmap;
use CandyCore\Charts\Heatmap\HeatPoint;
use CandyCore\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class HeatmapTest extends TestCase
{
    public function testEmptyGridIsEmpty(): void
    {
        $this->assertSame('', Heatmap::new([])->view());
    }

    public function testZeroSizeIsEmpty(): void
    {
        $this->assertSame('', Heatmap::new([[1.0]])->withSize(0, 0)->view());
    }

    public function testGridDimensionsDriveDefaultCanvas(): void
    {
        $h = Heatmap::new([
            [0.0, 0.5],
            [0.5, 1.0],
        ]);
        $this->assertSame(2, $h->width);
        $this->assertSame(2, $h->height);
        $rows = explode("\n", $h->view());
        $this->assertCount(2, $rows);
    }

    public function testEachCellWrappedInSgr(): void
    {
        $out = Heatmap::new([[0.0, 1.0]])->view();
        // Two cells of '█' should each be coloured.
        $this->assertSame(2, substr_count($out, '█'));
        $this->assertStringContainsString("\x1b[38", $out);
    }

    public function testColdAndHotColoursLerp(): void
    {
        // Pin cold=black (0,0,0) and hot=white (255,255,255).
        $cold = Color::rgb(0, 0, 0);
        $hot  = Color::rgb(255, 255, 255);
        $h = Heatmap::new([[0.0, 0.5, 1.0]])->withColors($cold, $hot);
        $out = $h->view();
        $this->assertStringContainsString('38;2;0;0;0',       $out);
        $this->assertStringContainsString('38;2;128;128;128', $out);
        $this->assertStringContainsString('38;2;255;255;255', $out);
    }

    public function testCustomRune(): void
    {
        $out = Heatmap::new([[0.0, 1.0]])->withRune('●')->view();
        $this->assertStringContainsString('●', $out);
        $this->assertStringNotContainsString('█', $out);
    }

    public function testFlatGridPicksColdEnd(): void
    {
        // All values equal → range is bumped by 1 internally, so we end
        // up at the cold side (t=0).
        $h = Heatmap::new([[5.0, 5.0]])
            ->withColors(Color::rgb(0, 0, 0), Color::rgb(255, 255, 255));
        $out = $h->view();
        $this->assertStringContainsString('38;2;0;0;0', $out);
        $this->assertStringNotContainsString('38;2;255;255;255', $out);
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heatmap::new([])->withSize(-1, 1);
    }

    public function testWithLegendAddsBottomRow(): void
    {
        $h = Heatmap::new([[0.0, 0.5, 1.0]], 6, 1)
            ->withColors(Color::rgb(0, 0, 0), Color::rgb(255, 0, 0))
            ->withLegend();
        $out = $h->view();
        $rows = explode("\n", $out);
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('0', $rows[1]);
        $this->assertStringContainsString('1', $rows[1]);
    }

    public function testWithPaletteUsesMultipleStops(): void
    {
        $palette = [
            Color::rgb(0, 0, 0),
            Color::rgb(0, 255, 0),   // mid: green
            Color::rgb(255, 0, 0),
        ];
        $h = Heatmap::new([[0.0, 0.5, 1.0]], 3, 1)
            ->withPalette($palette);
        $out = $h->view();
        $this->assertStringContainsString('38;2;0;255;0', $out);
    }

    public function testWithPaletteRejectsSingleStop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heatmap::new([])->withPalette([Color::rgb(0, 0, 0)]);
    }

    public function testHeatPointConstructorsExposeFields(): void
    {
        $p = new HeatPoint(2, 3, 4.5);
        $this->assertSame(2, $p->x);
        $this->assertSame(3, $p->y);
        $this->assertSame(4.5, $p->value);

        $i = HeatPoint::ofInt(1, 0, 9);
        $this->assertSame(1, $i->x);
        $this->assertSame(0, $i->y);
        $this->assertSame(9.0, $i->value);
    }

    public function testPushPointGrowsGrid(): void
    {
        $h = Heatmap::new([])->pushPoint(new HeatPoint(2, 1, 7.0));
        // Grid should now have row 0 (empty), row 1 with [0,0,7].
        $this->assertCount(2, $h->grid);
        $this->assertSame([],          $h->grid[0]);
        $this->assertSame([0, 0, 7.0], $h->grid[1]);
    }

    public function testPushPointOverwritesExistingCell(): void
    {
        $h = Heatmap::new([[1.0, 2.0], [3.0, 4.0]])
            ->pushPoint(new HeatPoint(0, 0, 99.0));
        $this->assertSame(99.0, $h->grid[0][0]);
        $this->assertSame(2.0,  $h->grid[0][1]);
    }

    public function testPushAllStreamsEveryPoint(): void
    {
        $h = Heatmap::new([])->pushAll([
            new HeatPoint(0, 0, 1.0),
            HeatPoint::ofInt(1, 0, 2),
            HeatPoint::ofInt(2, 0, 3),
        ]);
        $this->assertSame([1.0, 2.0, 3.0], $h->grid[0]);
    }

    public function testPushPointRejectsNegativeCoordinates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Heatmap::new([])->pushPoint(new HeatPoint(-1, 0, 0));
    }
}
