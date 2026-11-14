<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Support\ListWindow;

/**
 * Pins for the shared scroll-window formula (finding sugar-dashboard-051):
 * one windowing law for the calendar list, Select, and ComboBox. The
 * formula is ListComponent's long-pinned behaviour lifted intact.
 */
final class ListWindowTest extends TestCase
{
    public function testWindowAtLeastAsTallAsTheListShowsEverything(): void
    {
        self::assertSame(0, ListWindow::scrollTop(0, 5, 3));
        self::assertSame(0, ListWindow::scrollTop(2, 5, 5));
    }

    public function testEmptyListNeedsNoScroll(): void
    {
        self::assertSame(0, ListWindow::scrollTop(0, 5, 0));
    }

    public function testSelectionNearTopIsClampedToTheFirstRow(): void
    {
        // floor(0 - 4/2) = -2 -> clamps to 0
        self::assertSame(0, ListWindow::scrollTop(0, 4, 10));
        // floor(1 - 4/2) = -1 -> clamps to 0
        self::assertSame(0, ListWindow::scrollTop(1, 4, 10));
    }

    public function testSelectionNearBottomIsClampedToTheLastWindow(): void
    {
        // floor(9 - 4/2) = 7 -> clamps to total-height = 6
        self::assertSame(6, ListWindow::scrollTop(9, 4, 10));
        // floor(8 - 4/2) = 6 -> exactly the last window
        self::assertSame(6, ListWindow::scrollTop(8, 4, 10));
    }

    public function testMidListSelectionIsCentredOnTheActiveRow(): void
    {
        // floor(5 - 4/2) = 3
        self::assertSame(3, ListWindow::scrollTop(5, 4, 10));
        // floor(5 - 5/2) = floor(2.5) = 2
        self::assertSame(2, ListWindow::scrollTop(5, 5, 10));
    }
}
