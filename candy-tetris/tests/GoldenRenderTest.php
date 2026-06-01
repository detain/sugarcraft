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

        $rows[15][4] = Tetromino::I;
        $rows[15][8] = Tetromino::I;
        $rows[18][4] = Tetromino::I;
        $rows[18][8] = Tetromino::I;

        $board = new \SugarCraft\Tetris\Board($rows);

        $tspinGame = new Game(
            board:  $board,
            piece:  $game->piece,
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
