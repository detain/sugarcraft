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
        $ma = MovingAverage::create(3)
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
        $ma = MovingAverage::create(3)
            ->add(10.0)
            ->add(20.0);

        $cleared = $ma->clear();
        $this->assertCount(0, $cleared->values());
    }

    public function testRejectNonPositiveWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MovingAverage::create(0);
    }

    public function testAddMany(): void
    {
        $ma = MovingAverage::create(2)->addMany([10.0, 20.0, 30.0]);
        $sma = $ma->computeSimple();

        $this->assertCount(3, $sma);
        $this->assertSame(0.0, $sma[0]);   // not enough
        $this->assertSame(15.0, $sma[1]); // (10+20)/2
        $this->assertSame(25.0, $sma[2]); // (20+30)/2
    }
}
