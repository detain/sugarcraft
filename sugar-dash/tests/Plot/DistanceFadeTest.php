<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\DistanceFade;
use SugarCraft\Dash\Plot\Gradient101;

/**
 * Expected values are worked by hand from btop's Proc::draw formulas:
 *
 *   calc        = |selected − lc|
 *   fade index  = clamp(calc*100/select_max, 0, 100)          (truncating)
 *   position    = (min(v,100)+100) − calc*100/select_max
 *   color       = position < 100 ? proc_color[max(0,position)]
 *                                : process[clamp(position−100, 0, 100)]
 *
 * Identity ramps (entry k = rgb(k,0,0) / rgb(0,k,0)) make each looked-up
 * index readable straight off the returned color.
 */
final class DistanceFadeTest extends TestCase
{
    /** @return list<Color> entry k = rgb(k, 0, 0) */
    private static function redRamp(): array
    {
        return Gradient101::expand([Color::rgb(0, 0, 0), Color::rgb(100, 0, 0)]);
    }

    /** @return list<Color> entry k = rgb(0, k, 0) */
    private static function greenRamp(): array
    {
        return Gradient101::expand([Color::rgb(0, 0, 0), Color::rgb(0, 100, 0)]);
    }

    public function testDistanceIsAbsoluteDifference(): void
    {
        $this->assertSame(2, DistanceFade::distance(2, 0));
        // btop's off-by-one: the selected row (lc = selected - 1) is 1 away,
        // the row below it 0.
        $this->assertSame(1, DistanceFade::distance(2, 1));
        $this->assertSame(0, DistanceFade::distance(2, 2));
        $this->assertSame(3, DistanceFade::distance(2, 5));
        $this->assertSame(4, DistanceFade::distance(0, 4));
    }

    /** @return array<string, array{int, int, int}> */
    public static function fadeIndexCases(): array
    {
        return [
            'calc 0' => [0, 20, 0],
            'calc 1 of 20' => [1, 20, 5],
            'calc 2 of 20' => [2, 20, 10],
            'truncates 300/7=42.86' => [3, 7, 42],
            'truncates 100/3=33.3' => [1, 3, 33],
            'clamped high' => [25, 20, 100],
            'exact end' => [20, 20, 100],
        ];
    }

    #[DataProvider('fadeIndexCases')]
    public function testFadeIndex(int $calc, int $selectMax, int $expected): void
    {
        $this->assertSame($expected, DistanceFade::fadeIndex($calc, $selectMax));
    }

    public function testFadeColorLooksUpTheProcRamp(): void
    {
        $c = DistanceFade::fadeColor(self::redRamp(), 3, 7);
        $this->assertSame([42, 0, 0], [$c->r, $c->g, $c->b]);
    }

    /** @return array<string, array{int, int, int, int}> */
    public static function positionCases(): array
    {
        return [
            'busy, near' => [50, 2, 20, 140],
            'over 100 capped' => [150, 2, 20, 190],
            'idle, adjacent' => [0, 1, 20, 95],
            'idle, far beyond' => [0, 25, 20, -25],
            'idle, selected neighbour' => [0, 0, 20, 100],
            'hot, no distance' => [99, 0, 20, 199],
            'truncating distance 100/3' => [10, 1, 3, 77],
        ];
    }

    #[DataProvider('positionCases')]
    public function testMetricPosition(int $v, int $calc, int $selectMax, int $expected): void
    {
        $this->assertSame($expected, DistanceFade::metricPosition($v, $calc, $selectMax));
    }

    /** @return array<string, array{int, int, int, string, int}> */
    public static function metricColorCases(): array
    {
        // [v, calc, selectMax, ramp ('process'|'proc_color'), index]
        return [
            '140 → process[40]' => [50, 2, 20, 'process', 40],
            '190 → process[90]' => [150, 2, 20, 'process', 90],
            '95 → proc_color[95]' => [0, 1, 20, 'proc_color', 95],
            '-25 → proc_color[0]' => [0, 25, 20, 'proc_color', 0],
            '100 → process[0]' => [0, 0, 20, 'process', 0],
            '199 → process[99]' => [99, 0, 20, 'process', 99],
            '77 → proc_color[77]' => [10, 1, 3, 'proc_color', 77],
            '100 at 100 → process[100]' => [100, 0, 5, 'process', 100],
        ];
    }

    #[DataProvider('metricColorCases')]
    public function testMetricColorPicksRampAndIndex(int $v, int $calc, int $sm, string $ramp, int $index): void
    {
        $c = DistanceFade::metricColor(self::greenRamp(), self::redRamp(), $v, $calc, $sm);
        $expected = $ramp === 'process' ? [$index, 0, 0] : [0, $index, 0];
        $this->assertSame($expected, [$c->r, $c->g, $c->b]);
    }

    public function testFlatMetricColorClamps(): void
    {
        $this->assertSame(37, DistanceFade::flatMetricColor(self::redRamp(), 37)->r);
        $this->assertSame(100, DistanceFade::flatMetricColor(self::redRamp(), 250)->r);
        $this->assertSame(0, DistanceFade::flatMetricColor(self::redRamp(), -4)->r);
    }

    public function testBtopDefaultRampsMatchHandComputedEntries(): void
    {
        // proc: #cccccc → #404040, channel = 204 + trunc(k * -140 / 100)
        $proc = DistanceFade::ramp(Color::hex('#cccccc'), Color::hex('#404040'));
        $this->assertSame(204, $proc[0]->r);
        $this->assertSame(203, $proc[1]->r);   // -1.4 → -1
        $this->assertSame(190, $proc[10]->r);
        $this->assertSame(158, $proc[33]->r);  // -46.2 → -46
        $this->assertSame(64, $proc[100]->r);

        // proc_color: #404040 → #80d0a3 at 95: 64+trunc(60.8), 64+trunc(136.8), 64+trunc(94.05)
        $procColor = DistanceFade::ramp(Color::hex('#404040'), Color::hex('#80d0a3'));
        $this->assertSame([124, 200, 158], [$procColor[95]->r, $procColor[95]->g, $procColor[95]->b]);
    }

    /** @return array<string, array{float, float, int, array{int, int, int}}> */
    public static function metricValueCases(): array
    {
        return [
            'rounds cpu, floors mem, thirds threads' => [37.5, 4.9, 12, [38, 4, 4]],
            'cpu half away from zero' => [2.5, 0.0, 2, [3, 0, 0]],
            'small' => [0.4, 0.99, 5, [0, 0, 1]],
            'big' => [350.0, 100.0, 301, [350, 100, 100]],
            'negatives clamp' => [-3.0, -1.0, -9, [0, 0, 0]],
        ];
    }

    /**
     * @param array{int, int, int} $expected
     */
    #[DataProvider('metricValueCases')]
    public function testMetricValues(float $cpu, float $mem, int $threads, array $expected): void
    {
        $this->assertSame($expected, DistanceFade::metricValues($cpu, $mem, $threads));
    }

    public function testZeroSelectMaxThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DistanceFade::fadeIndex(1, 0);
    }

    public function testShortRampThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DistanceFade::fadeColor([Color::rgb(0, 0, 0)], 0, 1);
    }
}
