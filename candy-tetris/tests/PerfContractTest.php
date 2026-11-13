<?php

/**
 * candy-tetris/tests/PerfContractTest.php
 *
 * The candy-tetris-010 spine (Buffer migration, memos, one-pass AI eval,
 * probe descent) rewrites MACHINERY, not MEANING. Every cure on that row
 * must answer the same bytes, the same placements and the same scores as
 * the code it replaced — so this file carries the OLD algorithms as
 * literal reference copies and demands the new ones agree, cell for cell
 * and centipoint for centipoint, across seeded boards.
 *
 * The second contract is memo liveness: a cache that survives the thing
 * it was keyed on (a locale flip, a remapped table, a recycled object
 * id) is how derived help lies to the player. Each memo door gets its
 * invalidation pressure pinned.
 */

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Keys\KeyBindings;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Computer;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\Tetromino;

final class PerfContractTest extends TestCase
{
    // ------------------------------------------------------------------
    // Reference copies of the pre-candy-tetris-050/-051/-073 algorithms.
    // ------------------------------------------------------------------

    private function referenceDropPiece(Board $board, Piece $piece): Piece
    {
        $candidate = $piece;
        while (true) {
            $next = $candidate->moved(0, 1);
            if ($board->fits($next) === false) {
                return $candidate;
            }
            $candidate = $next;
        }
    }

    /**
     * @param list<list<?Tetromino>> $rows
     */
    private function referenceEvaluate(array $rows): float
    {
        $height = 0;
        for ($col = 0; $col < Board::COLS; $col++) {
            for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
                if ($rows[$row][$col] !== null) {
                    $height += Board::ROWS - $row;
                    break;
                }
            }
        }

