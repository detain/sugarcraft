<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\OHLC;

use SugarCraft\Charts\OHLC\Bar;
use PHPUnit\Framework\TestCase;

/**
 * @see Bar
 */
final class BarTest extends TestCase
{
    public function testConstructWithAllFields(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 103.0);

        self::assertSame(100.0, $bar->open);
        self::assertSame(105.0, $bar->high);
        self::assertSame(98.0, $bar->low);
        self::assertSame(103.0, $bar->close);
    }

    public function testIsBullishWhenCloseGreaterThanOpen(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 103.0);
        self::assertTrue($bar->isBullish());
        self::assertFalse($bar->isBearish());
    }

    public function testIsBearishWhenCloseLessThanOpen(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 97.0);
        self::assertFalse($bar->isBullish());
        self::assertTrue($bar->isBearish());
    }

    public function testIsNotBullishNorBearishWhenCloseEqualsOpen(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 100.0);
        self::assertFalse($bar->isBullish());
        self::assertFalse($bar->isBearish());
    }

    public function testBodyTopReturnsMaxOfOpenAndClose(): void
    {
        // bullish: close > open
        $bullish = new Bar(100.0, 105.0, 98.0, 103.0);
        self::assertSame(103.0, $bullish->bodyTop());

        // bearish: open > close
        $bearish = new Bar(100.0, 105.0, 98.0, 97.0);
        self::assertSame(100.0, $bearish->bodyTop());

        // equal
        $equal = new Bar(100.0, 105.0, 98.0, 100.0);
        self::assertSame(100.0, $equal->bodyTop());
    }

    public function testBodyBottomReturnsMinOfOpenAndClose(): void
    {
        // bullish: close > open
        $bullish = new Bar(100.0, 105.0, 98.0, 103.0);
        self::assertSame(100.0, $bullish->bodyBottom());

        // bearish: open > close
        $bearish = new Bar(100.0, 105.0, 98.0, 97.0);
        self::assertSame(97.0, $bearish->bodyBottom());

        // equal
        $equal = new Bar(100.0, 105.0, 98.0, 100.0);
        self::assertSame(100.0, $equal->bodyBottom());
    }

    public function testBodyTopAndBottomConsistent(): void
    {
        $bar = new Bar(50.0, 80.0, 30.0, 70.0);
        self::assertSame(70.0, $bar->bodyTop());
        self::assertSame(50.0, $bar->bodyBottom());
        self::assertGreaterThanOrEqual($bar->bodyBottom(), $bar->bodyTop());
    }

    public function testHighIsAlwaysGreaterThanOrEqualToBodyTop(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 103.0);
        self::assertGreaterThanOrEqual($bar->bodyTop(), $bar->high);
    }

    public function testLowIsAlwaysLessThanOrEqualToBodyBottom(): void
    {
        $bar = new Bar(100.0, 105.0, 98.0, 103.0);
        self::assertLessThanOrEqual($bar->bodyBottom(), $bar->low);
    }

    /**
     * @dataProvider integerValuesProvider
     */
    public function testIntegerValuesAreAcceptedAsFloats(int $open, int $high, int $low, int $close): void
    {
        $bar = new Bar($open, $high, $low, $close);
        self::assertSame((float) $open, $bar->open);
        self::assertSame((float) $high, $bar->high);
        self::assertSame((float) $low, $bar->low);
        self::assertSame((float) $close, $bar->close);
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function integerValuesProvider(): iterable
    {
        yield 'all positive' => [10, 20, 5, 15];
        yield 'all negative' => [-20, -5, -30, -10];
        yield 'mixed' => [-10, 20, -15, 5];
        yield 'zero' => [0, 10, -5, 5];
    }
}
