<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Minesweeper\Board;
use SugarCraft\Minesweeper\Cell;
use SugarCraft\Minesweeper\Difficulty;
use SugarCraft\Minesweeper\Game;
use SugarCraft\Minesweeper\Renderer;
use SugarCraft\Minesweeper\Stats;
use SugarCraft\Core\Util\Width;

/**
 * The frame's footer promised "each line stays under
 * 80 cells" while the French help lines measured 78-79 BEFORE the two-cell
 * indent — a claim no test checked. This guard renders the frame in every
 * shipped locale and fails loudly on any line that would wrap on an 80-column
 * terminal.
 *
 * Status lines are locale-rendered too: the guard pins fresh, exploded and
 * won-with-a-three-digit-time boards, so banners and the timer format ride
 * the same budget. The notice toast is excluded by construction (the games
 * here carry none — the toast strings are short save/load announcements).
 */
final class FooterWidthGuardTest extends TestCase
{
    private const MAX_LINE = 80;

    /** @return list<array{0:string,1:Game}> */
    private function fixtures(): array
    {
        $fresh = Game::withDifficulty(Difficulty::STANDARD, static fn(int $max): int => 0);

        // Exploded 3x3 with a detonated mine: hand-built through the public
        // Board ctor (LossBoardGlyphTest idiom) so no drive randomness is
        // involved. Cursor parked off any flagged cell.
        $mines = [[false, false, false], [false, true, false], [true, false, false]];
        $rows = [];
        $adj = [[1, 2, 1], [2, 0, 3], [1, 3, 1]];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = new Cell(
                    mine: $mines[$y][$x],
                    revealed: $y === 1 && $x === 1,
                    flagged: false,
                    adjacent: $mines[$y][$x] ? 0 : $adj[$y][$x],
                );
            }
            $rows[] = $row;
        }
        $exploded = new Game(
            board: Board::fromRows(3, 3, 2, $rows, minesPlaced: true, exploded: true, revealedCount: 1, flaggedCount: 0),
            cursorX: 0,
            cursorY: 0,
        );

        // Won board carrying a >1h frozen time — the longest timer suffix.
        $winRows = [];
        for ($y = 0; $y < 2; $y++) {
            $row = [];
            for ($x = 0; $x < 2; $x++) {
                $row[] = new Cell(mine: false, revealed: true, flagged: false, adjacent: 0);
            }
            $winRows[] = $row;
        }
        // 2x2 with a mine cannot be won (revealedCount can never reach w*h-m
        // with the mine unopened), so use 3x3/8 safe cells all revealed.
        $safe = [];
        for ($y = 0; $y < 3; $y++) {
            $row = [];
            for ($x = 0; $x < 3; $x++) {
                $row[] = $y === 0 && $x === 0
                    ? new Cell(mine: true)
                    : new Cell(mine: false, revealed: true, flagged: false, adjacent: $x === 1 || $y === 1 ? 1 : 0);
            }
            $safe[] = $row;
        }
        $safeBoard = Board::fromRows(3, 3, 1, $safe, minesPlaced: true, exploded: false, revealedCount: 8, flaggedCount: 0);
        $won = new Game(
            board: $safeBoard,
            cursorX: 2,
            cursorY: 2,
            elapsedSeconds: 3625,
        );
        unset($winRows);

        return [['fresh', $fresh], ['exploded', $exploded], ['won+3digit', $won],
            // EXPERT (30×16) is the widest shipped preset board — 16+8 = 24
            // rows, the exact boot-fit floor, and the longest footer budget.
            ['expert', Game::withDifficulty(Difficulty::EXPERT)],
            // msw-08: the stats variant of the status line (preset name +
            // best-time segment with the widest legal number) and the
            // banner that quotes a beaten record — both ride the same
            // budget as the plain strings, in every locale.
            ['stats-status', Game::withDifficulty(
                Difficulty::EXPERT,
                static fn(int $max): int => 0,
                stats: new Stats(expertGames: 1, expertBest: 3599),
            )],
            ['stats-beat-banner', new Game(
                board: $safeBoard,
                cursorX: 2,
                cursorY: 2,
                elapsedSeconds: 3625,
                stats: new Stats(easyGames: 2, easyWins: 1, easyBest: 3600),
                newBest: true,
                beatenRecord: 3600,
            )]];
    }

    public function testEveryFrameLineFitsEightyColumnsInEveryLocale(): void
    {
        $locales = [];
        foreach (glob(__DIR__ . '/../lang/*.php') as $file) {
            $locales[] = basename($file, '.php');
        }
        $this->assertNotEmpty($locales, 'sanity: locales ship in lang/');

        $previous = T::locale();
        try {
            foreach ($locales as $locale) {
                T::setLocale($locale);
                foreach ($this->fixtures() as [$name, $game]) {
                    foreach (explode("\n", Renderer::render($game)) as $line) {
                        $clean = rtrim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, ' ');
                        $width = Width::of($clean);
                        $this->assertLessThanOrEqual(
                            self::MAX_LINE,
                            $width,
                            "locale {$locale} fixture {$name}: line wraps at {$width} cells: {$clean}",
                        );
                    }
                }
            }
        } finally {
            T::setLocale($previous);
        }
    }

    /**
     * Terminal fit (candy-minesweeper-001): on a narrow terminal the footer
     * word-wraps inside the live columns instead of reflowing the screen —
     * every rendered line of every locale must respect a 40-column budget.
     */
    public function testEveryFrameLineFitsFortyColumnsInEveryLocale(): void
    {
        $fixtures = [
            ['fresh', Game::withDifficulty(Difficulty::EASY)],
            ['expert', Game::withDifficulty(Difficulty::EXPERT)],
            ['toasted', Game::withDifficulty(Difficulty::STANDARD, notice: 'saved')],
            // msw-08 stats status must word-wrap inside the live columns too.
            ['stats', Game::withDifficulty(
                Difficulty::EXPERT,
                static fn(int $max): int => 0,
                stats: new Stats(expertGames: 1, expertBest: 3599),
            )],
            // msw-16: the post-mortem legend swap must ride this budget too.
            ['exploded', (static function (): Game {
                $mines = [[false, false, false], [false, true, false], [true, false, false]];
                $rows = [];
                for ($y = 0; $y < 3; $y++) {
                    $row = [];
                    for ($x = 0; $x < 3; $x++) {
                        $row[] = new Cell(mine: $mines[$y][$x], revealed: $y === 1 && $x === 1);
                    }
                    $rows[] = $row;
                }
                return new Game(
                    board: Board::fromRows(3, 3, 2, $rows, minesPlaced: true, exploded: true, revealedCount: 1, flaggedCount: 0),
                    cursorX: 0,
                    cursorY: 0,
                );
            })()],
        ];
        $locales = array_map('basename', array_combine($glob = glob(__DIR__ . '/../lang/*.php') ?: [], $glob));
        $this->assertNotEmpty($locales);

        $previous = T::locale();
        try {
            foreach ($locales as $locale) {
                T::setLocale($locale);
                foreach ($fixtures as [$name, $game]) {
                    $narrow = $game->update(new WindowSizeMsg(40, 40))[0];
                    foreach (explode("\n", Renderer::render($narrow)) as $line) {
                        $clean = rtrim(preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line, ' ');
                        $width = Width::of($clean);
                        $this->assertLessThanOrEqual(
                            40,
                            $width,
                            "locale {$locale} narrow fixture {$name}: line overflows at {$width} cells: {$clean}",
                        );
                    }
                }
            }
        } finally {
            T::setLocale($previous);
        }
    }
}
