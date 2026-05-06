<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\BarChart;

use CandyCore\Charts\BarChart\Bar;
use CandyCore\Charts\BarChart\BarChart;
use PHPUnit\Framework\TestCase;

final class BarChartTest extends TestCase
{
    public function testEmptyChartIsEmpty(): void
    {
        $this->assertSame('', BarChart::new([], 10, 5)->view());
    }

    public function testHeightHonoredAndLabelsRow(): void
    {
        $out = BarChart::new([['a', 1], ['b', 2]], 5, 4)->view();
        $rows = explode("\n", $out);
        // 4 height = 3 body rows + 1 label row.
        $this->assertCount(4, $rows);
    }

    public function testTallestBarReachesTop(): void
    {
        $out = BarChart::new([['x', 0.0], ['y', 1.0]], 3, 4)->view();
        $rows = explode("\n", $out);
        // First row should contain a block where the tall bar is.
        $this->assertStringContainsString('█', $rows[0]);
    }

    public function testLabelsTruncatedToColumnWidth(): void
    {
        // Two bars in a width=4 chart: 1 col each, 1 col gap.
        $out  = BarChart::new([['alpha', 0.5], ['beta', 0.9]], 4, 3)->view();
        $rows = explode("\n", $out);
        $this->assertSame('a b', $rows[count($rows) - 1]);
    }

    public function testWithoutLabels(): void
    {
        $out  = BarChart::new([['a', 1], ['b', 2]], 5, 3)->withShowLabels(false)->view();
        $rows = explode("\n", $out);
        $this->assertCount(3, $rows);
    }

    public function testAcceptsBarObjects(): void
    {
        $bars = [new Bar('x', 0.5), new Bar('y', 1.0)];
        $out  = BarChart::new($bars, 5, 3)->view();
        $this->assertNotSame('', $out);
    }

    public function testAcceptsLabelKeyedArray(): void
    {
        $out = BarChart::new(['cpu' => 1.0, 'mem' => 0.5], 7, 3)->view();
        $rows = explode("\n", $out);
        $this->assertStringContainsString('cpu', $rows[count($rows) - 1]);
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BarChart::new([], -1, 5);
    }

    public function testRenderedRowsNeverExceedWidth(): void
    {
        // 5 bars in a 3-cell budget — trailing bars must be dropped so
        // every rendered row fits the chart width.
        $bars = [
            ['a', 0.1], ['b', 0.2], ['c', 0.3], ['d', 0.4], ['e', 0.5],
        ];
        $out = BarChart::new($bars, 3, 4)->view();
        foreach (explode("\n", $out) as $row) {
            $this->assertLessThanOrEqual(3, mb_strlen($row, 'UTF-8'),
                'each row must fit the configured width');
        }
    }

    public function testHeightOneWithLabelsRendersOneRow(): void
    {
        // height=1 + showLabels would otherwise emit body+labels=2 rows.
        $out = BarChart::new([['a', 0.5], ['b', 1.0]], 5, 1)->view();
        $rows = array_filter(explode("\n", $out), static fn($r) => $r !== '');
        $this->assertCount(1, $rows);
    }

    public function testHeightTwoWithLabelsRendersTwoRows(): void
    {
        // height=2 fits both a body row and a label row.
        $out = BarChart::new([['a', 1.0]], 3, 2)->view();
        $this->assertCount(2, explode("\n", $out));
    }

    public function testHorizontalRendersOneRowPerBar(): void
    {
        $out = BarChart::new([['a', 5.0], ['bb', 10.0], ['ccc', 2.0]], 20, 5)
            ->withHorizontal()
            ->view();
        $rows = explode("\n", $out);
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('a',   $rows[0]);
        $this->assertStringContainsString('bb',  $rows[1]);
        $this->assertStringContainsString('ccc', $rows[2]);
        $this->assertStringContainsString('█', $out);
    }

    public function testWithShowAxisDrawsAxisRunes(): void
    {
        $out = BarChart::new([['a', 0.7], ['b', 0.3]], 8, 4)
            ->withShowAxis()
            ->view();
        $this->assertStringContainsString('┤', $out);
        $this->assertStringContainsString('└', $out);
        $this->assertStringContainsString('─', $out);
    }

    public function testPushAppendsSingleBar(): void
    {
        $b = BarChart::new([['a', 0.5]]);
        $b = $b->push(['b', 0.7]);
        $this->assertCount(2, $b->bars);
        $this->assertSame('a', $b->bars[0]->label);
        $this->assertSame('b', $b->bars[1]->label);
    }

    public function testPushAcceptsBarInstance(): void
    {
        $b = BarChart::new()->push(new Bar('only', 1.0));
        $this->assertCount(1, $b->bars);
        $this->assertSame('only', $b->bars[0]->label);
    }

    public function testPushAllAppendsEvery(): void
    {
        $b = BarChart::new([['a', 1.0]])->pushAll([['b', 2.0], ['c', 3.0]]);
        $this->assertCount(3, $b->bars);
        $this->assertSame('c', $b->bars[2]->label);
    }

    public function testPushAllOnEmptyArrayIsNoop(): void
    {
        $a = BarChart::new([['x', 1.0]]);
        $b = $a->pushAll([]);
        $this->assertSame($a->bars, $b->bars);
    }

    public function testClearWipesBars(): void
    {
        $b = BarChart::new([['a', 1.0], ['b', 2.0]])->clear();
        $this->assertSame([], $b->bars);
    }

    public function testPushIsImmutable(): void
    {
        $a = BarChart::new([['a', 1.0]]);
        $b = $a->push(['b', 2.0]);
        $this->assertCount(1, $a->bars);
        $this->assertCount(2, $b->bars);
    }
}
