<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region;

/**
 * B2 (lane A3a) — the mutating API enforces the wide-cell pair invariant
 * with the same null-continuation discipline applyDiff() already follows:
 * a width-2 lead owns a width-0 continuation to its right, and neither
 * half of a pair can be silently orphaned by withCellAt()/fill()/withRegion().
 */
final class WideCellPairingTest extends TestCase
{
    private const WIDE = '中';

    public function testWithCellAtWritesTheContinuationForAWideLead(): void
    {
        // A junk cell pre-sits in the partner slot; the wide write must
        // claim it as a continuation instead of leaving a broken pair.
        $buf = Buffer::new(3, 1)->withCellAt(1, 0, Cell::new('Z'));

        $paired = $buf->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));

        self::assertSame(2, $paired->cellAt(0, 0)->width());
        self::assertSame(0, $paired->cellAt(1, 0)->width());
        self::assertSame('', $paired->cellAt(1, 0)->rune());
        // The uninvolved column past the pair is untouched (still default).
        self::assertSame(' ', $paired->cellAt(2, 0)->rune());
    }

    public function testReplacingAWideLeadBlanksItsOrphanedContinuation(): void
    {
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));

        $cleared = $buf->withCellAt(0, 0, Cell::new('x'));

        self::assertSame('x', $cleared->cellAt(0, 0)->rune());
        // Without the sweep the stale width-0 cell at col 1 is skipped by
        // diff()/toAnsi() forever — the ghost-glyph one-column-over defect.
        self::assertSame(1, $cleared->cellAt(1, 0)->width());
        self::assertSame(' ', $cleared->cellAt(1, 0)->rune());
    }

    public function testWritingOntoAContinuationSweepsTheStrandedLead(): void
    {
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));

        $split = $buf->withCellAt(1, 0, Cell::new('Y'));

        // The pair was torn from the right: the lead cannot stay width-2
        // without its continuation, so it is blanked alongside.
        self::assertSame(1, $split->cellAt(0, 0)->width());
        self::assertSame(' ', $split->cellAt(0, 0)->rune());
        self::assertSame('Y', $split->cellAt(1, 0)->rune());
    }

    public function testStraddleAtLastColumnKeepsOnlyTheLead(): void
    {
        // applyDiff() silently skips an out-of-bounds continuation; the
        // mutator must not wrap the pair onto the next row.
        $buf = Buffer::new(2, 2)->withCellAt(1, 0, Cell::new(self::WIDE, null, null, 2));

        self::assertSame(2, $buf->cellAt(1, 0)->width());
        self::assertSame(1, $buf->cellAt(0, 1)->width());
        self::assertSame(' ', $buf->cellAt(0, 1)->rune());
    }

    public function testClearViaWithCellAtMakesTheRowRepaintCleanly(): void
    {
        // Consumer-shape pin (sugar-dash Chart's per-column withCellAt
        // writes): after clearing a wide cell the delta from the paired
        // frame must repaint BOTH columns, not silently keep a ghost.
        $before = Buffer::new(2, 1)
            ->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));
        $after = $before
            ->withCellAt(0, 0, Cell::new(' '))
            ->withCellAt(1, 0, Cell::new(' '));

        $wire = (new \SugarCraft\Buffer\Diff\DiffEncoder())
            ->encode($after->diff($before));

        // The delta must repaint BOTH columns — as two written blanks, an
        // ECH run, or a blank plus REP run-over (" \x1b[1b" is today's
        // actual shape). Pre-fix, the stale width-0 continuation at col 1
        // was skipped by diff(), so the wire only ever covered column 0
        // and the ghost survived the "clear".
        $coversBothColumns = str_contains($wire, '  ')
            || str_contains($wire, "\x1b[2X")
            || (bool) preg_match('/ \x1b\[(?:[1-9]\d*)b/', $wire);

        self::assertTrue(
            $coversBothColumns,
            'delta must cover both columns of the cleared pair, got: ' . bin2hex($wire),
        );
    }

    public function testFillOfAWideCellPairsEveryInBoundsLead(): void
    {
        $buf = Buffer::new(3, 1);

        $filled = $buf->fill(new Region(Position::new(0, 0), 1, 1), Cell::new(self::WIDE, null, null, 2));

        self::assertSame(2, $filled->cellAt(0, 0)->width());
        self::assertSame(0, $filled->cellAt(1, 0)->width());
    }

    public function testRegionBlitCarriesWellFormedPairsIntact(): void
    {
        $src = Buffer::new(2, 1)
            ->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));
        $dst = Buffer::new(4, 1);

        $blit = $dst->withRegion(new Region(Position::new(1, 0), 2, 1), $src);

        self::assertSame(2, $blit->cellAt(1, 0)->width());
        self::assertSame(0, $blit->cellAt(2, 0)->width());
        self::assertSame(self::WIDE, $blit->cellAt(1, 0)->rune());
        self::assertSame('', $blit->cellAt(2, 0)->rune());
    }

    public function testMutationsLeavetheOriginalBufferUntouched(): void
    {
        $buf = Buffer::new(2, 1);

        $buf->withCellAt(0, 0, Cell::new(self::WIDE, null, null, 2));

        self::assertSame(' ', $buf->cellAt(0, 0)->rune());
        self::assertSame(1, $buf->cellAt(1, 0)->width());
    }
}
