<?php

declare(strict_types=1);

namespace SugarCraft\Mines\Tests;

use SugarCraft\Mines\Board;
use PHPUnit\Framework\TestCase;

final class BoardTest extends TestCase
{
    public function testBlankBoardHasNoRevealedOrMineCells(): void
    {
        $b = Board::blank(5, 5, 3);
        $this->assertSame(5, $b->width);
        $this->assertSame(5, $b->height);
        $this->assertFalse($b->minesPlaced);
        foreach ($b->rows() as $row) {
            foreach ($row as $c) {
                $this->assertFalse($c->revealed);
                $this->assertFalse($c->mine);
            }
        }
    }

    public function testInvalidDimensionsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Board::blank(1, 5, 1);
    }

    public function testInvalidMineCountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 5×5 grid = 25 cells; minimum 1 unmined cell required → cap at 24.
        Board::blank(5, 5, 25);
    }

    public function testFirstRevealAvoidsMinesNearCursor(): void
    {
        // Deterministic shuffle: use $rand that always returns $max
        // → equivalent to no-shuffle.
        $rand = static fn(int $max): int => $max;
        $b = Board::blank(5, 5, 5)->reveal(2, 2, $rand);
        // Centre cell + its 8 neighbours must not be mined.
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $cell = $b->cell(2 + $dx, 2 + $dy);
                $this->assertNotNull($cell);
                $this->assertFalse($cell->mine, "expected (2,2)+($dx,$dy) safe");
            }
        }
    }

    public function testFirstRevealOnEmptyAreaFloodsRecursively(): void
    {
        // Board with no mines at all: everything should reveal in one click.
        $rand = static fn(int $max): int => 0;
        // We need at least 1 mine; place it in a corner the flood won't reach
        // before stopping at adjacency cells.
        $b = Board::blank(5, 5, 1)->reveal(2, 2, $rand);
        $revealedCount = 0;
        $unrevealed = 0;
        foreach ($b->rows() as $row) {
            foreach ($row as $c) {
                if ($c->revealed) $revealedCount++;
                else $unrevealed++;
            }
        }
        // The whole board minus the corner mine + adjacency border should reveal.
        $this->assertGreaterThan(15, $revealedCount);
    }

    public function testFlagToggle(): void
    {
        $b = Board::blank(3, 3, 1);
        $b = $b->toggleFlag(1, 1);
        $this->assertTrue($b->cell(1, 1)->flagged);
        $b = $b->toggleFlag(1, 1);
        $this->assertFalse($b->cell(1, 1)->flagged);
    }

    public function testFlaggedCellCannotReveal(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(3, 3, 1)->toggleFlag(1, 1)->reveal(1, 1, $rand);
        $this->assertFalse($b->cell(1, 1)->revealed);
    }

    public function testRevealingMineExplodes(): void
    {
        // Force every "random" pick to keep the original ordering, then
        // reveal a corner that we know is in the candidate-list tail.
        $rand = static fn(int $max): int => 0;
        // Click at (0,0): mines placed in cells outside that 3×3.
        $b = Board::blank(3, 4, 1)->reveal(0, 0, $rand);
        $this->assertTrue($b->minesPlaced);
        $this->assertFalse($b->exploded);  // first click is always safe
        // Find the mined cell + reveal it.
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                if ($b->cell($x, $y)->mine) {
                    $b = $b->reveal($x, $y, $rand);
                    break 2;
                }
            }
        }
        $this->assertTrue($b->exploded);
    }

    public function testWinDetection(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(3, 3, 1)->reveal(0, 0, $rand);
        // Find the mine cell, flag it; reveal everything else.
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                if (!$b->cell($x, $y)->mine && !$b->cell($x, $y)->revealed) {
                    $b = $b->reveal($x, $y, $rand);
                }
            }
        }
        $this->assertFalse($b->exploded);
        $this->assertTrue($b->isWon());
    }

    public function testChordClickRevealsNeighborsWhenSatisfied(): void
    {
        // Manually set up a 3×3 board where center (1,1) has adjacent=1,
        // one neighbor is flagged, and one neighbor is unrevealed safe.
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new \SugarCraft\Mines\Cell(false, false, false, 0);
            }
            $rows[] = $row;
        }
        // (1,1) center is revealed with adjacent=1 (satisfied)
        $rows[1][1] = new \SugarCraft\Mines\Cell(false, true, false, 1);
        // (0,1) left neighbor is flagged (counts as the correct mine flag)
        $rows[1][0] = new \SugarCraft\Mines\Cell(false, false, true, 0);
        // (2,1) right neighbor is unrevealed safe cell (should be revealed by chord)
        $rows[1][2] = new \SugarCraft\Mines\Cell(false, false, false, 0);

        $board = new Board(3, 3, 1, $rows, true, false);

        // Chord on center (1,1) — it has adjacent=1 and exactly 1 neighbor flagged
        // → should reveal (2,1) but not (0,1) since it's already flagged.
        $next = $board->chord(1, 1);

        $this->assertTrue($next->cell(2, 1)->revealed, 'Unflagged neighbor should be revealed');
        $this->assertFalse($next->cell(0, 1)->revealed, 'Flagged neighbor stays unrevealed');
        // Center cell stays revealed (chord only reveals neighbors, not the center)
        $this->assertTrue($next->cell(1, 1)->revealed, 'Center cell stays revealed');
    }

    public function testChordDoesNothingWhenNotSatisfied(): void
    {
        // Board with center revealed number but NOT all adjacent mines flagged.
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new \SugarCraft\Mines\Cell(false, false, false, 0);
            }
            $rows[] = $row;
        }
        $rows[1][1] = new \SugarCraft\Mines\Cell(false, true, false, 2);
        // Only 1 of 2 adjacent mines is flagged — not satisfied.
        $rows[1][0] = new \SugarCraft\Mines\Cell(false, false, true, 0);

        $board = new Board(3, 3, 1, $rows, true, false);

        $next = $board->chord(1, 1);

        $this->assertFalse($next->cell(0, 1)->revealed);
        $this->assertFalse($next->cell(2, 1)->revealed);
    }

    public function testChordOnUnrevealedCellNoops(): void
    {
        $b = Board::blank(3, 3, 1);
        $this->assertSame($b, $b->chord(1, 1));
    }

    public function testChordOnZeroAdjacentNoops(): void
    {
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new \SugarCraft\Mines\Cell(false, false, false, 0);
            }
            $rows[] = $row;
        }
        $rows[1][1] = new \SugarCraft\Mines\Cell(false, true, false, 0);

        $board = new Board(3, 3, 1, $rows, true, false);

        $this->assertSame($board, $board->chord(1, 1));
    }

    // ─── O(1) win detection via revealedCount ─────────────────────────────────

    public function testRevealedCountIncrementsOnFloodReveal(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(5, 5, 1);
        $this->assertSame(0, $b->revealedCount);
        $b = $b->reveal(2, 2, $rand);
        $this->assertGreaterThan(0, $b->revealedCount);
    }

    public function testIsWonUsesO1RevealedCountCheck(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(3, 3, 1)->reveal(0, 0, $rand);
        // Reveal all non-mine cells.
        for ($y = 0; $y < 3; $y++) {
            for ($x = 0; $x < 3; $x++) {
                if (!$b->cell($x, $y)->mine && !$b->cell($x, $y)->revealed) {
                    $b = $b->reveal($x, $y, $rand);
                }
            }
        }
        $this->assertFalse($b->exploded);
        $this->assertTrue($b->isWon());
        // O(1) check: revealedCount must equal total safe cells.
        $this->assertSame(3 * 3 - 1, $b->revealedCount);
    }

    public function testIsWonFalseBeforeAllCellsRevealed(): void
    {
        $rand = static fn(int $max): int => 0;
        $b = Board::blank(5, 5, 1)->reveal(0, 0, $rand);
        $this->assertFalse($b->isWon());
    }

    public function testChordIncrementsRevealedCount(): void
    {
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new \SugarCraft\Mines\Cell(false, false, false, 0);
            }
            $rows[] = $row;
        }
        // Center revealed with adjacent=1, one neighbor flagged.
        $rows[1][1] = new \SugarCraft\Mines\Cell(false, true, false, 1);
        $rows[1][0] = new \SugarCraft\Mines\Cell(false, false, true, 0);

        $board = new Board(3, 3, 1, $rows, true, false, 1);
        $this->assertSame(1, $board->revealedCount);

        // Chord reveals all 7 unflagged neighbors → 1 + 7 = 8.
        $next = $board->chord(1, 1);
        $this->assertSame(8, $next->revealedCount);
    }

    // ─── Board serialization ─────────────────────────────────────────────────────

    public function testSerializeProducesString(): void
    {
        $b = Board::blank(5, 5, 3);
        $s = $b->serialize();
        $this->assertIsString($s);
        $this->assertNotEmpty($s);
    }

    public function testUnserializeRoundTrips(): void
    {
        $rand = static fn(int $max): int => 0;
        $original = Board::blank(5, 5, 3)->reveal(2, 2, $rand);

        $serialized = $original->serialize();
        $restored = Board::unserialize($serialized);

        $this->assertSame($original->width, $restored->width);
        $this->assertSame($original->height, $restored->height);
        $this->assertSame($original->mineCount, $restored->mineCount);
        $this->assertSame($original->minesPlaced, $restored->minesPlaced);
        $this->assertSame($original->exploded, $restored->exploded);
        $this->assertSame($original->revealedCount, $restored->revealedCount);

        // Check cells match.
        for ($y = 0; $y < $original->height; $y++) {
            for ($x = 0; $x < $original->width; $x++) {
                $oc = $original->cell($x, $y);
                $rc = $restored->cell($x, $y);
                $this->assertSame($oc->mine, $rc->mine);
                $this->assertSame($oc->revealed, $rc->revealed);
                $this->assertSame($oc->flagged, $rc->flagged);
                $this->assertSame($oc->adjacent, $rc->adjacent);
            }
        }
    }

    public function testUnserializeInvalidPayloadThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Board::unserialize('not valid json');
    }

    public function testUnserializePartialPayloadThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Board::unserialize('{"w":5}');
    }

    public function testSerializeAfterFlagUnaffected(): void
    {
        $b = Board::blank(5, 5, 3)->toggleFlag(1, 1)->toggleFlag(2, 2);
        $s = $b->serialize();
        $r = Board::unserialize($s);
        $this->assertTrue($r->cell(1, 1)->flagged);
        $this->assertTrue($r->cell(2, 2)->flagged);
    }

    /**
     * Regression test: chord into a zero-adjacent neighbour must cascade
     * through the full empty pocket, not just reveal the single neighbour.
     *
     * Setup — 5×3 board, mines at (0,0) and (0,2):
     *   Row 0: [mine@flagged, adj=2, mine,  adj=0,  adj=0 ]
     *   Row 1: [adj=1,       revealed@adj=1, adj=1, adj=0,  adj=0 ]
     *   Row 2: [adj=0,       adj=1,        adj=0, adj=0,  adj=0 ]
     *
     * (1,1) is revealed with adj=1 and has exactly one flagged neighbour at (0,0).
     * (1,2) is an unrevealed safe cell with adj=0 — neither mine at (0,0) nor (0,2)
     * touches it. When chord(1,1) fires, floodReveal runs on (1,2), sees adj=0, and
     * cascades to its zero-adjacent neighbours including (2,2).
     *
     * This test fails before the flood-fill chord fix and passes after.
     */
    public function testChordCascadesIntoEmptyRegion(): void
    {
        // Build the 5×3 grid by hand with exact adjacent values.
        // Rows[y][x] — y=0 is top row; mines are at (0,0) and (0,2).
        // Cell (0,0): mine (flagged), so adj values of its neighbours are affected.
        // Cell (0,2): mine (unflagged), affects adj values too.
        $rows = [
            // y=0: two mines (one flagged), the rest clear.
            [new \SugarCraft\Mines\Cell(true,  false, true,  0), new \SugarCraft\Mines\Cell(false, false, false, 2), new \SugarCraft\Mines\Cell(true,  false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 0)],
            // y=1: the satisfied revealed number; (1,2) is adj=0 zero-pocket.
            [new \SugarCraft\Mines\Cell(false, false, false, 1), new \SugarCraft\Mines\Cell(false, true,  false, 1), new \SugarCraft\Mines\Cell(false, false, false, 1), new \SugarCraft\Mines\Cell(false, false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 0)],
            // y=2: the cascading zero-adjacent pocket.
            [new \SugarCraft\Mines\Cell(false, false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 1), new \SugarCraft\Mines\Cell(false, false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 0), new \SugarCraft\Mines\Cell(false, false, false, 0)],
        ];

        // minesPlaced=true, exploded=false, revealedCount=1 (only (1,1) revealed).
        $board = new Board(5, 3, 2, $rows, true, false, 1);

        // Chord at (1,1): adj=1, one flagged neighbour → satisfied.
        $next = $board->chord(1, 1);

        // The immediate unflagged neighbour (1,2) must be revealed.
        $this->assertTrue($next->cell(1, 2)->revealed, 'Immediate neighbour must be revealed by chord');

        // A cell two steps deeper — (2,2) — must also be revealed (proves cascade,
        // not just single-cell reveal).
        $this->assertTrue($next->cell(2, 2)->revealed, 'Cascade must reach deeper empty-pocket cells');

        // revealedCount must grow beyond the pre-chord baseline of 1.
        $this->assertGreaterThan(1, $next->revealedCount, 'Cascade must reveal more than one cell');
    }
}
