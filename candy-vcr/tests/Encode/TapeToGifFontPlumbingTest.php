<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\Rasterizer;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Snapshot;

/**
 * `Set FontSize` has two consumers in the render pipeline: the cell grid
 * (canvas geometry) and the glyph tiles (how big the text actually is).
 * Only the grid was wired — the rasterizer was re-fonted with the family
 * alone, so a 28pt tape drew 14pt glyphs inside 28pt-sized cells.
 *
 * Leg 1 pins the geometry half end-to-end through a recording rasterizer.
 * Leg 2 pins the glyph half at the seam that was severed: the resolved
 * point size must reach both backend arms' `withFont()` call. The size a
 * cloned rasterizer carries is not observable from the outside (GdRasterizer
 * is final and keeps it private), and `withFont()`'s own honoring is already
 * pinned in {@see \SugarCraft\Vcr\Tests\Raster\GdRasterizerTest}, so this is
 * a wiring pin on the method body.
 */
final class TapeToGifFontPlumbingTest extends TestCase
{
    /** @var array{0: int, 1: int}|null */
    private ?array $firstCell = null;

    private string $dir;

    protected function setUp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('ext/gd not available');
        }
        $this->firstCell = null;
        $this->dir = sys_get_temp_dir() . '/candy-vcr-t2g-font-' . bin2hex(random_bytes(4));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            self::fail("Failed to create temp dir: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->dir . '/' . $entry);
            }
        }
        @rmdir($this->dir);
    }

    public function testHeaderFontSizeSizesTheCellGrid(): void
    {
        $this->renderThroughRecorder(14);
        $small = $this->firstCell;
        $this->firstCell = null;
        $this->renderThroughRecorder(28);

        $this->assertNotNull($small);
        $this->assertNotNull($this->firstCell);
        $this->assertSame([8, 28], $small, 'FontSize 14 must size cells from the same metrics the grid derives');
        $this->assertSame([16, 56], $this->firstCell, 'a doubled point size must double the cell');
    }

    public function testResolvedFontSizeIsForwardedToEveryRasterizerBackend(): void
    {
        $helper = $this->methodSource('themedRasterizerWithFonts');
        $render = $this->methodSource('render');

        // Both backend arms must receive the size, and neither may fall back
        // to the family-only call that left the glyphs at the ctor default.
        $this->assertSame(2, substr_count($helper, 'withFont($fontFamily, $fontSize)'));
        $this->assertSame(0, substr_count($helper, 'withFont($fontFamily)'));

        // The size handed to the font plumbing must be the same resolved value
        // the cell grid uses — one source of truth, not a re-read of options.
        $this->assertSame(1, substr_count($render, 'themedRasterizerWithFonts($theme, $fontFamily, $fontSize)'));
        $this->assertSame(1, substr_count($render, 'Compiler::cellMetrics($fontSize)'));
    }

    private function renderThroughRecorder(int $fontSize): void
    {
        $tapePath = $this->dir . '/font-' . $fontSize . '.tape';
        file_put_contents(
            $tapePath,
            "Set Theme \"TokyoNight\"\nSet FontSize $fontSize\nSet Width 640\nSet Height 224\n"
            . "Type \"hello world\"\nSleep 100ms\n",
        );

        $recorder = new class implements Rasterizer {
            /** @var array{0: int, 1: int}|null */
            public ?array $firstCell = null;

            public function rasterize(
                Snapshot $snapshot,
                int $cellW,
                int $cellH,
                ?FontLoader $fonts = null,
                bool $renderCursor = true,
            ): \GdImage {
                $this->firstCell ??= [$cellW, $cellH];
                $image = imagecreatetruecolor($cellW, $cellH);
                if (!$image instanceof \GdImage) {
                    throw new \RuntimeException('imagecreatetruecolor failed');
                }
                return $image;
            }
        };

        $pipeline = new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), $recorder, new PhpGifEncoder());
        $pipeline->render($tapePath, $this->dir . '/font-' . $fontSize . '.gif', ['encoder' => 'php']);

        $this->firstCell = $recorder->firstCell;
    }

    private function methodSource(string $method): string
    {
        $reflection = new \ReflectionMethod(TapeToGif::class, $method);
        $file = $reflection->getFileName();
        $this->assertIsString($file);

        $lines = file($file);
        $this->assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }
}
