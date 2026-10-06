<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;

/**
 * M1 (round LL): the transparency mapping in HalfBlockRenderer shipped
 * two defects that the old contains-glyph asserts let through:
 *  - both-transparent cells emitted a bare ▀ (a visible upper-half
 *    stripe of the default foreground) instead of a plain space;
 *  - top-transparent cells put the bottom colour in the BACKGROUND of
 *    a ▄ — but ▄'s LOWER half is the foreground-filled part, so the
 *    colour must ride fgRgb.
 * Every branch is now pinned as an exact byte sequence: SGR + glyph +
 * reset, nothing more, nothing less.
 *
 * Fixture halfblock_4branch.png is a 1×8 pixel band whose row-pairs
 * (top, bottom) are: (T,T), (T,green), (red,T), (blue,yellow) — one
 * cell per branch when rendered at width=1, height=4.
 */
final class HalfBlockTransparentTest extends TestCase
{
    private HalfBlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HalfBlockRenderer();
    }

    /** @return list<string> one line per cell row, in fixture order */
    private function renderBranchCells(): array
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/halfblock_4branch.png');
        $out = $this->renderer->render($image, 1, 4);

        return explode("\n", $out);
    }

    public function testBothTransparentEmitsPlainSpace(): void
    {
        $lines = $this->renderBranchCells();
        $this->assertCount(4, $lines);
        // No glyph, no SGR — a bare ▀ would stripe the default fg.
        $this->assertSame(' ', $lines[0]);
    }

    public function testTopTransparentPaintsBottomColourInLowerHalf(): void
    {
        $lines = $this->renderBranchCells();
        // ▄ (U+2584) is filled by its FOREGROUND in the lower half, so the
        // bottom colour rides fgRgb and the upper half shows terminal default.
        $this->assertSame(
            Ansi::fgRgb(0, 255, 0) . "\u{2584}" . Ansi::reset(),
            $lines[1],
        );
    }

    public function testBottomTransparentPaintsTopColourInUpperHalf(): void
    {
        $lines = $this->renderBranchCells();
        // ▀ (U+2580) fg-fills its upper half — this branch was already correct.
        $this->assertSame(
            Ansi::fgRgb(255, 0, 0) . "\u{2580}" . Ansi::reset(),
            $lines[2],
        );
    }

    public function testBothOpaqueRendersFgTopBgBottomUpperBlock(): void
    {
        $lines = $this->renderBranchCells();
        $this->assertSame(
            Ansi::fgRgb(0, 0, 255) . Ansi::bgRgb(255, 255, 0) . "\u{2580}" . Ansi::reset(),
            $lines[3],
        );
    }

    public function testOutputNeverContainsCarriageReturn(): void
    {
        // TUI uses \n only.
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/halfblock_4branch.png');
        $this->assertStringNotContainsString("\r", $this->renderer->render($image, 1, 4));
    }

    public function testFullyOpaqueRedImageHasNoTransparentBranchBytes(): void
    {
        // 8x4_red.png: every cell is both-opaque red/red — no ▄ and no space.
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/8x4_red.png');
        $out = $this->renderer->render($image, 8, 4);
        $cell = Ansi::fgRgb(255, 0, 0) . Ansi::bgRgb(255, 0, 0) . "\u{2580}" . Ansi::reset();

        $this->assertSame(implode("\n", array_fill(0, 4, str_repeat($cell, 8))), $out);
    }

    public function testSupportsAlphaReturnsFalse(): void
    {
        $this->assertFalse($this->renderer->supportsAlpha());
    }

    public function testNameReturnsHalfblock(): void
    {
        $this->assertSame('halfblock', $this->renderer->name());
    }
}
