<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;
use SugarCraft\Vterm\Theme;

/**
 * vterm-10: the renderer path now models tab stops through the SAME
 * stateless TabHandler arithmetic as the emulator (defaults every 8 columns
 * from 8, HTS sets at the cursor, TBC 0 clears at the cursor / 3 clears all,
 * RIS rebuilds). State edges invisible to cell grids (a stop set at the
 * cursor column, the out-of-width HTS gate) are pinned here; byte-level
 * two-engine parity lives in EngineDifferentialTest's tab arms.
 */
final class RendererTabsTest extends TestCase
{
    private function handler(int $cols = 20, int $rows = 5): CsiHandlerImpl
    {
        return new CsiHandlerImpl(new Buffer($cols, $rows), new Cursor(), new Theme());
    }

    private function col(CsiHandlerImpl $h): int
    {
        return $h->cursor()->col;
    }

    public function testDefaultsLandEveryEightColumns(): void
    {
        $h = $this->handler();
        // From column 0 the first default stop is 8, then 16 (width 20).
        $h->cht(1);
        $this->assertSame(8, $this->col($h));
        $h->cht(1);
        $this->assertSame(16, $this->col($h));
    }

    public function testChtCountWalksStopsNotColumns(): void
    {
        $h = $this->handler();
        $h->cht(2);
        $this->assertSame(16, $this->col($h), 'CSI 2I is two STOPS, never col 2');
    }

    public function testForwardClampsToRightEdgeWithoutRemainingStops(): void
    {
        $h = $this->handler();
        $h->cht(9); // 8, 16, then no stop left: clamp at cols-1 = 19.
        $this->assertSame(19, $this->col($h));
        $h->cht(1);
        $this->assertSame(19, $this->col($h));
    }

    public function testBackwardWalksPreviousStopsAndClampsAtZero(): void
    {
        $h = $this->handler();
        $h->cht(2); // col 16
        $h->cbt(1);
        $this->assertSame(8, $this->col($h));
        $h->cbt(1);
        $this->assertSame(0, $this->col($h));
        $h->cbt(5);
        $this->assertSame(0, $this->col($h), 'CBT past the first stop clamps at column 0');
    }

    public function testHtsSetsAStopAtTheCursorColumn(): void
    {
        $h = $this->handler();
        $h->cup(1, 4); // column 3
        $h->escSetTabStop();
        $h->cr();
        $h->cht(1);
        $this->assertSame(3, $this->col($h), 'HT stops at the hard stop before the default 8');
    }

    public function testHtsAtLastColumnSetsAStop(): void
    {
        $h = $this->handler();
        $h->cup(1, 20); // column 19 — inside the grid, legal stop
        $h->escSetTabStop();
        $h->cr();
        $h->cht(9);
        $this->assertSame(19, $this->col($h));
    }

    public function testTbcZeroClearsTheStopAtTheCursorOnly(): void
    {
        $h = $this->handler();
        $h->cht(1); // col 8
        $h->tbc(0); // clear stop 8
        $h->cr();
        $h->cht(1);
        $this->assertSame(16, $this->col($h), 'only the cursor-column stop was cleared');
    }

    public function testTbcThreeClearsEverythingAndHtFallsToEdge(): void
    {
        $h = $this->handler();
        $h->cup(1, 4);
        $h->escSetTabStop();
        $h->tbc(3);
        $h->cr();
        $h->cht(1);
        $this->assertSame(19, $this->col($h));
    }

    public function testTbcUnknownModeIsANoop(): void
    {
        $h = $this->handler();
        $h->tbc(1);
        $h->cr();
        $h->cht(1);
        $this->assertSame(8, $this->col($h));
    }

    public function testRisRebuildsTheDefaultStopTable(): void
    {
        $h = $this->handler();
        $h->tbc(3);
        $h->escResetToInitialState();
        $h->cht(1);
        $this->assertSame(8, $this->col($h), 'RIS restores the every-8 defaults');
    }
}
