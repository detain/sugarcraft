<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Testing\Snapshot\Assertions;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testStartingBoardRendersAnsi(): void
    {
        $game = Game::start(new Bag(static fn(int $max): int => 0));
        $output = Renderer::render($game);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/tetris-starting-board.golden',
            $output,
        );
    }

    public function testPauseOverlayRendersAnsi(): void
    {
        $bag = new Bag(static fn(int $max): int => 0);
        $game = Game::start($bag);
        $paused = new Game(
            board:  $game->board,
            piece:  $game->piece,
            bag:    $game->bag,
            score:  $game->score,
            over:   false,
            paused: true,
        );
        $output = Renderer::render($paused);

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('paused', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/tetris-pause-overlay.golden',
            $output,
        );
    }

    public function testTSpinBoardRendersAnsi(): void
    {
        $bag = new Bag(static fn(int $max): int => 0);
        $game = Game::start($bag);

        $rows = [];
        for ($y = 0; $y < \SugarCraft\Tetris\Board::ROWS; $y++) {
            $row = array_fill(0, \SugarCraft\Tetris\Board::COLS, null);
            $rows[$y] = $row;
        }

        // Guideline kick-out T-Spin slot (matches GameTest::tspinSlotGrid):
        // rows 14-16 leave the T's column open, row 17 walls both bottom
        // corners; the rot-1 T seated at (5,15) completes row 16.
        foreach ([14, 15, 16] as $y) {
            foreach ([0, 1, 2, 3, 4, 5, 8, 9] as $x) {
                $rows[$y][$x] = Tetromino::I;
            }
        }
        foreach ([0, 1, 2, 4, 5, 7, 8, 9] as $x) {
            $rows[17][$x] = Tetromino::I;
        }

        $board = new \SugarCraft\Tetris\Board($rows);

        $tspinGame = new Game(
            board:  $board,
            piece:  new \SugarCraft\Tetris\Piece(Tetromino::T, 1, 5, 15),
            bag:    $game->bag,
            score:  $game->score,
        );

        $output = Renderer::render($tspinGame);

        $this->assertNotEmpty($output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/tetris-tspin-board.golden',
            $output,
        );
    }

    public function testGameOverOverlayRendersAnsi(): void
    {
        $bag = new Bag(static fn(int $max): int => 0);
        $game = Game::start($bag);
        $over = new Game(
            board:  $game->board,
            piece:  $game->piece,
            bag:    $game->bag,
            score:  $game->score,
            over:   true,
        );
        $output = Renderer::render($over);

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('GAME OVER', $output);
        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/tetris-gameover-overlay.golden',
            $output,
        );
    }
}
