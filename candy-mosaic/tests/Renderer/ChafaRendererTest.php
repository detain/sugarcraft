<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;

/**
 * Tests for the ChafaRenderer external-binary integration: the memoised
 * available() probe, the optional --format flag, and rendering through the
 * real `chafa` binary (skipped when it is not installed).
 *
 * @covers \SugarCraft\Mosaic\Renderer\ChafaRenderer
 */
final class ChafaRendererTest extends TestCase
{
    /**
     * Build a small real PNG ImageSource at runtime via GD (no committed fixture).
     */
    private static function makeSource(int $w = 8, int $h = 4): ImageSource
    {
        $img = \imagecreatetruecolor($w, $h);
        \imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) \imagecolorallocate($img, 200, 40, 40));
        $source = ImageSource::fromGd($img, 'image/png');
        \imagedestroy($img);

        return $source;
    }

    // -------------------------------------------------------------------------
    // available() — bool, memoised, idempotent
    // -------------------------------------------------------------------------

    /**
     * @testdox available() returns a bool and is idempotent across repeated calls (memoised)
     */
    public function testAvailableIsIdempotentBool(): void
    {
        $first = ChafaRenderer::available();
        $second = ChafaRenderer::available();

        $this->assertIsBool($first);
        $this->assertIsBool($second);
        // The result is memoised in a static, so repeated calls agree.
        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Rendering through the real binary (guarded by available())
    // -------------------------------------------------------------------------

    /**
     * @testdox a 'sixels'-format render produces a non-empty bare-sixel DCS blob
     */
    public function testRenderSixelsFormatProducesDcsBlob(): void
    {
        if (!ChafaRenderer::available()) {
            $this->markTestSkipped('chafa binary not available on this host');
        }

        $renderer = new ChafaRenderer(['--polite', 'on'], 'sixels');
        $output = $renderer->render(self::makeSource(), 8, 4);

        $this->assertIsString($output);
        $this->assertNotSame('', $output);
        // Bare sixel DCS introducer (ESC P).
        $this->assertStringContainsString("\x1bP", $output, 'sixel output carries the DCS intro');
    }

    /**
     * @testdox a default (symbols-mode) render returns a non-empty string
     */
    public function testRenderDefaultSymbolsReturnsNonEmpty(): void
    {
        if (!ChafaRenderer::available()) {
            $this->markTestSkipped('chafa binary not available on this host');
        }

        // No format → chafa's default character-art (symbols) mode.
        $renderer = new ChafaRenderer();
        $output = $renderer->render(self::makeSource(), 8, 4);

        $this->assertIsString($output);
        $this->assertNotSame('', $output);
    }

    // -------------------------------------------------------------------------
    // Argument validation (matches the renderer convention)
    // -------------------------------------------------------------------------

    /**
     * @testdox render() throws InvalidArgumentException for a non-positive width
     */
    public function testInvalidWidthThrows(): void
    {
        $renderer = new ChafaRenderer([], 'sixels');

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(self::makeSource(), 0);
    }

    /**
     * @testdox render() throws InvalidArgumentException for a negative height
     */
    public function testInvalidHeightThrows(): void
    {
        $renderer = new ChafaRenderer([], 'sixels');

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render(self::makeSource(), 8, -1);
    }
}
