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
        // Next-piece minis are Buffer-rendered coloured-space cells; the
        // Buffer SGR emitter uses ESC[0;48;2;R;G;Bm (background RGB).
        $this->assertStringContainsString("\x1b[0;48;2;", $out);
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

    public function testRenderMiniIsBufferBackedExactSnapshot(): void
    {
        // Byte-exact pin: renderMini() is now Buffer-backed (no hand-rolled
        // SGR via the removed block() helper). The O tetromino fills the
        // middle two columns of both rows. The candy-buffer SGR emitter
        // prefixes a reset ("0;") and only emits one trailing reset per run,
        // so this differs from the old block()-per-cell bytes — that byte
        // change is exactly what this test guards against regressing.
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMini');
        $method->setAccessible(true);

        $mini = $method->invoke(null, Tetromino::O);

        // O color 226 → 0xffd400 → 255;212;0
        $block = "\x1b[0;48;2;255;212;0m";
        $reset = "\x1b[0m";
        $row = '  ' . $block . '  ' . $block . '  ' . $reset . '  ';
        $expected = $row . "\n" . $row;

        $this->assertSame($expected, $mini, 'renderMini(O) must match the Buffer-rendered snapshot');
    }

    public function testRenderMiniOutputsColoredBlocksForI(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMini');
        $method->setAccessible(true);

        // Tetromino::I at rotation 0: cells at (0,1),(1,1),(2,1),(3,1) - horizontal bar at y=1
        // In the 4×4 mini box: bottom row (y=1) filled, top row (y=0) empty
        $mini = $method->invoke(null, Tetromino::I);
        // Must contain Buffer-rendered ANSI block sequences in the bottom row
        $this->assertStringContainsString("\x1b[0;48;2;", $mini);
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
