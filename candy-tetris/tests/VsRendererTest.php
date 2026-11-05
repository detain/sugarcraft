<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;
use PHPUnit\Framework\TestCase;

final class VsRendererTest extends TestCase
{
    public function testRenderReturnsNonEmptyString(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    public function testRenderContainsBothPlayerAndComputerLabels(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('PLAYER', $output);
        $this->assertStringContainsString('COMPUTER', $output);
    }

    public function testRenderContainsVSIndicator(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('VS', $output);
    }

    public function testRenderContainsScore(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('score:', $output);
        $this->assertStringContainsString('lines:', $output);
        $this->assertStringContainsString('level:', $output);
    }

    public function testRenderOverStateShowsWinner(): void
    {
        // Create a VS game in over state
        $playerGame = Game::start();
        $computerGame = Game::start();

        // Manually set computer game to over
        $overComputer = new Game(
            $computerGame->board,
            $computerGame->piece,
            $computerGame->bag,
            $computerGame->score,
            over: true,
        );

        $vs = new VsGame($playerGame, $overComputer, over: true, winner: 'PLAYER');
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('GAME OVER', $output);
        $this->assertStringContainsString('YOU WIN!', $output);
    }

    public function testRenderComputerOverShowsComputerWinner(): void
    {
        // Create a VS game in over state with computer winner
        $playerGame = Game::start();
        $computerGame = Game::start();

        // Manually set player game to over
        $overPlayer = new Game(
            $playerGame->board,
            $playerGame->piece,
            $playerGame->bag,
            $playerGame->score,
            over: true,
        );

        $vs = new VsGame($overPlayer, $computerGame, over: true, winner: 'COMPUTER');
        $output = VsRenderer::render($vs);

        $this->assertStringContainsString('GAME OVER', $output);
        $this->assertStringContainsString('COMPUTER WINS!', $output);
    }

    public function testRenderContainsANSIEscapeCodes(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        // Check for ANSI escape codes (board rendering)
        $this->assertStringContainsString("\x1b[", $output);
    }

    public function testRenderDoesNotContainQuitPromptWhenNotOver(): void
    {
        $vs = VsGame::start();
        $output = VsRenderer::render($vs);

        // Should not show quit prompt in normal play
        $this->assertStringNotContainsString('press q to quit', $output);
    }

    public function testRenderContainsQuitPromptWhenOver(): void
    {
        $playerGame = Game::start();
        $computerGame = Game::start();

        $overComputer = new Game(
            $computerGame->board,
            $computerGame->piece,
            $computerGame->bag,
            $computerGame->score,
            over: true,
        );

        $vs = new VsGame($playerGame, $overComputer, over: true, winner: 'PLAYER');
        $output = VsRenderer::render($vs);

        // The over banner must teach r alongside q — the versus over
        // screen answers restart (VsGame::update); a banner stuck on
        // quit_hint alone hid a live key.
        $this->assertStringContainsString('press r to restart', $output);
    }

    public function testRenderComputerBoardWithGhostPiece(): void
    {
        // Build a VS game where computer has placed a piece, so ghost is visible
        $computerGame = Game::start();

        // Hard drop computer's piece to place it on the board
        $computerWithPlacedPiece = $computerGame->mutate(['piece' => new \SugarCraft\Tetris\Piece(
            $computerGame->piece->kind,
            $computerGame->piece->rotation,
            $computerGame->piece->x,
            Board::ROWS - 5 // Near bottom so ghost is visible
        )]);

        $vs = new VsGame(Game::start(), $computerWithPlacedPiece);
        $output = VsRenderer::render($vs);

        // Should contain ANSI codes for rendering
        $this->assertStringContainsString("\x1b[", $output);
    }

    public function testRenderComputerPiecesUseDifferentHue(): void
    {
        // MA-14: the two palettes must be disjoint by value. The old
        // (color + 150) % 256 shift put computer S exactly on player Z's
        // red, so label-presence assertions proved nothing — pin the map.
        $playerPalette = [];
        foreach (Tetromino::cases() as $kind) {
            $playerPalette[] = $kind->color();
        }
        $computerPalette = array_values(VsRenderer::COMPUTER_COLORS);

        self::assertCount(7, $computerPalette, 'every kind needs a computer colour');
        self::assertSame($computerPalette, array_values(array_unique($computerPalette)), 'computer colours must not repeat each other');
        self::assertSame([], array_values(array_intersect($playerPalette, $computerPalette)), 'no computer colour may collide with the player palette');

        $vs = new VsGame(Game::start(), Game::start());
        $output = VsRenderer::render($vs);
        $this->assertStringContainsString('PLAYER', $output);
        $this->assertStringContainsString('COMPUTER', $output);
    }

    public function testRenderComputerBoardWithPlacedPieces(): void
    {
        // Build a VS game where computer has placed pieces on the board
        $computerGame = Game::start();

        // Create a board with some pieces already placed for computer
        $rows = $computerGame->board->rows();
        $rows[20][3] = Tetromino::T;
        $rows[20][4] = Tetromino::T;
        $rows[21][3] = Tetromino::T;
        $rows[21][4] = Tetromino::T;
        $computerBoard = new Board($rows);

        // Keep the piece at a position where it and its ghost will render
        $computerWithPlacedPieces = new Game(
            $computerBoard,
            $computerGame->piece,
            $computerGame->bag,
            $computerGame->score,
        );

        $vs = new VsGame(Game::start(), $computerWithPlacedPieces);
        $output = VsRenderer::render($vs);

        // Should render ANSI codes for the computer board with pieces
        $this->assertStringContainsString("\x1b[", $output);
        $this->assertStringContainsString('COMPUTER', $output);
    }
}
