<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Footer;

/**
 * D-143: Footer is a configuration carrier; its docblock claims it
 * "renders a footer row with summaries" but render() is a dead method
 * returning '' (GridTable builds the footer itself from the carrier
 * slots — the annotation seat is stale vs disk, disclosed). The pins
 * cover what the class actually guarantees: defaults, the none() door,
 * and each wither carrying the untouched slot.
 */
final class FooterTest extends TestCase
{
    public function testDefaultsSummaryNullAndRowCountOn(): void
    {
        $footer = Footer::new();

        self::assertNull($footer->summaryColumns());
        self::assertTrue($footer->shouldShowRowCount());
    }

    public function testNoneDisablesBothSlots(): void
    {
        $footer = Footer::none();

        self::assertNull($footer->summaryColumns());
        self::assertFalse($footer->shouldShowRowCount());
    }

    public function testNewCarriesExplicitSlotsThrough(): void
    {
        $footer = Footer::new(['total' => 'sum'], false);

        self::assertSame(['total' => 'sum'], $footer->summaryColumns());
        self::assertFalse($footer->shouldShowRowCount());
    }

    public function testWithersCarryTheUntouchedSlot(): void
    {
        $base = Footer::new(['a' => 'sum'], true);

        $summariesOnly = $base->withSummaryColumns(['b' => 'avg']);
        self::assertSame(['b' => 'avg'], $summariesOnly->summaryColumns());
        self::assertTrue($summariesOnly->shouldShowRowCount(), 'withSummaryColumns must carry showRowCount');

        $rowCountOnly = $base->withShowRowCount(false);
        self::assertFalse($rowCountOnly->shouldShowRowCount());
        self::assertSame(['a' => 'sum'], $rowCountOnly->summaryColumns(), 'withShowRowCount must carry summaryColumns');

        // Immutability of the base through both doors.
        self::assertSame(['a' => 'sum'], $base->summaryColumns());
        self::assertTrue($base->shouldShowRowCount());

        // Summary columns can be explicitly reset to null through the wither.
        self::assertNull($base->withSummaryColumns(null)->summaryColumns());
    }

    public function testRenderIsADeadEmptyPaintingDisclosedVsDocblock(): void
    {
        // Whatever the carrier says, render() itself contributes nothing
        // on disk today; if a future change starts painting through this
        // method, this pin flips loudly and the docblock claim gets
        // honoured at the same time.
        self::assertSame('', Footer::new(['a' => 'sum'])->render());
        self::assertSame('', Footer::none()->render());
    }
}
