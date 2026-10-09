<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Preview;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SugarCraft\Crush\Media\PreviewPaint;
use SugarCraft\Crush\Renderer;
use SugarCraft\Mosaic\Mosaic;

/**
 * The preview painter's two laws (W2.4): the BUDGET (quarter-pane, capped at
 * 24 cols - a thumbnail never outshouts the chat) and the SEPARATION (frames
 * churn through PreviewPaint's own bounded memo and NEVER touch Renderer's
 * transcript picture LRU, so a ten-frame render cannot evict the settled
 * images the user is still reading - crush_media.md §8.6-4).
 */
final class PreviewPaintTest extends TestCase
{
    /** A 1x1 PNG, the smallest frame shape A1111's current_image can arrive as. */
    private const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testTheBudgetIsQuarterPaneCappedAtTwentyFourCols(): void
    {
        $this->assertSame(24, PreviewPaint::MAX_COLS);
        $this->assertSame(24, PreviewPaint::budgetCols(100), 'a quarter of 100 is 25, the cap holds');
        $this->assertSame(10, PreviewPaint::budgetCols(40));
        $this->assertSame(6, PreviewPaint::budgetCols(25), 'integer division, rounded down');
        $this->assertSame(1, PreviewPaint::budgetCols(3), 'a hairline pane still gets one column');
        $this->assertSame(1, PreviewPaint::budgetCols(0), 'never zero-wide');
    }

    public function testGarbageInIsNullOutNotAnErrorRow(): void
    {
        $mosaic = $this->inlineMosaic();

        // base64_decode strict: 'not base64!!' carries characters outside
        // the alphabet, so it is refused before anything is decoded.
        $this->assertNull(PreviewPaint::paint('not base64!!', $mosaic, 100, 4));
        $this->assertNull(PreviewPaint::paint(
            base64_encode('this is definitely not a PNG payload'),
            $mosaic,
            100,
            4,
        ), 'a non-PNG body is dropped, never rendered or trusted');
    }

    public function testAPngFramePaintsWithinBudgetAndRowClamp(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        PreviewPaint::resetForTesting();
        $mosaic = $this->inlineMosaic();

        $painted = PreviewPaint::paint(self::TINY_PNG_B64, $mosaic, 100, 4);

        $this->assertNotNull($painted, 'a real PNG paints');
        $this->assertSame(24, $painted['cols'], 'drawn inside the W2.4 cap even on a 100-col pane');
        $this->assertGreaterThanOrEqual(1, $painted['rows']);
        $this->assertLessThanOrEqual(4, $painted['rows'], 'the row budget clamps');
        $this->assertNotSame('', $painted['body']);
    }

    public function testTheMemoStaysBoundedAndNeverTouchesTheTranscriptCache(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        PreviewPaint::resetForTesting();
        $mosaic = $this->inlineMosaic();
        $cacheBefore = (new ReflectionClass(Renderer::class))->getStaticPropertyValue('imageCache');

        // Six distinct frames (> MEMO_MAX) churn through, at wildly different
        // budgets so no two keys collide.
        for ($n = 0; $n < 6; $n++) {
            $bytes = $this->pngBytes((int) ($n + 1));
            PreviewPaint::paint(base64_encode($bytes), $mosaic, 100, max(1, 2 + $n));
        }

        $memo = (new ReflectionClass(PreviewPaint::class))->getStaticPropertyValue('memo');
        $this->assertLessThanOrEqual(4, \count($memo), 'the frame memo holds a handful, not the render');

        $cacheAfter = (new ReflectionClass(Renderer::class))->getStaticPropertyValue('imageCache');
        $this->assertSame($cacheBefore, $cacheAfter, 'preview churn may not occupy - or evict - a transcript picture slot');
    }

    public function testRepeatingTheSameFrameHitsTheMemoAndReturnsIdentically(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        PreviewPaint::resetForTesting();
        $mosaic = $this->inlineMosaic();

        $first = PreviewPaint::paint(self::TINY_PNG_B64, $mosaic, 80, 3);
        $second = PreviewPaint::paint(self::TINY_PNG_B64, $mosaic, 80, 3);

        $this->assertSame($first, $second, 'same frame, same pane, same cells - memo hit or fresh render');
    }

    private function inlineMosaic(): Mosaic
    {
        $mosaic = Mosaic::fromModeString('halfblock');
        $this->assertNotNull($mosaic);

        return $mosaic;
    }

    /**
     * A $width x 1 PNG built through GD - distinct widths give distinct frames
     * for the churn pins without needing six fixture files.
     */
    private function pngBytes(int $width): string
    {
        $im = imagecreatetruecolor(max(1, $width), 1);
        $this->assertNotFalse($im);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, $width * 8 % 256, 30, 200));
        $captured = tempnam(sys_get_temp_dir(), 'sc-preview-');
        $this->assertNotFalse(imagepng($im, $captured));
        imagedestroy($im);
        $bytes = (string) file_get_contents($captured);
        @unlink($captured);

        return $bytes;
    }
}
