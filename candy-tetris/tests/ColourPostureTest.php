<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Renderer;
use SugarCraft\Tetris\Tetromino;
use SugarCraft\Tetris\VsGame;
use SugarCraft\Tetris\VsRenderer;

/**
 * MA-12 colour posture: an Ascii profile (NO_COLOR / CLICOLOR=0 / TERM
 * without colour) must yield a frame with ZERO SGR bytes where SHAPE
 * carries every fact — previews and VS blocks carry real glyphs, the
 * exhausted-hold state carries a word, and the empty hold slot carries a
 * visible rule instead of blank space.
 */
final class ColourPostureTest extends TestCase
{
    private function bag(): Bag
    {
        return new Bag(static fn(int $max): int => 0);
    }

    private function asciiGame(): Game
    {
        return Game::start($this->bag())->update(new ColorProfileMsg(ColorProfile::Ascii))[0];
    }

    public function testAsciiFrameCarriesZeroSgrBytes(): void
    {
        // A locked cell in a visible row exercises the board's plain guard
        // (the cellKind branch): if a style leaked through, this frame would
        // carry an escape and the test reddens.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[Board::ROWS - 1][0] = Tetromino::L;
        $game = $this->asciiGame()->mutate([
            'board' => new Board($rows),
            // A visible live piece also exercises the ghost branch (its drop
            // shadow lands on visible rows) under the same zero-SGR promise.
            'piece' => new Piece(Tetromino::T, 0, 3, 10),
        ]);
        $frame = Renderer::render($game);
        $this->assertStringContainsString('█', $frame, 'the locked cell must still render its block glyph');
        $this->assertSame(0, substr_count($frame, "\x1b["), 'the Ascii posture must emit no escape bytes at all');
    }

    public function testAsciiPreviewsCarryGlyphs(): void
    {
        // The colour posture previews with styled SPACES; colourless, those
        // render as blank nothing. The plain posture must show blocks.
        $frame = Renderer::render($this->asciiGame());
        $this->assertGreaterThan(0, substr_count($frame, '█'), 'next previews must be shape-visible without colour');
    }

    public function testEmptyHoldSlotIsVisibleAndStaysAtPreviewWidth(): void
    {
        $frame = Renderer::render($this->asciiGame());
        $this->assertStringContainsString('────────', $frame, 'the empty hold slot must be a visible rule, not blank space');
        $rows = explode("\n", $frame);
        // m35 anti-reflow keystone: an exhausted hold (cue + 8-wide preview)
        // must not change the frame's row count versus an empty slot.
        $withUsedHold = $this->asciiGame()->mutate(['hold' => Tetromino::T, 'canHold' => false]);
        $usedRows = explode("\n", Renderer::render($withUsedHold));
        $this->assertSame(count($rows), count($usedRows), 'the hold card must never resize the frame mid-play');
    }

    public function testExhaustedHoldCarriesTextCueInEveryPosture(): void
    {
        $plain = Renderer::render($this->asciiGame()->mutate(['hold' => Tetromino::T, 'canHold' => false]));
        $this->assertStringContainsString('hold: (used)', $plain, 'the exhausted state needs a word, not only style');
        $this->assertSame(0, substr_count($plain, "\x1b["), 'the cue rides the label row — the plain frame keeps its zero-SGR promise even here');

        $colored = Renderer::render(Game::start($this->bag())->mutate(['hold' => Tetromino::T, 'canHold' => false]));
        $this->assertStringContainsString('hold: (used)', $colored);
        $this->assertStringContainsString("\x1b[2m", $colored, 'the colour posture keeps its dim as well as the word');
    }

    public function testVsAsciiFrameCarriesZeroSgrBytes(): void
    {
        $vs = VsGame::start()->update(new ColorProfileMsg(ColorProfile::Ascii))[0];
        $frame = VsRenderer::render($vs);
        $this->assertSame(0, substr_count($frame, "\x1b["), 'the VS Ascii posture must emit no escape bytes at all');
    }

    public function testVsColourBlocksCarryGlyphs(): void
    {
        // MA-12: the VS locked/falling cells used to be two styled SPACES —
        // where the background SGR did not land, the board inverted (holes
        // looked like blocks, the falling piece vanished). They now carry
        // '█' on top of the colour. Place a visible piece to have blocks.
        $vs = VsGame::start();
        $player = $vs->player->mutate(['piece' => new Piece(Tetromino::T, 0, 3, 10)]);
        $mutate = new \ReflectionMethod(VsGame::class, 'mutate');
        $mutate->setAccessible(true);
        $vs = $mutate->invoke($vs, ['player' => $player]);

        $frame = VsRenderer::render($vs);
        $this->assertStringContainsString('██', $frame, 'a visible VS block must be shape-carried, not colour-only');
    }

    public function testNullProfileRendersTheFrozenColourPath(): void
    {
        // The byte-frozen snapshot pins run through the default (null =
        // not-yet-reported) posture; a bare Ascii decision must not leak
        // into it.
        $frame = Renderer::render(Game::start($this->bag()));
        $this->assertStringContainsString("\x1b[0;48;2;", $frame, 'unreported profile keeps the full-colour path');
    }
}
