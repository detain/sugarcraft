<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Support\Rune;

/**
 * CH-01 (sugar-charts-013): the single-cell claim behind multi-width and
 * empty runes — a wide glyph painted into one cell makes every touched
 * row overrun the declared width, and an empty rune silently erases the
 * series. Both are refused at every rune door; the guard itself is
 * idempotent on ordinary printable runes.
 */
final class RuneWidthTest extends TestCase
{
    public function testCleanSingleCellRunePassesThroughByteIdentical(): void
    {
        $this->assertSame('█', Rune::cell('█', 'test rune'));
        $this->assertSame('·', Rune::cell('·', 'test rune'));
    }

    public function testCjkRuneIsRefusedByTheSharedDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rune::cell('漢', 'test rune');
    }

    public function testEmptyRuneIsRefusedByTheSharedDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Rune::cell('', 'test rune');
    }

    public function testLineChartRefusesWidePointAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1, 2], 8, 2)->withPoint('漢');
    }

    public function testLineChartRefusesEmptyPointAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([1, 2], 8, 2)->withPoint('');
    }

    public function testLineChartRefusesWideDatasetPointRebuild(): void
    {
        // The datasetPoints map is re-measured on every constructor
        // rebuild, so a poisoned map cannot be smuggled in via copy().
        $chart = LineChart::new([1, 2], 8, 2)->withDataset('a', [1, 2]);
        $this->expectException(\InvalidArgumentException::class);
        $chart->withDatasetPoint('a', '漢');
    }

    public function testScatterRefusesWideRuneAtTheDoor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Scatter::new([[1, 2]], 8, 4)->withRune('漢');
    }

    public function testWideRuneNeverReachesViewEvenIndirectly(): void
    {
        // Defense in depth: the refusal happens at the setter; a
        // successfully-constructed chart's view carries no raw ESC and
        // stays within its declared width.
        $chart = LineChart::new([1, 2], 8, 2)->withPoint('*');
        $rows = explode("\n", $chart->view());
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(8, \SugarCraft\Core\Util\Width::string($row));
        }
    }
}
