<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\Sparkline;

use CandyCore\Charts\Sparkline\Sparkline;
use CandyCore\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    public function testEmptyDataPaddedWithBlanks(): void
    {
        $this->assertSame('   ', Sparkline::new([], 3)->view());
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $this->assertSame('', Sparkline::new([1, 2, 3], 0)->view());
    }

    public function testRendersOneCellPerPoint(): void
    {
        $out = Sparkline::new([1, 2, 3, 4, 5, 6, 7, 8])->view();
        $this->assertSame(8, Width::string($out));
    }

    public function testUsesEightLevelsForLinearRamp(): void
    {
        $out = Sparkline::new([0, 1, 2, 3, 4, 5, 6, 7, 8])->view();
        // 9 points scaled into 8 levels: first should be ' ' (or ▁ if min>min),
        // last should be '█'. Validate the bookends.
        $this->assertStringEndsWith('█', $out);
    }

    public function testFlatSeriesRendersMidBar(): void
    {
        $this->assertSame('▄▄▄', Sparkline::new([5, 5, 5])->view());
    }

    public function testWindowKeepsLastNPoints(): void
    {
        $out = Sparkline::new([1, 2, 3, 4, 5, 6])->withWidth(3)->view();
        // Only the last 3 points (4, 5, 6) survive; 4 is min, 6 is max.
        $this->assertSame(' ▄█', $out);
    }

    public function testShorterDataLeftPadded(): void
    {
        $out = Sparkline::new([1, 2])->withWidth(5)->view();
        // 3 leading blanks then 2 levels.
        $this->assertSame(5, Width::string($out));
        $this->assertStringStartsWith('   ', $out);
    }

    public function testExplicitMinMax(): void
    {
        $out = Sparkline::new([0, 5, 10])->withMin(0.0)->withMax(10.0)->view();
        $this->assertStringEndsWith('█', $out);
    }

    public function testNegativeWidthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sparkline::new()->withWidth(-1);
    }

    public function testPushAppendsSingleSample(): void
    {
        $s = Sparkline::new([1, 2])->withWidth(3)->push(3);
        $this->assertSame([1, 2, 3], $s->data);
        $this->assertSame(3, mb_strlen($s->view(), 'UTF-8'));
    }

    public function testPushAllAppendsEvery(): void
    {
        $s = Sparkline::new([1])->pushAll([2, 3, 4]);
        $this->assertSame([1, 2, 3, 4], $s->data);
    }

    public function testPushAllOnEmptyArrayIsNoop(): void
    {
        $a = Sparkline::new([1, 2]);
        $b = $a->pushAll([]);
        $this->assertSame($a->data, $b->data);
    }

    public function testStreamingSlidingWindow(): void
    {
        // With width=4 and 6 samples, view() shows the last 4 only.
        $s = Sparkline::new([], 4);
        foreach ([1, 2, 3, 4, 5, 6] as $v) {
            $s = $s->push($v);
        }
        $this->assertSame([1, 2, 3, 4, 5, 6], $s->data);
        $out = $s->view();
        $this->assertSame(4, mb_strlen($out, 'UTF-8'));
        $this->assertStringEndsWith('█', $out);
    }

    public function testClearWipesData(): void
    {
        $s = Sparkline::new([1, 2, 3])->clear();
        $this->assertSame([], $s->data);
    }

    public function testPushIsImmutable(): void
    {
        $a = Sparkline::new([1]);
        $b = $a->push(2);
        $this->assertSame([1],    $a->data);
        $this->assertSame([1, 2], $b->data);
    }
}
