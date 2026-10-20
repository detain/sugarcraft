<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Render\Mode;

/**
 * E-11: the GIF open-time seek clamp must be symmetric.
 *
 * The shipped clamp was upper-bound count() — a seek whose nominal frame
 * number reaches or passes the frame count landed one slot BEYOND the last
 * frame, so next() answered EOF immediately and the Player's loop path
 * wrapped to frame 0 (survey chafa_improvemnts.md :742-747: "seeking to a
 * late time ... jumps to START, visibly wrong"). The fix clamps to
 * count-1 on top and 0 below: past-the-end seeks land on the LAST frame.
 *
 * Fixtures are pack()-built multi-frame GIFs (no GD write dependency):
 * three full-size 1x1 frames painted black / red / green from a shared
 * 4-entry global colour table.
 */
final class GifDecoderSeekTest extends TestCase
{
    private ?string $gifPath = null;

    protected function tearDown(): void
    {
        if ($this->gifPath !== null && file_exists($this->gifPath)) {
            unlink($this->gifPath);
            $this->gifPath = null;
        }
        parent::tearDown();
    }

    /**
     * Byte-level frame colours as they must arrive through the HalfBlock
     * text path: 1 cell column × 2 pixel rows (rowsPerCell 2).
     */
    private const FRAME_BLACK = "\x00\x00\x00\x00\x00\x00";
    private const FRAME_RED = "\xff\x00\x00\xff\x00\x00";
    private const FRAME_GREEN = "\x00\xff\x00\x00\xff\x00";

    /**
     * Build a 3-frame 1x1 GIF (black, red, green) and return its temp path.
     */
    private function writeThreeFrameGif(): string
    {
        $path = sys_get_temp_dir() . '/sugarcraft-gif-seek-' . uniqid('', true) . '.gif';
        file_put_contents($path, $this->seekGifBytes());
        $this->gifPath = $path;
        return $path;
    }

    /**
     * GIF89a, one 4-entry global colour table, three full-frame 1x1 images.
     *
     * The LZW payload pre-clears before every literal (min code size 3,
     * 4-bit codes), so no string-table growth is needed and the stream is
     * valid in any conforming reader.
     */
    private function seekGifBytes(): string
    {
        $table = [
            [0, 0, 0],
            [255, 0, 0],
            [0, 255, 0],
            [0, 0, 255],
        ];
        // 4 entries = 2^(n+1) with n=1; flags: GCT present, 1 field bit -> 4 entries.
        $buf = 'GIF89a' . pack('v', 1) . pack('v', 1) . chr(0x81) . "\x00\x00";
        foreach ($table as [$r, $g, $b]) {
            $buf .= chr($r) . chr($g) . chr($b);
        }

        foreach ([0, 1, 2] as $pixel) {
            // GCE: no disposal, 10 cs delay, no transparency.
            $buf .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
            $buf .= "\x2C" . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1) . "\x00";
            $buf .= $this->lzwFourBitCodes([8, $pixel, 9]);
        }

        return $buf . "\x3B";
    }

    /**
     * @param list<int> $codes
     */
    private function lzwFourBitCodes(array $codes): string
    {
        $acc = 0;
        $n = 0;
        $packed = '';
        foreach ($codes as $code) {
            $acc |= $code << $n;
            $n += 4;
            while ($n >= 8) {
                $packed .= chr($acc & 0xFF);
                $acc >>= 8;
                $n -= 8;
            }
        }
        if ($n > 0) {
            $packed .= chr($acc & 0xFF);
        }

        $blocks = '';
        foreach (str_split($packed, 255) as $chunk) {
            $blocks .= chr(strlen($chunk)) . $chunk;
        }

        return "\x03" . $blocks . "\x00";
    }

    /**
     * @return array<int, string> frame index => rgb bytes, consumed in order
     */
    private function drainAllFrames(GifDecoder $decoder): array
    {
        $seen = [];
        while (($frame = $decoder->next()) !== null) {
            $seen[] = $frame->bytes;
        }
        return $seen;
    }

    public function testTheThreeFrameFixtureDecodesInColourOrder(): void
    {
        $decoder = new GifDecoder();
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 10.0, Mode::HalfBlock, 0.0);

        $seen = $this->drainAllFrames($decoder);
        $decoder->close();

        $this->assertSame(
            [self::FRAME_BLACK, self::FRAME_RED, self::FRAME_GREEN],
            $seen,
            'the fixture must yield exactly black, red, green — every seek pin below rides this identity'
        );
    }

    public function testSeekPastTheEndClampsToTheLastFrame(): void
    {
        $decoder = new GifDecoder();
        // 100 s @ 10 fps = frame 1000, far past the 3 available frames.
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 10.0, Mode::HalfBlock, 100.0);

        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame, 'a past-the-end seek must land on the last frame, not on EOF');
        $this->assertSame(self::FRAME_GREEN, $frame->bytes, 'the clamped seek must sit exactly on the final frame');
    }

    public function testSeekRoundingOntoTheFrameCountClampsToTheLastFrame(): void
    {
        $decoder = new GifDecoder();
        // 0.3 s @ 10 fps = frame index 3 == count(): the old off-by-one slot.
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 10.0, Mode::HalfBlock, 0.3);

        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame, 'round-up onto the frame count must clamp to count-1, not to EOF');
        $this->assertSame(self::FRAME_GREEN, $frame->bytes);
    }

    public function testSeekExactlyOnTheLastFrameStillLandsThere(): void
    {
        $decoder = new GifDecoder();
        // 0.2 s @ 10 fps = frame index 2 == count-1: stable boundary, unchanged by the fix.
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 10.0, Mode::HalfBlock, 0.2);

        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertSame(self::FRAME_GREEN, $frame->bytes);
    }

    public function testNegativeStartSecLandsOnTheFirstFrame(): void
    {
        $decoder = new GifDecoder();
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 10.0, Mode::HalfBlock, -5.0);

        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame, 'a negative startSec must land on frame 0, never on a negative index');
        $this->assertSame(self::FRAME_BLACK, $frame->bytes);
    }

    public function testZeroFpsIgnoresTheSeekAndStartsAtTheFirstFrame(): void
    {
        $decoder = new GifDecoder();
        $decoder->open($this->writeThreeFrameGif(), 1, 1, 0.0, Mode::HalfBlock, 1.0);

        $frame = $decoder->next();
        $decoder->close();

        $this->assertNotNull($frame);
        $this->assertSame(self::FRAME_BLACK, $frame->bytes, 'with no nominal fps the seek is not interpretable; stay at the head');
    }
}
