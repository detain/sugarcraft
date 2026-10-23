<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Symbol\CanvasMode;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolSetKana;

/**
 * The per-cell error-0 veto in SymbolMapRenderer::applyWidePass() is
 * LOAD-BEARING (rev-w11-2 M-1) — the build report's "defense-in-depth"
 * claim was false and is corrected by this pin.
 *
 * Under CanvasMode::Fgbg the wide trial skips the opaque-halves check
 * (pickWide takes the terminal default pair straight away) and halfError
 * skips alpha-null pixels, so a pair whose left cell is fully transparent
 * (blank, narrow error 0) CAN commit a wide kana lead painting foreground
 * across the blank cell — exactly the recorded C divergence in the A-05a
 * deviation note. The reviewer's 480-strip sweep flipped 4/480 renders
 * with the guard removed (all kana-map + Fgbg; minimal shape = probe case
 * 86); this hand-built fixture reproduces the same flip deterministically:
 * guard out, the "blank + narrow block" pair collapses to the single wide
 * lead U+30CE over the transparent cell.
 */
final class WideErrorGuardPinTest extends TestCase
{
    public function testTheErrorZeroVetoStopsAFgbgWideLeadCommittingOverATransparentHalf(): void
    {
        // 2-cell strip, 8 px per cell: cell 0 fully transparent, cell 1 the
        // right half of U+3042 (あ) over black — the case-86 shape by hand.
        $img = imagecreatetruecolor(16, 8);
        imagealphablending($img, false);
        imagesavealpha($img, true); // without this the PNG encode flattens the alpha
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, 7, 7, $transparent);
        $rows = explode('|', SymbolSetKana::OUTLINES[0x3042]);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, 8 + $x, $y, $rows[$y][8 + $x] === 'X' ? $white : $black);
            }
        }
        $strip = ImageSource::fromGd($img, 'image/png');

        // Positive control that the alpha survived the PNG round trip, and
        // the reference bytes: Fgbg emits no pens, so the row is exactly
        // blank + the narrow winner.
        $narrow = (new SymbolMapRenderer())
            ->withCanvasMode(CanvasMode::Fgbg)
            ->render($strip, 2, 1);
        $this->assertSame(" \u{2598}", $narrow);

        // With the veto in place the wide pass is a byte-level no-op on this
        // fixture even though the map carries the kana wide array...
        $kana = (new SymbolMapRenderer(SymbolMap::new()->withKana()))
            ->withCanvasMode(CanvasMode::Fgbg)
            ->render($strip, 2, 1);
        $this->assertSame($narrow, $kana);

        // ...and the exact bytes are pinned. Removing the guard reddens this
        // digest (measured guard-out replacement: the single lead
        // "\u{30ce}" committed across the transparent cell, continuation
        // emitting zero bytes).
        $this->assertSame('44802cdbe01999d5fdbdad3659aef76c13619d58', sha1($kana));
    }
}
