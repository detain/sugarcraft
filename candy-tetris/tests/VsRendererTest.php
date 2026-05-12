<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Game;
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

        $this->assertStringContainsString('press q to quit', $output);
    }
}
