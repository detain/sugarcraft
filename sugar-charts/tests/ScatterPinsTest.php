<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Scatter\Scatter;

/**
 * CH-09 (sugar-charts-018): pinned axis ends are HONORED — a lone
 * withXRange/withYRange pin survives even when it sits outside the data,
 * re-scaling the plot instead of being absorbed by the scan.
 */
final class ScatterPinsTest extends TestCase
{
    public function testLoneXPinInsideDataShiftsThePlot(): void
    {
        $auto   = Scatter::new([[6.0, 1.0], [8.0, 2.0]], 20, 5)->withAxes(false)->view();
        $pinned = Scatter::new([[6.0, 1.0], [8.0, 2.0]], 20, 5)->withAxes(false)->withXRange(5.0, null)->view();
        $this->assertNotSame($auto, $pinned);
        // The pinned min (5) extends the domain left, so x=6 moves inward.
        $autoCol   = min(array_map(static fn(string $l): int => $l === '' ? PHP_INT_MAX : (int) strrpos($l, '*'), explode("\n", $auto)));
        $pinCol    = min(array_map(static fn(string $l): int => $l === '' ? PHP_INT_MAX : (int) strrpos($l, '*'), explode("\n", $pinned)));
        $this->assertGreaterThan($autoCol, $pinCol);
    }

    public function testPinnedEndAppearsOnAxisBytes(): void
    {
        // withMinX(5.0)-style honoring (sugar-charts-018 acceptance): the
        // default y gutter renders the pinned/effective endpoints as bytes.
        $out = Scatter::new([[1.0, 5.0], [3.0, 7.0]], 20, 5)
            ->withYRange(4.0, null)
            ->view();
        $this->assertStringContainsString('│', $out);
        $this->assertStringContainsString('7', $out);
        $this->assertStringContainsString('4', $out);
    }
}
