<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\HalfBlockRenderer;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Tests\Support\HalfBlockStream;

/**
 * Finding #9 — the HalfBlock parity contract, stated precisely.
 *
 * `Player::view()` renders HalfBlock through the inline `frameToBuffer()` Buffer
 * path; `Render\HalfBlockRenderer` (the candy-mosaic delegation) is never reached
 * by the runtime. The duplication the finding flags is therefore a *guard*, not a
 * hot path, and what has to hold is that the guard means something.
 *
 * The original parity assertion stripped every SGR sequence and compared the count
 * of ▀ glyphs — something any two grid-sized outputs satisfy trivially, and which
 * stayed green while the two paths silently disagreed on colour. This suite
 * compares the SEMANTIC cell stream instead: the ordered (glyph, foreground,
 * background) triples decoded from each path's escapes (see
 * {@see HalfBlockStream}). The SGR *encoding* legitimately differs — the Buffer
 * path emits one combined `0;38;2;…;48;2;…m` run per cell, mosaic a
 * reset-terminated fg/bg pair — so encoding is deliberately out of scope and
 * colour is entirely in scope.
 *
 * `testOddHeightFramesDivergeByDesign` pins the one place the two do NOT agree: a
 * frame whose pixel height is odd. The inline path pairs rows verbatim and pads the
 * orphan lower half with black; mosaic resamples to an even grid first, so every
 * row after the first is interpolated. No conforming decoder can emit that shape —
 * HalfBlock decodes at `cellsH × rowsPerCell()`, always even — so the divergence is
 * unreachable rather than wrong, and pinning it makes any future reconciliation (or
 * deletion of one path) a conscious decision instead of a silent output change.
 *
 * @covers \SugarCraft\Reel\Render\HalfBlockRenderer
 */
final class HalfBlockParityTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function reachableGeometries(): array
    {
        // Every width with an EVEN height — the only shape a HalfBlock decoder can
        // emit (cellsH rows × rowsPerCell() source pixels each).
        return [
            '1 cell wide, 1 cell tall' => [1, 2],
            '4x2' => [4, 2],
            '8x4' => [8, 4],
            '13x6' => [13, 6],
            '16x16' => [16, 16],
            'wide and short' => [40, 2],
            'tall' => [2, 24],
            'non-multiple of 2 columns' => [13, 8],
        ];
    }

    #[DataProvider('reachableGeometries')]
    public function testInlineAndMosaicAgreeOnEveryCellForEvenHeightFrames(int $w, int $h): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd required for the mosaic HalfBlock path');
        }

        $frame = HalfBlockStream::gradient($w, $h);

        $inline = HalfBlockStream::inlineCells($frame, $w, intdiv($h, 2));
        $mosaic = HalfBlockStream::mosaicCells($frame);

        self::assertNotEmpty($inline, 'the inline path must emit at least one cell');
        self::assertCount($w * intdiv($h, 2), $inline, 'one cell per grid position');
        self::assertSame(
            $mosaic,
            $inline,
            sprintf('inline and mosaic HalfBlock paths must agree cell-by-cell for a %dx%d frame', $w, $h),
        );
    }

    public function testTwoToneFrameCarriesUpperForegroundAndLowerBackground(): void
    {
        // Row 0 red, row 1 green: the definition of a half block.
        $bytes = str_repeat("\xff\x00\x00", 4) . str_repeat("\x00\x80\x00", 4);
        $frame = new RgbFrame($bytes, 4, 2);

        $inline = HalfBlockStream::inlineCells($frame, 4, 1);
        self::assertCount(4, $inline);
        foreach ($inline as $i => $cell) {
            self::assertSame('255,0,0', $cell['fg'], "cell {$i} foreground must be the upper pixel");
            self::assertSame('0,128,0', $cell['bg'], "cell {$i} background must be the lower pixel");
        }

        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd required for the mosaic HalfBlock path');
        }
        self::assertSame($inline, HalfBlockStream::mosaicCells($frame), 'the mosaic guard must agree on the canonical case');
    }

    public function testEveryCellIsAGlyphWithBothChannelsPopulated(): void
    {
        // A degenerate fg/bg-less cell would slip past a count-only comparison.
        $cells = HalfBlockStream::inlineCells(HalfBlockStream::gradient(6, 4), 6, 2);

        foreach ($cells as $i => $cell) {
            self::assertSame("\u{2580}", $cell['glyph'], "cell {$i} must be the upper-half block");
            self::assertNotNull($cell['fg'], "cell {$i} must carry the upper pixel colour");
            self::assertNotNull($cell['bg'], "cell {$i} must carry the lower pixel colour");
        }
    }

    /**
     * Pin of the known, unreachable divergence (see class docblock).
     */
    public function testOddHeightFramesDivergeByDesign(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd required for the mosaic HalfBlock path');
        }

        $frame = HalfBlockStream::gradient(5, 5);

        $inline = HalfBlockStream::inlineCells($frame, 5, 3);
        $mosaic = HalfBlockStream::mosaicCells($frame);

        self::assertCount(15, $inline);
        self::assertCount(15, $mosaic);

        // Same glyph grid — which is all the old count-only test could ever notice.
        self::assertSame(array_column($inline, 'glyph'), array_column($mosaic, 'glyph'));

        // Mosaic renders cellHeight×2 = 6 source rows from a 5-row image, so every
        // row after the first lands between pixels and both channels move.
        self::assertNotSame(array_column($inline, 'fg'), array_column($mosaic, 'fg'), 'odd height resamples every row');
        self::assertNotSame(array_column($inline, 'bg'), array_column($mosaic, 'bg'));

        // The inline path's signature is the black pad on the orphan row; mosaic
        // has no such cell because it never runs out of source rows.
        self::assertSame('0,0,0', $inline[10]['bg'], 'the inline path pads the orphan lower row with black');
        self::assertNotSame('0,0,0', $inline[0]['bg']);
        self::assertNotSame('0,0,0', $mosaic[10]['bg'], 'mosaic interpolates that row instead of padding it');
    }

    public function testEmptyFrameRendersNothingOnBothPaths(): void
    {
        $frame = new RgbFrame('', 0, 0);

        self::assertSame('', (new HalfBlockRenderer())->render($frame, Mode::HalfBlock), 'a 0x0 frame must short-circuit');
        self::assertSame([], HalfBlockStream::mosaicCells($frame));
        self::assertSame([], HalfBlockStream::cells("\x1b[0mno glyphs here\x1b[0m"));
    }
}
