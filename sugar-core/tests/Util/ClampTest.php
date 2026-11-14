<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Clamp;

/**
 * First tests for {@see Clamp} (finding sugar-core-067 leg: utility classes
 * shipped with zero tests). The family contract is total coercion: every
 * input answers a legal value and nothing is signalled — the deliberate
 * opposite of {@see \SugarCraft\Core\Util\Validation}, which refuses. The
 * sc-38 role line on the class docblock is the doctrine these pins hold.
 */
final class ClampTest extends TestCase
{
    /**
     * @dataProvider closedIntervalShapes
     *
     * @param array{0:int,1:int,2:int} $args
     */
    public function testIntClampsToTheClosedInterval(array $args, int $expected): void
    {
        [$value, $min, $max] = $args;
        $this->assertSame($expected, Clamp::int($value, $min, $max));
    }

    /**
     * @return array<string, array{0:array{0:int,1:int,2:int},1:int}>
     */
    public static function closedIntervalShapes(): array
    {
        return [
            'inside passes through'   => [[5, 0, 10], 5],
            'below lands on min'      => [[-3, 0, 7], 0],
            'above lands on max'      => [[99, 0, 7], 7],
            'exactly min is min'      => [[0, 0, 10], 0],
            'exactly max is max'      => [[10, 0, 10], 10],
            'degenerate interval'     => [[42, 7, 7], 7],
            'negatives only'          => [[-5, -10, -1], -5],
            'value below a negative min' => [[-50, -10, -1], -10],
            'int extremes below'      => [[\PHP_INT_MIN, 0, \PHP_INT_MAX], 0],
            'int extremes above'      => [[\PHP_INT_MAX, 0, 100], 100],
        ];
    }

    public function testFloatClampsToTheClosedInterval(): void
    {
        $this->assertSame(0.5, Clamp::float(0.5, 0.0, 1.0));
        $this->assertSame(-1.0, Clamp::float(-5.5, -1.0, 1.0));
        $this->assertSame(1.0, Clamp::float(5.5, -1.0, 1.0));
        // Infinity is total: it clamps, it never passes through a bound.
        $this->assertSame(1.0, Clamp::float(\INF, 0.0, 1.0));
        $this->assertSame(0.0, Clamp::float(-\INF, 0.0, 1.0));
    }

    public function testNonNegDefaultsToUnboundedAbove(): void
    {
        $this->assertSame(0, Clamp::nonNeg(-1));
        $this->assertSame(\PHP_INT_MAX, Clamp::nonNeg(\PHP_INT_MAX));
        $this->assertSame(512, Clamp::nonNeg(1000, 512));
        $this->assertSame(3, Clamp::nonNeg(3, 512));
    }

    public function testByteClampsToTheColorComponentRange(): void
    {
        $this->assertSame(0, Clamp::byte(-10));
        $this->assertSame(0, Clamp::byte(0));
        $this->assertSame(128, Clamp::byte(128));
        $this->assertSame(255, Clamp::byte(255));
        $this->assertSame(255, Clamp::byte(300));
    }

    public function testCoercionIsTotalAndSilent(): void
    {
        // The Validation counterpart throws on out-of-range; Clamp must not.
        // One call per method with an out-of-range input, asserting a void
        // of exceptions AND of diagnostics:
        \ob_start();
        $int  = Clamp::int(11, 0, 10);
        $flt  = Clamp::float(2.0, 0.0, 1.0);
        $non  = Clamp::nonNeg(-1);
        $byte = Clamp::byte(999);
        $out  = \ob_get_clean();
        $this->assertSame([10, 1.0, 0, 255], [$int, $flt, $non, $byte]);
        $this->assertSame('', $out, 'coercion must not warn — out-of-range is a legal answer here');
    }
}
