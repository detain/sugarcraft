<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\BarChart;

use SugarCraft\Charts\BarChart\Bar;
use PHPUnit\Framework\TestCase;

/**
 * @see Bar
 */
final class BarTest extends TestCase
{
    public function testConstructWithLabelAndValue(): void
    {
        $bar = new Bar('Sales', 42.0);

        self::assertSame('Sales', $bar->label);
        self::assertSame(42.0, $bar->value);
    }

    public function testLabelCanBeEmpty(): void
    {
        $bar = new Bar('', 99.0);
        self::assertSame('', $bar->label);
    }

    public function testLabelCanContainUnicode(): void
    {
        $bar = new Bar('売上', 50.0);
        self::assertSame('売上', $bar->label);
    }

    public function testIntegerValueIsCoercedToFloat(): void
    {
        $bar = new Bar('Count', 7);
        self::assertSame(7.0, $bar->value);
        self::assertIsFloat($bar->value);
    }

    public function testNegativeValueIsAccepted(): void
    {
        $bar = new Bar('Loss', -25.5);
        self::assertSame(-25.5, $bar->value);
    }

    public function testZeroValueIsAccepted(): void
    {
        $bar = new Bar('Zero', 0.0);
        self::assertSame(0.0, $bar->value);
    }

    /**
     * @dataProvider finiteValueProvider
     */
    public function testFiniteValuesAreAccepted(float $value): void
    {
        $bar = new Bar('Test', $value);
        self::assertSame($value, $bar->value);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function finiteValueProvider(): iterable
    {
        yield 'positive'       => [100.0];
        yield 'negative'       => [-50.0];
        yield 'zero'           => [0.0];
        yield 'small fraction' => [0.001];
        yield 'large value'    => [1e10];
        yield 'int coerced'   => [42];
    }

    public function testRejectsNaNValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NaN');
        new Bar('Bad', NAN);
    }

    public function testRejectsPositiveInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('INF');
        new Bar('Bad', INF);
    }

    public function testRejectsNegativeInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-INF');
        new Bar('Bad', -INF);
    }

    /**
     * @dataProvider labelValuesProvider
     */
    public function testLabelAndValueAreExposedCorrectly(string $label, float $value): void
    {
        $bar = new Bar($label, $value);
        self::assertSame($label, $bar->label);
        self::assertSame($value, $bar->value);
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function labelValuesProvider(): iterable
    {
        yield 'alpha label' => ['Alpha', 1.0];
        yield 'numeric label' => ['123', 2.0];
        yield 'special chars' => ['Q1-Q2', 3.0];
        yield 'long label' => ['Very long category name that might be truncated in display', 4.0];
    }
}
