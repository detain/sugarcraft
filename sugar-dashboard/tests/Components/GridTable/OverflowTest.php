<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Overflow;
use SugarCraft\Dashboard\Components\GridTable\OverflowMode;

/**
 * D-143: Overflow ships an asymmetric default (truncate horizontally,
 * wrap vertically) that no factory reproduces — the asymmetry is a
 * deliberate policy, and a "tidy-up" to symmetric defaults would change
 * every overflowing grid's shape while the suite stayed green.
 */
final class OverflowTest extends TestCase
{
    public function testDefaultAndNewFallbackAreHorizontallyTruncatingVerticallyWrapping(): void
    {
        foreach ([new Overflow(), Overflow::new()] as $overflow) {
            self::assertSame(OverflowMode::Truncate, $overflow->horizontal());
            self::assertSame(OverflowMode::Wrap, $overflow->vertical());
        }
    }

    public function testNewAcceptsExplicitAxesIndependently(): void
    {
        $mixed = Overflow::new(horizontal: OverflowMode::Ellipsis, vertical: OverflowMode::Truncate);

        self::assertSame(OverflowMode::Ellipsis, $mixed->horizontal());
        self::assertSame(OverflowMode::Truncate, $mixed->vertical());
    }

    public function testNamedFactoriesSetBothAxesAsAPair(): void
    {
        $truncate = Overflow::truncate();
        self::assertSame(OverflowMode::Truncate, $truncate->horizontal());
        self::assertSame(OverflowMode::Truncate, $truncate->vertical());

        $wrap = Overflow::wrap();
        self::assertSame(OverflowMode::Wrap, $wrap->horizontal());
        self::assertSame(OverflowMode::Wrap, $wrap->vertical());

        $ellipsis = Overflow::ellipsis();
        self::assertSame(OverflowMode::Ellipsis, $ellipsis->horizontal());
        self::assertSame(OverflowMode::Ellipsis, $ellipsis->vertical());
    }

    public function testOverflowModeRosterIsComplete(): void
    {
        self::assertSame(
            ['Truncate', 'Wrap', 'Ellipsis'],
            array_map(static fn(OverflowMode $mode): string => $mode->name, OverflowMode::cases()),
        );
    }
}
