<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;

/**
 * lib-reviews-1103 batch 2, M12: BarChart::withLegend(true) used to render
 * nothing because legendItems was never populated and the ChartExtras
 * render path gates on a non-empty item list. The legend now derives one
 * entry per bar (label = bar label, color = palette rotation) when the
 * caller has not supplied explicit items.
 */
final class BarChartLegendDeriveTest extends TestCase
{
    /** @return list<array{label: string, color: string}> */
    private static function items(BarChart $b): array
    {
        $prop = new \ReflectionProperty(BarChart::class, 'legendItems');
        $prop->setAccessible(true);
        return $prop->getValue($b);
    }

    public function testLegendDerivesItemsFromBarsWhenEmpty(): void
    {
        $b = BarChart::new([['alpha', 5.0], ['beta', 2.0]], 14, 3)->withLegend();
        $items = self::items($b);
        $this->assertCount(2, $items);
        $this->assertSame('alpha', $items[0]['label']);
        $this->assertSame('beta', $items[1]['label']);
        $this->assertContains($items[0]['color'], ['blue', 'orange']);
        $view = $b->view();
        $this->assertStringContainsString('alpha', $view);
        $this->assertStringContainsString('beta', $view);
    }

    public function testExplicitItemsWinOverDerived(): void
    {
        $b = BarChart::new([['alpha', 5.0]], 14, 3)
            ->withLegendItems([['label' => 'mine', 'color' => 'red']])
            ->withLegend();
        $items = self::items($b);
        $this->assertCount(1, $items);
        $this->assertSame('mine', $items[0]['label']);
    }

    public function testDerivedItemsRotatePaletteWithoutDuplicatesPerBar(): void
    {
        $bars = [];
        for ($i = 0; $i < 8; $i++) {
            $bars[] = ['b' . $i, 1.0];
        }
        $items = self::items(BarChart::new($bars, 14, 3)->withLegend());
        $this->assertCount(8, $items);
        // Index rotation: entry 6 repeats entry 0's palette slot.
        $this->assertSame($items[0]['color'], $items[6]['color']);
        $this->assertNotSame($items[0]['color'], $items[1]['color']);
    }

    public function testLegendOffDoesNotDerive(): void
    {
        $b = BarChart::new([['alpha', 5.0]], 14, 3);
        $this->assertSame([], self::items($b));
    }

    public function testDerivedLabelsAreGuardedLikeExplicitOnes(): void
    {
        // Bar labels arrive through Bar's ctor (guarded), so derivation
        // cannot smuggle raw escapes into the legend.
        $b = BarChart::new([["\x1b[2Jpwn", 5.0]], 14, 3)->withLegend();
        $items = self::items($b);
        $this->assertStringNotContainsString("\x1b", $items[0]['label']);
        $this->assertStringNotContainsString("\x1b[2J", $b->view());
    }
}
