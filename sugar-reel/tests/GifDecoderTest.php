<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\DecoderFactory;
use SugarCraft\Reel\Decode\GifDecoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;
use SugarCraft\Reel\Source\Probe;

/**
 * Unit tests for GifDecoder.
 *
 * Creates a minimal valid GIF using PHP's built-in GD functions,
 * then tests GifDecoder against it. No hand-crafted hex bytes needed.
 *
 * @covers \SugarCraft\Reel\Decode\GifDecoder
 */
final class GifDecoderTest extends TestCase
{
    private ?string $tempGifPath = null;

    protected function tearDown(): void
    {
        if ($this->tempGifPath !== null && file_exists($this->tempGifPath)) {
            unlink($this->tempGifPath);
            $this->tempGifPath = null;
        }
        parent::tearDown();
    }

    /**
     * Create a minimal valid 1×1 black pixel GIF using PHP's GD functions,
     * write it to a temp file, and return the path.
     */
    private function createTempGif(): string
    {
        // Create a 1×1 black pixel image using GD
        $img = imagecreate(1, 1);
        $black = imagecolorallocate($img, 0, 0, 0); // black
        imagesetpixel($img, 0, 0, $black);

        $path = sys_get_temp_dir() . '/sugarcraft-gif-test-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        $this->tempGifPath = $path;
        return $path;
    }

    // -------------------------------------------------------------------------
    // open + next returns an RgbFrame
    // -------------------------------------------------------------------------

    /**
     * @testdox open() followed by next() returns an RgbFrame with correct dimensions
     *
     * With no $mode the decoder defaults to HalfBlock semantics (2 source rows
     * per cell), so a 1×1 cell GIF decodes to 1 col × 2 pixel-rows.
     */
    public function testOpenAndNextReturnsRgbFrame(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $frame = $decoder->next();

        $this->assertInstanceOf(RgbFrame::class, $frame);
        $this->assertSame(1, $frame->w);
        // $mode === null defaults to HalfBlock (rowsPerCell 2): cellsH 1 → 2 rows.
        $this->assertSame(2, $frame->h);
        // RgbFrame bytes length = w * h * 3 = 1 * 2 * 3 = 6
        $this->assertSame(6, strlen($frame->bytes));

        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // Iterator exhaustion
    // -------------------------------------------------------------------------

    /**
     * @testdox next() returns null when all frames have been consumed
     */
    public function testOpenAndNextReturnsNullWhenExhausted(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $first = $decoder->next();
        $this->assertNotNull($first); // At least one frame

        $second = $decoder->next();
        $this->assertNull($second); // No more frames

        $decoder->close();
    }

    /**
     * @testdox next() returns null after close() is called (not an exception)
     */
    public function testCloseThenNextReturnsNull(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);
        $decoder->next(); // consume the one frame
        $decoder->close();

        // After close(), next() must return null, not throw
        $result = $decoder->next();
        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // getIterator
    // -------------------------------------------------------------------------

    /**
     * @testdox getIterator() returns a Generator that yields at least one frame
     */
    public function testGetIterator(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $iterator = $decoder->getIterator();
        $this->assertInstanceOf(\Generator::class, $iterator);

        // Iterate and count frames using the iterator (NOT $decoder directly —
        // foreach on the Decoder object calls getIterator() again, creating
        // a second generator that starts from frameIndex 0 and racing with
        // the first, resulting in 0 frames counted in the wrong iterator).
        $frameCount = 0;
        foreach ($iterator as $frame) {
            $frameCount++;
            $this->assertInstanceOf(RgbFrame::class, $frame);
        }

        $this->assertGreaterThanOrEqual(1, $frameCount, 'Should yield at least 1 frame');
        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // Pixel content verification
    // -------------------------------------------------------------------------

    /**
     * @testdox toGd() on the decoded RgbFrame produces a correct black pixel image
     */
    public function testDecodedFramePixelContentIsBlack(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $frame = $decoder->next();
        $this->assertNotNull($frame);

        // Convert RgbFrame back to GD and check the pixel color
        $img = $frame->toGd();
        $rgb = imagecolorat($img, 0, 0);

        // R=0, G=0, B=0 for black pixel
        $this->assertSame(0, ($rgb >> 16) & 0xff, 'R component should be 0');
        $this->assertSame(0, ($rgb >> 8) & 0xff,  'G component should be 0');
        $this->assertSame(0, $rgb & 0xff,          'B component should be 0');

        imagedestroy($img);
        $decoder->close();
    }

    // -------------------------------------------------------------------------
    // F5: GIF decode height honors the rendering mode
    // -------------------------------------------------------------------------

    /**
     * Create a small multi-color truecolor GIF and return its path.
     */
    private function createTempColorGif(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        // Paint a deterministic gradient so the frame is non-trivial.
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $color = imagecolorallocate($img, ($x * 31) % 256, ($y * 47) % 256, (($x + $y) * 17) % 256);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        $path = sys_get_temp_dir() . '/sugar-reel-mode-gif-' . uniqid('', true) . '.gif';
        imagegif($img, $path);
        imagedestroy($img);

        $this->tempGifPath = $path;
        return $path;
    }

    /**
     * Regression for F5. GifDecoder must scale the decoded frame height to
     * cellsH * mode->rowsPerCell() so it matches FfmpegDecoder per mode:
     *   - HalfBlock packs 2 source rows per cell → frame->h == cellsH * 2
     *   - Ascii (and the other 1-row modes) → frame->h == cellsH
     *
     * On master GifDecoder always decoded at cellsH (mode ignored), so the
     * HalfBlock frame came out at h == cellsH (6) instead of 12 — the assert
     * `$frame->h === 12` fails.
     *
     * @testdox GifDecoder frame height tracks the mode (HalfBlock 2x, Ascii 1x)
     */
    public function testGifDecodeHeightHonorsMode(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension required to build a test GIF');
        }

        $path = $this->createTempColorGif(8, 6);

        // HalfBlock: 2 source rows per cell → height doubled.
        $hb = new GifDecoder();
        $hb->open($path, 8, 6, 10.0, Mode::HalfBlock);
        $hbFrame = $hb->next();
        $hb->close();

        $this->assertNotNull($hbFrame);
        $this->assertSame(8, $hbFrame->w, 'width must equal cellsW');
        $this->assertSame(6 * 2, $hbFrame->h, 'HalfBlock height must be cellsH * 2 == 12');

        // Ascii: 1 source row per cell → height equals cellsH.
        $ascii = new GifDecoder();
        $ascii->open($path, 8, 6, 10.0, Mode::Ascii);
        $asciiFrame = $ascii->next();
        $ascii->close();

        $this->assertNotNull($asciiFrame);
        $this->assertSame(8, $asciiFrame->w, 'width must equal cellsW');
        $this->assertSame(6, $asciiFrame->h, 'Ascii height must equal cellsH == 6');
    }
}
