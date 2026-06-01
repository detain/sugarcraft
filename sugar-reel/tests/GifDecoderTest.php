<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\DecoderFactory;
use SugarCraft\Reel\Decode\GifDecoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Source\Probe;

/**
 * Unit tests for GifDecoder.
 *
 * Creates a minimal valid GIF using PHP's built-in GD functions,
 * then tests GifDecoder against it. No hand-crafted hex bytes needed.
 *
 * @covers GifDecoder
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
     */
    public function testOpenAndNextReturnsRgbFrame(): void
    {
        $path = $this->createTempGif();

        $decoder = new GifDecoder();
        $decoder->open($path, 1, 1, 10.0);

        $frame = $decoder->next();

        $this->assertInstanceOf(RgbFrame::class, $frame);
        $this->assertSame(1, $frame->w);
        $this->assertSame(1, $frame->h);
        // RgbFrame bytes length = w * h * 3 = 1 * 1 * 3 = 3
        $this->assertSame(3, strlen($frame->bytes));

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
}
