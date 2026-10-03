<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Scale;

/**
 * Destination canvases must be bounded BEFORE they are allocated: resize()'s
 * target, the Scale::None native-size cell box, and Sixel's cells × font-cell
 * pixel canvas each used to be checked (if at all) only after GD had already
 * attempted the allocation.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 * @covers \SugarCraft\Mosaic\Mosaic
 * @covers \SugarCraft\Mosaic\Renderer\SixelRenderer
 */
final class RenderAllocationGuardTest extends TestCase
{
    private function png(int $w, int $h): ImageSource
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return ImageSource::fromString($bytes);
    }

    public function testResizeRefusesAnOverCeilingTargetBeforeAllocating(): void
    {
        // 100 000 × 100 000 = 10 G pixels: GD would attempt (and warn on) the
        // allocation; the ceiling must refuse it first with the typed budget error.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/100000×100000 exceed the maximum of/');
        $this->png(4, 4)->resize(100_000, 100_000);
    }

    public function testResizeHonoursALoweredCeiling(): void
    {
        $source = $this->png(4, 4)->withMaxPixels(100);
        self::assertSame([10, 10], [$source->resize(10, 10)->width, $source->resize(10, 10)->height]);

        $this->expectException(\InvalidArgumentException::class);
        $source->resize(11, 10);
    }

    public function testScaleNoneNeverWidensTheCellBoxPastTheRequest(): void
    {
        $out = Mosaic::halfBlock()->withScale(Scale::None)->render($this->png(200, 100), 10);
        $rows = explode("\n", $out);

        self::assertSame(10, mb_strlen((string) preg_replace('/\x1b\[[0-9;]*m/', '', $rows[0])));
        self::assertCount(5, $rows);
    }

    public function testScaleNoneKeepsNativeSizeWhenItFits(): void
    {
        $out = Mosaic::halfBlock()->withScale(Scale::None)->render($this->png(6, 4), 40);
        $rows = explode("\n", $out);

        self::assertSame(6, mb_strlen((string) preg_replace('/\x1b\[[0-9;]*m/', '', $rows[0])));
    }

    public function testSixelCanvasIsHeldToTheSourcePixelCeiling(): void
    {
        // 4×4 source under a 10 000-pixel ceiling; a 100-cell box is a
        // 1000 × 2000 px canvas (10×20 px cells) — far past that ceiling.
        $source = $this->png(4, 4)->withMaxPixels(10_000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Sixel canvas 1000×2000 px .* exceeds the 10000-pixel ceiling/');
        (new SixelRenderer())->render($source, 100, 100);
    }

    public function testSixelCanvasCeilingDisabledWithBudget(): void
    {
        $source = $this->png(4, 4)->withMaxPixels(0);

        self::assertStringStartsWith("\x1bP", (new SixelRenderer())->render($source, 4, 2));
    }
}