        $holes = 0;
        for ($col = 0; $col < Board::COLS; $col++) {
            $blockSeen = false;
            for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
                if ($rows[$row][$col] !== null) {
                    $blockSeen = true;
                } elseif ($blockSeen === true) {
                    $holes++;
                }
            }
        }

        $gaps = 0;
        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $hasBlock = false;
            $hasGap = false;
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($rows[$row][$col] !== null) {
                    $hasBlock = true;
                } elseif ($hasBlock === true) {
                    $hasGap = true;
                }
            }
            if ($hasBlock === true && $hasGap === true) {
                for ($col = 0; $col < Board::COLS; $col++) {
                    if ($rows[$row][$col] === null) {
                        $gaps++;
                    }
                }
            }
        }

        $lines = 0;
        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $full = true;
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($rows[$row][$col] === null) {
                    $full = false;
                    break;
                }
            }
            if ($full === true) {
                $lines++;
            }
        }

        return -4.5 * $height - 7.5 * $holes - 3.5 * $gaps + 9.0 * $lines;
    }

    /**
     * @return array{0:int,1:int,2:float}
     */
    private function referenceBestMove(Board $board, Piece $piece): array
    {
        $bestScore = -INF;
        $bestDx = 0;
        $bestRot = 0;

        foreach (range(0, 3) as $rotation) {
            $rotated = $piece;
            for ($r = 0; $r < $rotation; $r++) {
                $rotated = $rotated->rotated(1);
            }
            $cells = $rotated->cells();
            $minX = PHP_INT_MAX;
            $maxX = PHP_INT_MIN;
            foreach ($cells as [$x,]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
            }
            $pieceWidth = $maxX - $minX + 1;
            $maxXPos = Board::COLS - $pieceWidth;
            for ($dx = -$minX; $dx <= $maxXPos; $dx++) {
                $moved = $rotated->moved($dx, 0);
                if ($board->fits($moved) === false) {
                    continue;
                }
                $dropped = $this->referenceDropPiece($board, $moved);
                $placed = $board->place($dropped);
                $score = $this->referenceEvaluate($placed->rows());
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestDx = $dx;
                    $bestRot = $rotation;
                }
            }
        }

        return [$bestDx, $bestRot, $bestScore];
    }

    // ------------------------------------------------------------------
    // Seeded fixture boards.
    // ------------------------------------------------------------------

    /** @return list<Board> */
    private function seededBoards(int $count, int $salt = 0): array
    {
        $boards = [];
        for ($b = 0; $b < $count; $b++) {
            $rng = ($b * 1103515245 + $salt * 12345 + 7411011) & 0x7FFFFFFF;
            $next = static function () use (&$rng): int {
                $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
                return $rng;
            };
            $rows = [];
            for ($y = 0; $y < Board::ROWS; $y++) {
                $row = [];
                for ($x = 0; $x < Board::COLS; $x++) {
                    $roll = $next() % 100;
                    $row[] = $y >= Board::HIDDEN_ROWS + 12 && $roll < 38
                        ? Tetromino::cases()[$next() % 7]
                        : null;
                }
                $rows[] = $row;
            }
            $boards[] = new Board($rows);
        }

        return $boards;
    }

    // ------------------------------------------------------------------
    // dropPiece: probe descent ≡ the old step loop.
    // ------------------------------------------------------------------

    public function testTheProbeDescentMatchesTheStepLoopEverywhere(): void
    {
        foreach ($this->seededBoards(24) as $board) {
            foreach (Tetromino::cases() as $kind) {
                foreach ([0, 1, 2, 3] as $rotation) {
                    foreach ([0, 1, 3, 4, 5, 7, 9] as $x) {
                        foreach ([0, 5, 11, 18, 21] as $y) {
                            $piece = new Piece($kind, $rotation, $x, $y);
                            $mine = $board->dropPiece($piece);
                            $theirs = $this->referenceDropPiece($board, $piece);
                            self::assertSame(
                                $theirs->cells(),
                                $mine->cells(),
                                sprintf(
                                    'probe descent diverged for %s rot%d at (%d,%d)',
                                    $kind->name,
                                    $rotation,
                                    $x,
                                    $y,
                                ),
                            );
                        }
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // evaluate: the one-pass sweep ≡ the four walks.
    // ------------------------------------------------------------------

    public function testTheSinglePassEvaluateMatchesTheFourScanFormulas(): void
    {
        $ai = new Computer();
        foreach ($this->seededBoards(40, salt: 3) as $board) {
            self::assertSame(
                $this->referenceEvaluate($board->rows()),
                $ai->evaluate($board),
            );
        }

        // Deliberate law-corners the random boards sample badly: a full
        // row (lines, and gaps must NOT also count it), a row with only
        // leading nulls (no gap penalty — hasGap needs null-AFTER-block),
        // a hole stacked under a block, and a gapped row whose leading
        // nulls DO count under the shipped law.
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $rows[] = array_fill(0, Board::COLS, null);
        }
        $rows[23] = array_fill(0, Board::COLS, Tetromino::O);          // complete
        $rows[22][0] = null;
        $rows[22][1] = Tetromino::I;                                    // leading nulls, block, no gap? gap AFTER block at col 2
        $rows[22][2] = Tetromino::I;
        for ($c = 3; $c < Board::COLS; $c++) {
            $rows[22][$c] = null;
        }                                                          // gapped row: EVERY null counts (1+7)
        $rows[21][4] = Tetromino::T;                                   // block over the (22,4) hole
        $rows[20] = array_fill(0, Board::COLS, null);                  // empty above: no facts
        $board = new Board($rows);

        self::assertSame($this->referenceEvaluate($rows), $ai->evaluate($board));
        // Independent recomputation of the corner by hand: heights c0/c3/
        // c5..c9 first fill at the row-23 wall (1 each = 7), c1/c2 at row
        // 22 (2 each = 4), c4 at row 21 (3) → 14; holes: only c4's null at
        // row 22 sits below its first block → 1; gaps: row 22 (blocks 1-2,
        // nulls after → ALL 8 nulls) AND row 21 (block at 4, trailing
        // nulls at 5-9 → all 9 nulls of the row — the old law's bite is
        // exactly why the oracle exists) → 17; lines: the O wall → 1.
        // -4.5*14 - 7.5*1 - 3.5*17 + 9*1 = -121.0. This catches a
        // REFERENCE that drifted from intent, not just a rewrite that
        // drifted from the reference.
        self::assertSame(-121.0, $ai->evaluate($board));
    }

    // ------------------------------------------------------------------
    // bestMove: dedupe + tightened bound leave the decision untouched.
    // ------------------------------------------------------------------

    public function testTheDedupedSearchMakesExactlyTheOldDecision(): void
    {
        $ai = new Computer();
        foreach ($this->seededBoards(10, salt: 11) as $board) {
            foreach (Tetromino::cases() as $kind) {
                foreach ([0, 2] as $rotation) {
                    $piece = new Piece($kind, $rotation, 3, 0);
                    self::assertSame(
                        $this->referenceBestMove($board, $piece),
                        $ai->bestMove($board, $piece),
                        sprintf('bestMove diverged for %s rot%d', $kind->name, $rotation),
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Memo liveness.
    // ------------------------------------------------------------------

    public function testTheDefaultTableIsSharedButOverridesStaySeparate(): void
    {
        self::assertSame(Bindings::table(), Bindings::table(), 'the shipped table is one immutable instance');

        $override = Bindings::table()->withOverride('pause', 'k');
        self::assertNotSame(Bindings::table(), $override);
        // The override folded a NEW token; the shared default must be
        // byte-untouched by it (mutation-leak watch).
        self::assertContains('p', Bindings::table()->tokens('pause'));
        self::assertContains('k', $override->tokens('pause'));
    }

    public function testTheHelpCardMemoTurnsOverWithTheLocale(): void
    {
        $before = T::locale();
        try {
            T::setLocale('de');
            $german = Bindings::helpCard(Bindings::table());
            T::setLocale('en');
            $english = Bindings::helpCard(Bindings::table());

            self::assertNotSame($german, $english, 'a locale flip must never serve the other language from the memo');
            self::assertStringContainsString('rotate cw', $english);
            self::assertStringContainsString('drehen', $german, 'the german card carries german verbs, not english ones from the memo');
        } finally {
            T::setLocale($before);
        }
    }

    public function testTheHelpCardMemoTurnsOverWithTheTable(): void
    {
        $default = Bindings::helpCard(Bindings::table());
        $remapped = Bindings::helpCard(Bindings::table()->withOverride('move-left', 'j'));

        self::assertNotSame($default, $remapped);
        self::assertStringContainsString('j', $remapped);
        // The default instance's card is still the DEFAULT — the memo
        // entry the remap wrote cannot leak into it.
        self::assertSame($default, Bindings::helpCard(Bindings::table()));
    }

    public function testEveryCellStringMemoMatchesAFreshRender(): void
    {
        // Renderer::renderMini and the style doors are private; the
        // public frame IS their consumer — compare a frame built before
        // and after the caches are hot instead: a stale/contaminated memo
        // would move the second render away from the golden either way,
        // but the exact contract below pins it at the unit seam via
        // reflection on the two pure builders.
        $ref = new \ReflectionMethod(Renderer::class, 'renderMini');
        $ref->setAccessible(true);

        foreach (Tetromino::cases() as $kind) {
            // Both postures for the SAME kind must be distinct entries:
            // a memo key that forgets the posture leaks plain bytes into
            // the colour asks (or vice versa) — stability alone would
            // never notice. (Spotted exactly this way: the mutation
            // "$cacheKey = $kind->name" survived the first draft.)
            $plainOne = $ref->invoke(null, $kind, true);
            $colourOne = $ref->invoke(null, $kind, false);
            $plainTwo = $ref->invoke(null, $kind, true);
            $colourTwo = $ref->invoke(null, $kind, false);

            self::assertSame($plainOne, $plainTwo, $kind->name . ' plain preview must be stable');
            self::assertSame($colourOne, $colourTwo, $kind->name . ' colour preview must be stable');
            self::assertStringNotContainsString("\x1b", $plainOne, 'plain previews carry no SGR');
            self::assertStringContainsString("\x1b", $colourOne, $kind->name . ' colour preview paints SGR');
            self::assertNotSame($plainOne, $colourOne, 'the two postures are separate memo entries');
        }
    }

    public function testTheRotationTableMemoIsSharedAcrossCallers(): void
    {
        // cells() hands out the cached table; a caller must not be able
        // to poison it for the next one.
        $cells = Tetromino::T->cells(1);
        $cells[0][0] = 99; // mutate OUR copy of the outer list

        self::assertSame([[1, 0], [1, 1], [2, 1], [1, 2]], Tetromino::T->cells(1), 'the shipped table is served by value, never by reference alias');
    }

    public function testTheBoardIsBuiltByOneBufferWrapNow(): void
    {
        // The goldens pin the bytes; this pins the SHAPE the perf row
        // promised: the board interior arrives through fromGrid, and the
        // quadratic withCellAt chain is gone from that builder. (The
        // memoised renderMini may still walk its sixteen cells once per
        // kind — it runs fourteen times per process, not per frame.)
        $source = (string) file_get_contents(__DIR__ . '/../src/Renderer.php');
        $start = strpos($source, 'private static function renderBoard(');
        $end = strpos($source, '/**', (int) strpos($source, '$interior =', $start));
        self::assertIsInt($start);
        self::assertIsInt($end);
        $body = substr($source, $start, $end - $start);

        self::assertStringContainsString('Buffer::fromGrid(Board::COLS, Board::VISIBLE_ROWS, $grid)', $body);
        self::assertStringNotContainsString('withCellAt(', $body, 'the quadratic chain must not return to the board builder');
    }
}
