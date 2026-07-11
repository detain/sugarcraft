<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Support;

use SugarCraft\Charts\Support\Finite;
use PHPUnit\Framework\TestCase;

/**
 * @see Finite
 */
final class FiniteTest extends TestCase
{
    public function testAssertReturnsFloatForFiniteValues(): void
    {
        self::assertSame(3.5, Finite::assert(3.5));
        self::assertSame(7.0, Finite::assert(7));   // int coerced to float
        self::assertSame(-0.0, Finite::assert(-0.0));
    }

    /**
     * @dataProvider nonFiniteCases
     */
    public function testAssertRejectsNonFinite(int|float $value, string $descriptor): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($descriptor);
        Finite::assert($value);
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function nonFiniteCases(): iterable
    {
        yield 'NaN'          => [NAN, 'NaN'];
        yield 'positive INF' => [INF, 'INF'];
        yield 'negative INF' => [-INF, '-INF'];
    }

    public function testAssertAllAcceptsFiniteList(): void
    {
        Finite::assertAll([1, 2.5, -3, 0.0]);
        $this->addToAssertionCount(1);   // no throw == pass
    }

    public function testAssertAllRejectsFirstNonFinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Finite::assertAll([1.0, 2.0, NAN, 4.0]);
    }

    public function testAssertAllAcceptsEmptyList(): void
    {
        Finite::assertAll([]);
        $this->addToAssertionCount(1);
    }
}
