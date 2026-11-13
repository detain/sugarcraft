<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Aggregation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\MovingAverage;

final class MovingAverageTest extends TestCase
{
    public function testSimpleMovingAverage(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];
        $sma = MovingAverage::simple(3, $values);

        // Window=3, so:
        // idx 0: not enough data -> 0.0
        // idx 1: not enough data -> 0.0
        // idx 2: (10+20+30)/3 = 20
        // idx 3: (20+30+40)/3 = 30
        // idx 4: (30+40+50)/3 = 40
        $this->assertCount(5, $sma);
        $this->assertSame(0.0, $sma[0]);
        $this->assertSame(0.0, $sma[1]);
        $this->assertSame(20.0, $sma[2]);
        $this->assertSame(30.0, $sma[3]);
        $this->assertSame(40.0, $sma[4]);
    }

    public function testCenteredMovingAverage(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0, 50.0];
        $sma = MovingAverage::centered(3, $values);

        // Centered with window=3:
        // idx 0: out of bounds -> 0.0
        // idx 1: valid window [10,20,30] -> 20
        // idx 2: valid window [20,30,40] -> 30
        // idx 3: valid window [30,40,50] -> 40
        // idx 4: out of bounds -> 0.0
        $this->assertCount(5, $sma);
        $this->assertSame(0.0, $sma[0]);
        $this->assertSame(20.0, $sma[1]);
        $this->assertSame(30.0, $sma[2]);
        $this->assertSame(40.0, $sma[3]);
        $this->assertSame(0.0, $sma[4]);
    }

    public function testExponentialMovingAverage(): void
    {
        $values = [10.0, 20.0, 30.0, 40.0];
        $ema = MovingAverage::ema(3, $values);

        // alpha = 2/(3+1) = 0.5
        // ema[0] = 10
        // ema[1] = 0.5*20 + 0.5*10 = 15
        // ema[2] = 0.5*30 + 0.5*15 = 22.5
        // ema[3] = 0.5*40 + 0.5*22.5 = 31.25
        $this->assertCount(4, $ema);
        $this->assertSame(10.0, $ema[0]);
        $this->assertSame(15.0, $ema[1]);
        $this->assertSame(22.5, $ema[2]);
        $this->assertSame(31.25, $ema[3]);
    }

    public function testEmptyValuesReturnEmpty(): void
    {
        $sma = MovingAverage::simple(3, []);
        $this->assertSame([], $sma);
    }

    public function testWindowLargerThanData(): void
    {
        $values = [10.0, 20.0];
        $sma = MovingAverage::simple(5, $values);

        $this->assertCount(2, $sma);
        $this->assertSame(0.0, $sma[0]);
        $this->assertSame(0.0, $sma[1]);
    }

    public function testFluentInterface(): void
    {
        $ma = MovingAverage::new(3)
            ->add(10.0)
            ->add(20.0)
            ->add(30.0);

        $values = $ma->values();
        $this->assertCount(3, $values);
        $this->assertSame(30.0, $values[2]);

        $sma = $ma->computeSimple();
        $this->assertCount(3, $sma);
        $this->assertSame(0.0, $sma[0]);
        $this->assertSame(0.0, $sma[1]);
        $this->assertSame(20.0, $sma[2]);
    }

    public function testClearResetsValues(): void
    {
        $ma = MovingAverage::new(3)
            ->add(10.0)
            ->add(20.0);

        $cleared = $ma->clear();
        $this->assertCount(0, $cleared->values());
    }

    public function testRejectNonPositiveWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MovingAverage::new(0);
    }

    public function testAddMany(): void
    {
        $ma = MovingAverage::new(2)->addMany([10.0, 20.0, 30.0]);
        $sma = $ma->computeSimple();

        $this->assertCount(3, $sma);
        $this->assertSame(0.0, $sma[0]);   // not enough
        $this->assertSame(15.0, $sma[1]); // (10+20)/2
        $this->assertSame(25.0, $sma[2]); // (20+30)/2
    }// ------------------------------------------------ CH-39 (029/041)

    public function testEmaRejectsZeroWindowEvenWithEmptyValues(): void
    {
        // sugar-charts-029: the old ema() returned [] before any window
        // check, so windowSize 0 silently "succeeded".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Window size must be > 0, got 0');
        MovingAverage::ema(0);
    }

    public function testEmaRejectsNegativeDefaultAlphaFromBadWindow(): void
    {
        // window -3 would have produced alpha 2/(-2) = -1.0 and a
        // sign-flipping silent divergence.
        $this->expectException(\InvalidArgumentException::class);
        MovingAverage::ema(-3, [1.0, 2.0]);
    }

    public function testEmaRejectsExplicitAlphaOutsideRange(): void
    {
        foreach ([0.0, -0.5, 1.5] as $bad) {
            try {
                MovingAverage::ema(3, [1.0, 2.0], $bad);
                $this->fail("alpha {$bad} must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('EMA alpha must be in (0, 1]', $e->getMessage());
            }
        }
    }

    public function testEmaAcceptsAlphaBoundariesOneAndDefault(): void
    {
        $this->assertSame([5.0, 7.0], MovingAverage::ema(3, [5.0, 7.0], 1.0));
        $this->assertNotSame([], MovingAverage::ema(3, [5.0, 7.0]));
    }

    public function testConstructorDoorsSharedByAllEntryPoints(): void
    {
        foreach (['new' => fn () => MovingAverage::new(0), 'simple' => fn () => MovingAverage::simple(-1, [1.0]), 'centered' => fn () => MovingAverage::centered(0, [1.0])] as $name => $call) {
            try {
                $call();
                $this->fail("{$name} must share the window door");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Window size must be > 0', $e->getMessage());
            }
        }
    }

    public function testAccumulatorByteParityVsSliceReferenceOnIntegers(): void
    {
        // sugar-charts-041: running-sum must equal the fresh slice-sum
        // reference byte-for-byte on an exact-representable seeded series.
        $values = [];
        for ($i = 0; $i < 600; $i++) {
            $values[] = ($i * 37 % 101) - 50;
        }
        foreach ([2, 7, 10, 43] as $w) {
            $got = MovingAverage::simple($w, $values);
            $ref = [];
            for ($i = 0; $i < count($values); $i++) {
                // (float) cast: the legacy slice path leaked PHP's exact
                // int/int division (int(−8)) despite the documented
                // list<float>; the accumulator keeps every element a true
                // float, so the reference pins the CONTRACT, not the leak.
                $ref[] = $i < $w - 1 ? 0.0 : (float) (array_sum(array_slice($values, $i - $w + 1, $w)) / $w);
            }
            $this->assertSame($ref, $got, "window {$w} trailing parity");
        }
    }

    public function testCenteredAccumulatorParityAndEdgePad(): void
    {
        $values = range(1, 50);
        $w = 7;
        $got = MovingAverage::centered($w, $values);
        $half = intdiv($w, 2);
        $ref = [];
        for ($i = 0; $i < count($values); $i++) {
            $start = $i - $half;
            $end = $i + $half;
            $ref[] = ($start < 0 || $end >= count($values))
                ? 0.0
                : (float) (array_sum(array_slice($values, $start, $w)) / $w);
        }
        $this->assertSame($ref, $got);
        // edges are the honest 0.0 pad, interior is the true window
        $this->assertSame(0.0, $got[0]);
        $this->assertSame(0.0, $got[count($got) - 1]);
    }

    public function testCenteredNarrowerThanSeriesIsAllPad(): void
    {
        $this->assertSame([0.0, 0.0, 0.0], MovingAverage::centered(5, [1.0, 2.0, 3.0]));
    }

    public function testWindowApproxLengthIsLinearTime(): void
    {
        // sugar-charts-041 acceptance: with window ~= n the old slice-sum
        // path was quadratic (n^2/2 adds); the accumulator must stay well
        // inside a linear budget.
        $values = range(1, 20000);
        $out = MovingAverage::simple(10000, $values); // first run warms autoload
        $ms = 1e9;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $start = hrtime(true);
            MovingAverage::simple(10000, $values);
            $ms = min($ms, (hrtime(true) - $start) / 1e6);
        }
        $this->assertCount(20000, $out);
        // best-of-3 on the bare loop measures ~8ms; the legacy slice-sum
        // path measured ~430ms on the same host — 120ms absorbs PHPUnit
        // bootstrap noise without ever passing a quadratic regression.
        $this->assertLessThan(120.0, $ms, "20k@w=10k took {$ms}ms — accumulator regressed to quadratic?");
    }
}

