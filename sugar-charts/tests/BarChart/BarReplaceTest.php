<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\Bar;
use SugarCraft\Charts\BarChart\BarChart;

/**
 * CH-11 / sugar-charts-019: `withBars()` is the documented replace-all
 * seam (ntcharts parity) and had zero test coverage — a dropped coercion
 * or a merging copy would ship green. The replace must store ONLY the new
 * set, through every accepted row shape.
 */
final class BarReplaceTest extends TestCase
{
    public function testWithBarsReplacesWholeSet(): void
    {
        $chart    = BarChart::new([['old-a', 3.0], ['old-b', 7.0]], 12, 4);
        $replaced = $chart->withBars(['new-a' => 5.0]);
        // A replace equals a fresh chart built from the new set alone.
        self::assertSame(BarChart::new(['new-a' => 5.0], 12, 4)->view(), $replaced->view());
        $out = $replaced->view();
        self::assertStringNotContainsString('old-a', $out, 'old set leaked through the replace door');
        self::assertStringNotContainsString('old-b', $out);
        self::assertNotSame($chart->view(), $out);
    }

    public function testWithBarsAcceptsEveryDocumentedRowShape(): void
    {
        $fromTuples = BarChart::new([])->withBars([['a', 1.0], ['b', 2.0]]);
        $fromAssoc  = BarChart::new([])->withBars(['a' => 1.0, 'b' => 2.0]);
        $fromBars   = BarChart::new([])->withBars([new Bar('a', 1.0), new Bar('b', 2.0)]);
        self::assertSame($fromTuples->view(), $fromAssoc->view());
        self::assertSame($fromTuples->view(), $fromBars->view());
    }

    public function testReplaceResetsStaleGeometryTail(): void
    {
        // Replacing a 6-bar set with 1 bar must re-derive geometry for the
        // new count (a stale cached count would paint padding past the bar).
        $chart = BarChart::new([['a', 1.0], ['b', 2.0], ['c', 3.0], ['d', 4.0], ['e', 5.0], ['f', 6.0]], 12, 4);
        $one   = $chart->withBars(['solo' => 3.0]);
        self::assertSame(BarChart::new(['solo' => 3.0], 12, 4)->view(), $one->view());
    }
}
