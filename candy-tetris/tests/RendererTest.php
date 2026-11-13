<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Support\Bindings;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function deterministicGame(): Game
    {
        // Bag with deterministic sequence: cycle through I, O, T...
        $bag = new Bag(static fn(int $_max): int => 0);
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

    /**
     * candy-tetris-005: the banners teach the LIVE table. A player who
     * remapped pause never sees a dead 'p' promise; the default table
     * still spells the shipped bytes (goldens), and the two-token quit
     * reads in the intro's keycap grammar, not as one impossible chord.
     */
    public function testThePauseBannerNamesTheLivePauseBinding(): void
    {
        $remapped = $this->deterministicGame()
            ->mutate(['bindings' => Bindings::table()->withOverride('pause', 'x')]);
        $out = Renderer::render($remapped->mutate(['paused' => true]));

        $this->assertStringContainsString('press x to resume', $out);
        $this->assertStringNotContainsString('press p to resume', $out);
    }

    public function testTheOverBannerNamesTheLiveRestartAndQuitBindings(): void
    {
        $remapped = $this->deterministicGame()->mutate(['bindings' => Bindings::table()
            ->withOverride('restart', 't')
            ->withOverride('quit', 'z'),
        ]);
        $out = Renderer::render($remapped->mutate(['over' => true, 'overReason' => Game::OVER_CRUSHED]));

        $this->assertStringContainsString('press t to restart', $out);
        $this->assertStringContainsString('press z to quit', $out);
        $this->assertStringNotContainsString('press r to restart', $out);
        $this->assertStringNotContainsString('press q to quit', $out);
    }

    public function testDefaultBannersSpellTheShippedBytesWithTheLiveQuitChordNamed(): void
    {
        $out = Renderer::render($this->deterministicGame()->mutate([
            'over' => true,
            'overReason' => Game::OVER_CRUSHED,
        ]));

        // 'quit' binds two live tokens (candy-tetris-034 added the
        // second); the hint spells both in the intro's keycap grammar and
        // folds onto whole lines so the wider truth is never clipped.
        $this->assertStringContainsString('press r to restart', $out);
        $this->assertStringContainsString('press q or ctrl+c to quit', $out);
        $this->assertContains(
            'press q or ctrl+c to quit',
            array_map(static fn(string $line): string => trim($line), explode("\n", $out)),
            'the quit clause lands whole on its own line — never clipped at the frame edge',
        );
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
        // When canHold is false, the hold display is dimmed via SugarStyle->dim(true)
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

    /**
     * -044 cure: the old oracle was `assertNotNull` on a `: Style`-typed
     * factory that cannot return null — it certified nothing. The styles now
     * get their real state: blockStyle carries the kind's RGB as BACKGROUND,
     * ghostStyle carries it as FOREGROUND with the faint attribute (the
     * landing-preview posture at Renderer.php:275-281).
     */
    public function testBlockStyleCarriesTheKindColourAsBackground(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('blockStyle');
        $method->setAccessible(true);

        $style = $method->invoke(null, Tetromino::T);

        $this->assertSame(Tetromino::T->rgbColor(), $style->bg());
        $this->assertNull($style->fg());
        $this->assertFalse($style->hasFaint());
    }

    public function testGhostStyleCarriesTheKindColourFaintInForeground(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('ghostStyle');
        $method->setAccessible(true);

        $style = $method->invoke(null, Tetromino::T);

        $this->assertSame(Tetromino::T->rgbColor(), $style->fg());
        $this->assertNull($style->bg());
        $this->assertTrue($style->hasFaint());
    }

    public function testRenderMiniIsBufferBackedExactSnapshot(): void
    {
        // Byte-exact pin: renderMini() is now Buffer-backed (no hand-rolled
        // SGR via the removed block() helper). The O tetromino fills the
        // middle two columns of both rows. The sugar-cellbuffer SGR emitter
        // prefixes a reset ("0;") and Buffer::toAnsi() is canonical: equal
        // adjacent styles share ONE SGR open and one trailing reset per
        // style run — so the two 2-space blocks merge into a single 4-space
        // styled run. That byte shape is exactly what this test guards.
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMini');
        $method->setAccessible(true);

        $mini = $method->invoke(null, Tetromino::O);

        // O color 226 → 0xffd400 → 255;212;0
        $block = "\x1b[0;48;2;255;212;0m";
        $reset = "\x1b[0m";
        $row = '  ' . $block . '    ' . $reset . '  ';
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

    public function testRenderMiniPlaceholderCarriesThePreviewWidth(): void
    {
        $reflector = new \ReflectionClass(Renderer::class);
        $method = $reflector->getMethod('renderMiniPlaceholder');
        $method->setAccessible(true);

        $placeholder = $method->invoke(null);
        // m35: same 8-cell width (and 2-row height) an occupied preview
        // carries, so the hold card never changes shape on the first hold.
        $this->assertSame("────────\n────────", $placeholder, 'placeholder must be 8 rule cells on 2 lines');
        $lines = explode("\n", $placeholder);
        foreach ($lines as $line) {
            $this->assertSame(8, Width::of($line));
        }
    }

    public function testEveryVisibleCellRendersExactlyOneRune(): void
    {
        $out = Renderer::render($this->deterministicGame());
        // The playfield loop visits every visible cell once and writes one
        // of the three runes: locked/live block, ghost, or empty dot.
        $runes = substr_count($out, '█') + substr_count($out, '▒') + substr_count($out, '·');
        $this->assertSame(
            Board::COLS * Board::VISIBLE_ROWS,
            $runes,
            'the visible playfield must be fully covered by block/ghost/dot runes',
        );
    }

    public function testRenderMarksEmptyCellsAsDots(): void
    {
        $game = $this->deterministicGame();
        $out = Renderer::render($game);

        // Covered cells = live piece ∪ its drop-position ghost; the
        // renderer dedupes by key, so the union is exactly what is NOT a dot.
        $covered = [];
        foreach ($game->piece->cells() as [$x, $y]) {
            $covered["$x,$y"] = true;
        }
        foreach ($game->board->dropPiece($game->piece)->cells() as [$x, $y]) {
            $covered["$x,$y"] = true;
        }
        $visibleCovered = 0;
        foreach (array_keys($covered) as $key) {
            [$x, $y] = array_map('intval', explode(',', $key));
            if ($x >= 0 && $x < Board::COLS
                && $y >= Board::HIDDEN_ROWS
                && $y < Board::HIDDEN_ROWS + Board::VISIBLE_ROWS
            ) {
                $visibleCovered++;
            }
        }

        $this->assertSame(
            Board::COLS * Board::VISIBLE_ROWS - $visibleCovered,
            substr_count($out, '·'),
            'every uncovered visible cell renders the · empty-cell rune',
        );
    }
}
