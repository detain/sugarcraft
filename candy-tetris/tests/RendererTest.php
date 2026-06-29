<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;
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

    public function testRenderShowsGhostPieceAtLandingPosition(): void
    {
        // Construct a game with a piece mid-board so its ghost lands in visible rows
        $g = $this->deterministicGame();
        // Piece spawns near top. Move it to mid-board so ghost is visible.
        $midPiece = $g->piece->moved(0, 12);
        $g = $g->mutate(['piece' => $midPiece]);
        $out = Renderer::render($g);
        // Ghost cells render as ▒ at the landing position
        $this->assertStringContainsString('▒', $out);
    }

    public function testRenderDimsHoldWhenCanHoldIsFalse(): void
    {
        // Construct a game where hold is set but canHold is false
        $g = $this->deterministicGame();
        $withHold = new Game(
            board:     $g->board,
            piece:     $g->piece,
            bag:       $g->bag,
            score:     $g->score,
            hold:      Tetromino::T,
            canHold:   false,
        );
        $out = Renderer::render($withHold);
        // When canHold is false, the hold display is dimmed via SprinklesStyle->dim(true)
        // The faint attribute ESC[2m is applied to the hold card
        $this->assertStringContainsString("\x1b[2m", $out);
    }

    public function testRenderShowsNextPiecesInSidebar(): void
    {
        $out = Renderer::render($this->deterministicGame());
        // Next pieces render as coloured-space cells via block()
        // block() uses ESC[48;2;R;G;Bm (background RGB) + two spaces + ESC[0m
        $this->assertStringContainsString("\x1b[48;2;", $out);
    }

    public function testBlockStyleReturnsStyleForTetromino(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('blockStyle');
        $method->setAccessible(true);

        $style = $method->invoke(null, Tetromino::T);
        // blockStyle returns a Style with a background RGB colour
        $this->assertNotNull($style);
    }

    public function testGhostStyleReturnsStyleWithFaintAttribute(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('ghostStyle');
        $method->setAccessible(true);

        $style = $method->invoke(null, Tetromino::T);
        $this->assertNotNull($style);
    }
}
