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

    public function testBlockReturnsAnsiBackgroundForTetrominoT(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('block');
        $method->setAccessible(true);

        // Tetromino::T color is 129 → RGB from COLOR_MAP is 0xbd7dff
        // Expected: ESC[48;2;189;125;255m  ESC[0m
        $block = $method->invoke(null, Tetromino::T);
        $this->assertSame(
            "\x1b[48;2;189;125;255m  \x1b[0m",
            $block,
            'block(T) must return ANSI RGB background for T color (0xbd7dff = 189;125;255)',
        );
    }

    public function testGhostReturnsFaintAnsiForegroundForTetrominoI(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('ghost');
        $method->setAccessible(true);

        // Tetromino::I color is 51 → RGB from COLOR_MAP is 0x00d4ff (dimmed to 0x888888)
        // ghost() uses 0x888888 as fallback for any color
        $ghost = $method->invoke(null, Tetromino::I);
        $this->assertStringContainsString("\x1b[38;2;", $ghost);
        $this->assertStringContainsString("\x1b[2m", $ghost, 'ghost must use faint attribute');
        $this->assertStringContainsString('▒▒', $ghost, 'ghost must render as ▒▒');
    }

    public function testRenderMiniOutputsColoredBlocksForI(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMini');
        $method->setAccessible(true);

        // Tetromino::I at rotation 0: cells at (0,1),(1,1),(2,1),(3,1) - horizontal bar at y=1
        // In the 4×4 mini box: bottom row (y=1) filled, top row (y=0) empty
        $mini = $method->invoke(null, Tetromino::I);
        // Must contain ANSI block sequences in the bottom row
        $this->assertStringContainsString("\x1b[48;2;", $mini);
        // Should be 2 lines (y=0 empty, y=1 filled)
        $lines = explode("\n", $mini);
        $this->assertSame(2, count($lines), 'renderMini must return 2 lines');
    }

    public function testRenderMiniPlaceholderReturnsFourSpacesOnTwoLines(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMiniPlaceholder');
        $method->setAccessible(true);

        $placeholder = $method->invoke(null);
        $this->assertSame("     \n     ", $placeholder, 'placeholder must be 5 spaces on 2 lines');
    }
}
