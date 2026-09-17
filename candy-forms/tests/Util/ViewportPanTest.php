<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Util\ViewportPan;

/**
 * Pins for the window-pan tail extracted from the byte-identical tails of
 * ItemList::moveCursor() and FilePicker::moveCursor() (E736 plan 3.7).
 */
final class ViewportPanTest extends TestCase
{
    public function testCursorInsideWindowKeepsOffset(): void
    {
        $this->assertSame(3, ViewportPan::offsetFor(5, 3, 10));
    }

    public function testCursorAboveWindowPullsOffsetBack(): void
    {
        $this->assertSame(1, ViewportPan::offsetFor(1, 4, 5));
    }

    public function testCursorBelowWindowPushesOffsetForward(): void
    {
        $this->assertSame(6, ViewportPan::offsetFor(8, 0, 3));
    }

    public function testUnboundedHeightNeverPushes(): void
    {
        $this->assertSame(0, ViewportPan::offsetFor(99, 0, 0));
        $this->assertSame(0, ViewportPan::offsetFor(99, 0, -1));
    }

    public function testOffsetIsNeverNegative(): void
    {
        $this->assertSame(0, ViewportPan::offsetFor(0, -5, 10));
    }

    public function testBoundaryCursorExactlyAtWindowEndStays(): void
    {
        // height 3 shows rows 0..2; cursor 2 is still on screen.
        $this->assertSame(0, ViewportPan::offsetFor(2, 0, 3));
    }

    public function testItemListStyleWrapFeedsPanUnchanged(): void
    {
        // The per-class heads (ItemList wrap vs FilePicker clamp) stay in
        // place; pan only ever sees an already-resolved cursor. A wrapped
        // index behaves identically to a clamped one here.
        $this->assertSame(
            ViewportPan::offsetFor(7, 0, 3),
            ViewportPan::offsetFor((7 % 10 + 10) % 10, 0, 3),
        );
    }
}
