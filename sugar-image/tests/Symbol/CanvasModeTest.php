<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Symbol;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;
use SugarCraft\Image\Symbol\CanvasMode;

/**
 * A-07: fixed-palette canvas modes (plan_chafa S6.2 / PC-14).
 *
 * The whole point of the quantize-before-score ladder is that a
 * limited palette must not be shown a truecolor-optimal glyph it
 * cannot paint: the pen snapping here is checked against the xterm
 * tables directly (256-slot ramp, 16 system colours, white/black
 * reference pair) rather than against the enum's own arithmetic.
 */
final class CanvasModeTest extends TestCase
{
    public function testTrueColorPassesThrough(): void
    {
        $this->assertSame([12, 200, 33], CanvasMode::TrueColor->quantizeRgb([12, 200, 33]));
        $this->assertFalse(CanvasMode::TrueColor->quantizes());
        $this->assertSame('38;2;12;200;33', CanvasMode::TrueColor->fgSgr([12, 200, 33]));
        $this->assertSame('38;2;12;200;33', CanvasMode::TrueColor->bgSgr([12, 200, 33]));
        $this->assertNull(CanvasMode::TrueColor->fgSgr(null));
    }

    public function testAnsi256SnapsOntoTheFixedRamp(): void
    {
        // (100,100,100) lands on grey-ramp slot 241 == (98,98,98).
        $this->assertSame([98, 98, 98], CanvasMode::Ansi256->quantizeRgb([100, 100, 100]));
        $this->assertSame('38;5;241', CanvasMode::Ansi256->fgSgr([100, 100, 100]));
        $this->assertSame('38;5;241', CanvasMode::Ansi256->bgSgr([100, 100, 100]));
        $this->assertTrue(CanvasMode::Ansi256->quantizes());

        // Exact cube colours are fixed points.
        $this->assertSame([95, 135, 0], CanvasMode::Ansi256->quantizeRgb([95, 135, 0]));
    }

    public function testAnsi16SnapsToTheSystemColourTable(): void
    {
        // (250,0,0) is bright red (#FF0000, slot 9); fg code 91, bg code 101.
        $this->assertSame([255, 0, 0], CanvasMode::Ansi16->quantizeRgb([250, 0, 0]));
        $this->assertSame('91', CanvasMode::Ansi16->fgSgr([250, 0, 0]));
        $this->assertSame('101', CanvasMode::Ansi16->bgSgr([250, 0, 0]));

        // Dark red sits on slot 1 (#CD0000): fg 31 / bg 41.
        $this->assertSame([205, 0, 0], CanvasMode::Ansi16->quantizeRgb([120, 0, 0]));
        $this->assertSame('31', CanvasMode::Ansi16->fgSgr([120, 0, 0]));
        $this->assertSame('41', CanvasMode::Ansi16->bgSgr([120, 0, 0]));
    }

    public function testFgbgCollapsesToTheDefaultPenPair(): void
    {
        // Luma exactly at the 128 boundary crosses to white; 127 stays black.
        $this->assertSame([255, 255, 255], CanvasMode::Fgbg->quantizeRgb([128, 128, 128]));
        $this->assertSame([0, 0, 0], CanvasMode::Fgbg->quantizeRgb([127, 127, 127]));
        $this->assertNull(CanvasMode::Fgbg->fgSgr([10, 20, 30]));
        $this->assertNull(CanvasMode::Fgbg->bgSgr([10, 20, 30]));
    }

    public function testTheSymbolRendererDefaultIsByteFrozenTrueColor(): void
    {
        $image = $this->gradientImage();

        $default = (new SymbolMapRenderer())->render($image, 4);
        $explicit = (new SymbolMapRenderer())->withCanvasMode(CanvasMode::TrueColor)->render($image, 4);
        $this->assertSame($default, $explicit);
        $this->assertStringContainsString('38;2;', $default);
    }

    public function testFgbgSymbolOutputCarriesNoColourParameters(): void
    {
        $image = $this->gradientImage();

        $out = (new SymbolMapRenderer())
            ->withCanvasMode(CanvasMode::Fgbg)
            ->render($image, 4);

        $this->assertStringNotContainsString('38;2;', $out);
        $this->assertStringNotContainsString("\x1b[38", $out);
        // Glyphs still paint — the cell text survives without SGR pens.
        $this->assertMatchesRegularExpression('/[\x{2500}-\x{FFFF}]/u', $out);
    }

    public function testPalettesChangeThePickedGlyphWhenTheTerminalCannotPaintTheWinner(): void
    {
        // A red/green gradient forces red+green pens; on a 1-colour-per-channel
        // terminal (Ansi16) the same cells must still render, quantized.
        $image = $this->gradientImage();

        $trueColor = (new SymbolMapRenderer())->render($image, 4);
        $ansi16 = (new SymbolMapRenderer())->withCanvasMode(CanvasMode::Ansi16)->render($image, 4);

        // Both are 4 rows of glyphs; the palettes differ in encoding...
        $this->assertSame(substr_count($trueColor, "\n"), substr_count($ansi16, "\n"));
        // ...and no Ansi16 pen may use the truecolor introducer.
        $this->assertStringNotContainsString('38;2;', $ansi16);
        $this->assertMatchesRegularExpression('/\x1b\[[0-9;]*(?:3[0-7]|9[0-7])[0-9;]*m/', $ansi16);

        // Quantize-before-score self-consistency: every pen the renderer
        // emitted is one its own palette could have chosen.
        $this->assertStringContainsString('38;5;', (new SymbolMapRenderer())
            ->withCanvasMode(CanvasMode::Ansi256)
            ->render($image, 4));
    }

    public function testQuarterBlockDefaultStaysByteFrozenAndPalettesEmitSnappedSgr(): void
    {
        $image = $this->gradientImage();
        $qbr = new QuarterBlockRenderer();

        $default = $qbr->render($image, 4);
        $this->assertSame($default, $qbr->withCanvasMode(CanvasMode::TrueColor)->render($image, 4));
        $this->assertStringContainsString('38;2;', $default);

        $fgbg = $qbr->withCanvasMode(CanvasMode::Fgbg)->render($image, 4);
        $this->assertStringNotContainsString('38;2;', $fgbg);

        $ansi256 = $qbr->withCanvasMode(CanvasMode::Ansi256)->render($image, 4);
        $this->assertStringNotContainsString('38;2;', $ansi256);
        $this->assertStringContainsString('38;5;', $ansi256);
    }

    private function gradientImage(): \SugarCraft\Image\ImageSource
    {
        $im = imagecreatetruecolor(32, 8);
        for ($x = 0; $x < 32; $x++) {
            $c = imagecolorallocate($im, (int) ($x * 8), 40, 255 - (int) ($x * 8));
            imageline($im, $x, 0, $x, 7, $c);
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        return \SugarCraft\Image\ImageSource::fromString($png);
    }
}
