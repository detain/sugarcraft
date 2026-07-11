<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Streamline;
use PHPUnit\Framework\TestCase;

final class StreamlineTest extends TestCase
{
    /**
     * Parity proof for the ring-buffer + reused-chart refactor: for every
     * step of a push sequence (empty → filling → full → wrapped) the
     * reused-template `view()` must be byte-identical to the pre-refactor
     * path, which built a fresh `LineChart` from the ordered window each
     * frame. Covers the default range and an explicit min/max/glyph.
     *
     * @dataProvider parityConfigs
     */
    public function testViewIsByteIdenticalToFreshChartAcrossPushSequence(
        ?float $min,
        ?float $max,
        string $point,
    ): void {
        $s = Streamline::new(5, 4)->withYRange($min, $max)->withPoint($point);

        // Include enough samples to fill and then wrap the width-5 window.
        foreach ([2, 5, 1, 8, 3, 7, 4, 9, 6, 0, 5, 2] as $sample) {
            $expected = LineChart::new($s->window, 5, 4)
                ->withMin($min)
                ->withMax($max)
                ->withPoint($point)
                ->view();
            self::assertSame($expected, $s->view());

            $s = $s->push($sample);
            // Re-check after the mutation so the wrapped window is covered too.
            $expected = LineChart::new($s->window, 5, 4)
                ->withMin($min)
                ->withMax($max)
                ->withPoint($point)
                ->view();
            self::assertSame($expected, $s->view());
        }
    }

    /**
     * @return iterable<string, array{?float, ?float, string}>
     */
    public static function parityConfigs(): iterable
    {
        yield 'auto range, default glyph' => [null, null, '*'];
        yield 'pinned range, custom glyph' => [0.0, 10.0, 'o'];
    }

    /**
     * Behavioural proof that the internal path is a genuine ring buffer,
     * not an ordered slice: after wrapping, the physical buffer is stored
     * rotated (head-relative), distinct from the insertion-ordered window,
     * and `head` / `size` track the ring state.
     */
    public function testRingBufferStoresWrappedPhysicalLayout(): void
    {
        // width 5, seven pushes (0..6): window wraps once.
        $s = Streamline::new(5, 4)->pushAll(range(0, 6));

        $buffer = self::readPrivate($s, 'buffer');
        $head   = self::readPrivate($s, 'head');
        $size   = self::readPrivate($s, 'size');

        // Insertion order (public contract) is the last 5 samples.
        self::assertSame([2, 3, 4, 5, 6], $s->window);
        // Physical ring layout is rotated: newest two samples overwrote
        // slots 0 and 1, so the raw buffer differs from the ordered window.
        self::assertSame([5, 6, 2, 3, 4], $buffer);
        self::assertSame(2, $head);   // 7 % 5
        self::assertSame(5, $size);   // capped at width
    }

    private static function readPrivate(object $obj, string $prop): mixed
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);

        return $ref->getValue($obj);
    }

    public function testEmptyRendersBlankCanvas(): void
    {
        $out = Streamline::new(8, 3)->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
    }

    public function testPushAppendsAndCapsAtWidth(): void
    {
        $s = Streamline::new(5, 4);
        for ($i = 0; $i < 10; $i++) {
            $s = $s->push($i);
        }
        $this->assertCount(5, $s->window);
        // Window holds the most recent 5 samples (5..9).
        $this->assertSame([5, 6, 7, 8, 9], $s->window);
    }

    public function testPushAllAppendsAll(): void
    {
        $s = Streamline::new(10, 4)->pushAll([1, 2, 3]);
        $this->assertSame([1, 2, 3], $s->window);
    }

    public function testWithSizeShrinksWindow(): void
    {
        $s = Streamline::new(10, 4)->pushAll(range(1, 10))->withSize(3, 4);
        $this->assertCount(3, $s->window);
        $this->assertSame([8, 9, 10], $s->window);
    }

    public function testViewProducesCanvas(): void
    {
        $out = Streamline::new(5, 3)->pushAll([1, 3, 2, 4, 1])->view();
        $this->assertSame(3, substr_count($out, "\n") + 1);
        $this->assertStringContainsString('*', $out);
    }

    public function testClearEmptiesWindow(): void
    {
        $s = Streamline::new(5, 3)->pushAll([1, 2, 3]);
        $this->assertSame(3, $s->count());
        $s = $s->clear();
        $this->assertTrue($s->isEmpty());
        $this->assertSame(0, $s->count());
    }

    public function testClearPreservesSettings(): void
    {
        $s = Streamline::new(5, 3)->withMin(0.0)->withMax(10.0)->withPoint('o');
        $s = $s->clear();
        $this->assertSame(0.0,  $s->min);
        $this->assertSame(10.0, $s->max);
        $this->assertSame('o',  $s->point);
    }

    public function testWithYRangeShortcut(): void
    {
        $s = Streamline::new(5, 3)->withYRange(0.0, 10.0);
        $this->assertSame(0.0,  $s->min);
        $this->assertSame(10.0, $s->max);
    }
}
