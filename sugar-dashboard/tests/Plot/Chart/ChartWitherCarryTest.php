<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Plot\Chart\Chart;
use SugarCraft\Dashboard\Plot\Chart\ChartDataPoint;
use SugarCraft\Dashboard\Plot\Chart\ChartType;

/**
 * D-19 (finding sugar-dashboard-016, leg 4/4 plot): all twelve Chart
 * withers rebuilt via `new self(...)`, dropping the setSize allocation
 * held in private fields outside the constructor. Same re-apply pattern
 * as D-16/D-17; the pin is the carry identity — size-then-wither must
 * equal wither-then-size byte for byte (the wither is what erased the
 * box before the fix).
 */
final class ChartWitherCarryTest extends TestCase
{
    private const W = 40;
    private const H = 14;

    private function base(): Chart
    {
        return Chart::new([
            new ChartDataPoint('alpha', 3.0),
            new ChartDataPoint('beta', 5.0),
            new ChartDataPoint('gamma', 2.0),
        ]);
    }

    /**
     * @param array<string,callable> $withers
     */
    private function assertCarryIdentity(array $withers): void
    {
        $base = $this->base();
        $sized = $base->setSize(self::W, self::H);
        foreach ($withers as $name => $with) {
            $viaSize = $with($sized);
            $viaWither = $with($base)->setSize(self::W, self::H);
            $this->assertSame(
                $viaSize->render(),
                $viaWither->render(),
                "Chart.$name: wither after setSize dropped the allocation"
            );
            $this->assertSame($viaSize->innerSize(), $viaWither->innerSize(), "Chart.$name innerSize diverged");
        }
    }

    public function testAllTwelveWithersCarryTheBox(): void
    {
        $this->assertCarryIdentity([
            'withDataPoints' => static fn (Chart $c): Chart => $c->withDataPoints([new ChartDataPoint('delta', 4.0)]),
            'withType' => static fn (Chart $c): Chart => $c->withType(ChartType::Line),
            'withWidth' => static fn (Chart $c): Chart => $c->withWidth(30),
            'withHeight' => static fn (Chart $c): Chart => $c->withHeight(9),
            'withGrid' => static fn (Chart $c): Chart => $c->withGrid(false),
            'withShowValues' => static fn (Chart $c): Chart => $c->withShowValues(true),
            'withShowLabels' => static fn (Chart $c): Chart => $c->withShowLabels(false),
            'withXAxisLabel' => static fn (Chart $c): Chart => $c->withXAxisLabel('time'),
            'withYAxisLabel' => static fn (Chart $c): Chart => $c->withYAxisLabel('value'),
            'withColor' => static fn (Chart $c): Chart => $c->withColor(Color::hex('#FFAA00')),
            'withGridColor' => static fn (Chart $c): Chart => $c->withGridColor(Color::hex('#333333')),
            'withLabelColor' => static fn (Chart $c): Chart => $c->withLabelColor(Color::hex('#CCCCCC')),
        ]);
    }

    public function testPinTeethTheBoxIsObservable(): void
    {
        $base = $this->base();
        $sized = $base->setSize(self::W, self::H);
        $this->assertNotSame(
            $base->withGrid(false)->render(),
            $sized->withGrid(false)->render(),
            'a sized chart must render differently than the natural box (identity pin is not vacuous)'
        );
        $this->assertGreaterThan(200, strlen($sized->render()));
    }
}
