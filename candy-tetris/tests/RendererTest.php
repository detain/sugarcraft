<?php

declare(strict_types=1);

namespace CandyCore\Tetris\Tests;

use CandyCore\Tetris\Bag;
use CandyCore\Tetris\Game;
use CandyCore\Tetris\Renderer;
use CandyCore\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function deterministicGame(): Game
    {
        // Bag with deterministic sequence: cycle through I, O, T...
        $bag = new Bag(static fn(int $max): int => 0);
        return Game::start($bag);
    }

    public function testRenderProducesNonEmptyFrame(): void
    {
        $out = Renderer::render($this->deterministicGame());
        $this->assertNotSame('', $out);
    }

    public function testRenderShowsScoreAndLevelLabels(): void
    {
        $out = Renderer::render($this->deterministicGame());
        $this->assertStringContainsString('score:', $out);
        $this->assertStringContainsString('lines:', $out);
        $this->assertStringContainsString('level:', $out);
    }

    public function testRenderShowsHelpTextAndNextLabel(): void
    {
        $out = Renderer::render($this->deterministicGame());
        $this->assertStringContainsString('next:', $out);
        $this->assertStringContainsString('move', $out);
        $this->assertStringContainsString('hard drop', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testRenderShowsPauseBanner(): void
    {
        $g = $this->deterministicGame();
        $paused = new Game(
            board:  $g->board,
            piece:  $g->piece,
            bag:    $g->bag,
            score:  $g->score,
            over:   false,
            paused: true,
        );
        $out = Renderer::render($paused);
        $this->assertStringContainsString('paused', $out);
    }

    public function testRenderShowsGameOverBanner(): void
    {
        $g = $this->deterministicGame();
        $over = new Game(
            board:  $g->board,
            piece:  $g->piece,
            bag:    $g->bag,
            score:  $g->score,
            over:   true,
        );
        $out = Renderer::render($over);
        $this->assertStringContainsString('GAME OVER', $out);
        $this->assertStringContainsString('final score', $out);
    }
}
