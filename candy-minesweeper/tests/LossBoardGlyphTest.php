<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Game;

/**
 * M21 (lib-reviews-1103): post-mortem legibility. On a LOST board the overlay
 * must grade the player's work — a flag on safe ground was WRONG and wears
 * '✗' (dim lavender), and a mine they missed lights up in mine-red instead of
 * sharing the hidden-dot grey. Mid-play nothing changes (flags are 'F',
 * hidden cells are dots — no spoilers), and a WON board keeps the classic
 * dim-mine overlay untouched.
 *
 * Boards are hand-built through the public Board constructor so the glyph
 * states are pinned exactly; assertions read the raw SGR from view().
 */
final class LossBoardGlyphTest extends TestCase
{
    private const RED_PLAIN     = "38;2;255;95;135m*";
    private const RED_BOLD      = "38;2;255;95;135;1m*";
    private const DIM_DOT       = "38;2;90;74;120m·";
    private const DIM_STAR      = "38;2;90;74;120m*";
    private const WRONG_FLAG    = "38;2;125;110;152m✗";
    private const GOLD_FLAG     = "38;2;253;230;138;1mF";

    /**
     * 3×3, two mines: (1,1) detonated (revealed), (2,0) missed (unflagged).
     * (0,0) carries a flag on a SAFE cell — the wrong flag. One correct flag
     * The single flag sits on (0,0), a safe cell — the wrong-flag case.
     * A correct gold F on a lost board is the mid-play pin below taken to
     * the exploded state; the mines are (1,1) (detonated) and (2,0)
     * (missed), so mineCount 2 is exact.
     *
     * @param bool $exploded  the board state to construct
     */
    private static function board(bool $exploded): Board
    {
        $mineAt = static fn (int $x, int $y): bool => ($x === 1 && $y === 1) || ($x === 2 && $y === 0);

        $rows = [];
        $revealedCount = 0;
        $flaggedCount  = 0;
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $mine = $mineAt($x, $y);
                // (0,0): player flag on a safe cell (wrong). (1,1): detonated mine.
        // (2,0): mine the player missed — hidden until the boom.
                $revealed = $mine && $x === 1 && $y === 1; // only the detonated mine is open
                $flagged  = $x === 0 && $y === 0;
                if ($revealed) {
                    $revealedCount++;
                }
                if ($flagged) {
                    $flaggedCount++;
                }
                $row[] = new Cell(mine: $mine, revealed: $revealed, flagged: $flagged);
            }
            $rows[] = $row;
        }

        return Board::fromRows(
            width: 3,
            height: 3,
            mineCount: 2,
            rows: $rows,
            minesPlaced: true,
            exploded: $exploded,
            revealedCount: $revealedCount,
            flaggedCount: $flaggedCount,
        );
    }

    public function testLostBoardGradesFlagsAndMissedMines(): void
    {
        $view = (new Game(board: self::board(exploded: true), cursorX: 2, cursorY: 2))->view();

        self::assertStringContainsString(self::WRONG_FLAG, $view, 'a flag on safe ground must be marked wrong post-mortem');
        self::assertStringNotContainsString(self::GOLD_FLAG, $view, 'no gold F survives as a wrong flag on a lost board');
        self::assertStringContainsString(self::RED_PLAIN, $view, 'a missed mine lights up in mine red');
        self::assertStringContainsString(self::RED_BOLD, $view, 'sanity: the detonated mine stays bold red');
        self::assertStringNotContainsString(self::DIM_STAR, $view, 'no missed mine may hide in the hidden-dot grey');
        self::assertStringContainsString(self::DIM_DOT, $view, 'sanity: safe hidden cells stay dim dots');
    }

    public function testMidPlayShowsNoPostMortemHints(): void
    {
        $view = (new Game(board: self::board(exploded: false), cursorX: 2, cursorY: 2))->view();

        self::assertStringNotContainsString('✗', $view, 'the wrong-flag mark belongs to lost boards only');
        self::assertStringContainsString(self::GOLD_FLAG, $view, 'mid-play every flag reads as a plain gold F');
        self::assertStringNotContainsString(self::RED_PLAIN, $view, 'unflagged mines must not glow before the boom');
        self::assertStringContainsString(self::DIM_DOT, $view, 'sanity: hidden cells are dots');
    }

    public function testWonBoardKeepsTheClassicDimMineOverlay(): void
    {
        // A genuinely won 3×3/2: every safe cell revealed, mines still hidden.
        // The win overlay must keep the shipped look — missed mines render as
        // dim '*' via the isWon branch, and the lost-board grade (✗/bright
        // red) never leaks onto wins. (The mid-play fixture in board() below
        // is NOT a win: only 6 of 7 safe cells are open.)
        $rows = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $mine = ($x === 1 && $y === 1) || ($x === 2 && $y === 0);
                $row[] = new Cell(mine: $mine, revealed: !$mine, flagged: false);
            }
            $rows[] = $row;
        }
        $wonBoard = Board::fromRows(3, 3, 2, $rows, true, false, 7, 0);
        self::assertTrue((new Game(board: $wonBoard))->board->isWon(), 'sanity: seven of seven safe cells revealed');

        $wonView = (new Game(board: $wonBoard, cursorX: 2, cursorY: 2))->view();
        self::assertStringContainsString(self::DIM_STAR, $wonView, 'the win overlay keeps missed mines dim, as shipped');
        self::assertStringNotContainsString(self::RED_PLAIN, $wonView, 'the lost-board grade must not leak onto wins');
        self::assertStringNotContainsString('✗', $wonView, 'no wrong-flag marks on a won board');
    }
}
