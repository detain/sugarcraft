<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * Pins for the lib-reviews-1103 Canvas fixes: SGR runs are coalesced
 * (previously every styled cell re-emitted its full open+reset pair,
 * roughly doubling wire bytes for styled rows), zero-width marks merge
 * onto the base rune instead of being dropped, writing over a wide cell's
 * continuation clears the orphaned anchor half, and {@see Canvas::viewPadded()}
 * exists so overlay hosts stop losing trailing styled cells to the rtrim.
 */
final class CanvasReviewPinsTest extends TestCase
{
    private const RED = "\x1b[38;2;255;0;0m";
    private const RESET = "\x1b[0m";

    private function styled(): Style
    {
        return Style::new()->foreground(Color::rgb(255, 0, 0));
    }

    public function testConsecutiveSameStyleCellsCoalesceIntoOneRun(): void
    {
        $c = new Canvas(4, 1);
        $style = $this->styled();
        $c->setCell(0, 0, 'a', $style);
        $c->setCell(1, 0, 'b', $style);
        $c->setCell(2, 0, '-', null);
        $c->setCell(3, 0, 'c', null);

        // Exactly one opener before 'ab', one reset after, then bare text.
        $this->assertSame(self::RED . 'ab' . self::RESET . '-c', $c->view());
    }

    public function testStyledRunEndsResetAtRowEndEvenWhenTrimmed(): void
    {
        $c = new Canvas(3, 1);
        $c->setCell(0, 0, 'x', $this->styled());
        // Trailing plain cells rtrim away; the open run must still close.
        $view = $c->view();
        $this->assertSame(self::RED . 'x' . self::RESET, $view);
    }

    public function testStyleChangeFlushesPreviousRun(): void
    {
        $c = new Canvas(2, 1);
        $c->setCell(0, 0, 'a', $this->styled());
        $c->setCell(1, 0, 'b', Style::new()->foreground(Color::rgb(0, 255, 0)));
        $view = $c->view();

        // First run closes before the second opener: no attribute bleed.
        $this->assertStringContainsString(self::RESET . "\x1b[38;2;0;255;0m", $view);
        $this->assertStringEndsWith(self::RESET, $view);
    }

    public function testViewPaddedKeepsFullRowWidthWhileViewTrims(): void
    {
        $c = new Canvas(4, 1);
        $c->setCell(0, 0, 'a', $this->styled());
        $c->setCell(1, 0, 'b', null);
        // Columns 2-3 are empty: view() trims them, viewPadded() keeps the
        // grid rectangular for overlay hosts that compose by column index.
        $this->assertSame(self::RED . 'a' . self::RESET . 'b', $c->view());
        $this->assertSame(self::RED . 'a' . self::RESET . 'b  ', $c->viewPadded());

        // Simpler contract statement: padding keeps trailing blanks intact.
        $e = new Canvas(3, 1);
        $e->setCell(0, 0, 'x');
        $this->assertSame('x', $e->view());
        $this->assertSame('x  ', $e->viewPadded());
    }

    public function testWritingOverWideContinuationClearsOrphanAnchor(): void
    {
        $c = new Canvas(4, 1);
        $c->setCell(0, 0, '一'); // width 2: anchor x0, continuation x1
        $c->setCell(1, 0, 'x');  // steal the continuation slot

        // The half-eaten anchor must vanish (previously a ghost '一' stayed
        // at x0 and the row rendered 3 columns' worth of glyphs).
        $this->assertSame(' x', $c->view());
    }

    public function testWideCellReservesItsContinuationSlot(): void
    {
        $c = new Canvas(4, 1);
        $c->setCell(0, 0, '一');
        $c->setCell(2, 0, 'z');
        // '一' occupies x0+x1; 'z' lands at x2 immediately after.
        $this->assertSame('一z', $c->view());
    }

    public function testZeroWidthMarkMergesOntoPreviousBase(): void
    {
        $c = new Canvas(4, 1);
        $c->setCell(0, 0, 'a', $this->styled());
        $c->setCell(0, 0, "\u{0301}"); // combining acute onto the base

        // One run, merged rune payload, no dropped bytes: previously the
        // zero-width cell was silently discarded.
        $this->assertSame(self::RED . "a\u{0301}" . self::RESET, $c->view());
    }

    public function testZeroWidthAtEmptyColumnFallsBackLeftwards(): void
    {
        $c = new Canvas(4, 1);
        $c->setCell(1, 0, 'b');
        $c->setCell(2, 0, "\u{0301}"); // merges left onto x1 base
        $this->assertSame(" b\u{0301}", $c->view());
    }
}
