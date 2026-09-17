<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ApngDecoder;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Finding #18: the pure-PHP APNG walk — chunk framing, the dispose/blend state
 * machine, the decompression-bomb guards, and fail-fast on corrupt streams.
 *
 * @covers \SugarCraft\Mosaic\ApngDecoder
 */
final class ApngDecoderTest extends TestCase
{
    private const RED = [255, 0, 0, 255];
    private const GREEN = [0, 255, 0, 255];
    private const BLUE = [0, 0, 255, 255];
    private const CLEAR = [0, 0, 0, 0];

    /** @param string $rgba raw width×height×4 byte buffer */
    private static function pixel(string $rgba, int $width, int $x, int $y): array
    {
        $o = ($y * $width + $x) * 4;

        return [
            ord($rgba[$o]),
            ord($rgba[$o + 1]),
            ord($rgba[$o + 2]),
            ord($rgba[$o + 3]),
        ];
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    public function testIsAnimatedPngDetectsAcTL(): void
    {
        $apng = F::apng(2, 2, [[
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 1, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED, self::RED, self::RED, self::RED],
        ]]);
        $this->assertTrue(ApngDecoder::isAnimatedPng($apng));
    }

    public function testIsAnimatedPngRejectsStillImage(): void
    {
        $still = file_get_contents(__DIR__ . '/fixtures/8x4_red.png');
        $this->assertIsString($still);
        $this->assertFalse(ApngDecoder::isAnimatedPng($still));
    }

    public function testIsAnimatedPngRejectsGif(): void
    {
        $gif = F::gif(2, 2, [[0, 0, 0], [255, 0, 0]], [
            ['pixels' => [1, 1, 1, 1], 'delay' => 5],
        ]);
        $this->assertFalse(ApngDecoder::isAnimatedPng($gif));
    }

    public function testSingleFrameDelayAndDimensions(): void
    {
        $apng = F::apng(2, 2, [[
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 10, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED, self::GREEN, self::BLUE, [0, 0, 0, 128]],
        ]]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        $this->assertCount(1, $frames);
        $this->assertSame(2, $frames[0]['width']);
        $this->assertSame(2, $frames[0]['height']);
        $this->assertSame(100, $frames[0]['delayMs']);
        $this->assertSame(self::RED, self::pixel($frames[0]['rgba'], 2, 0, 0));
        $this->assertSame(self::GREEN, self::pixel($frames[0]['rgba'], 2, 1, 0));
        $this->assertSame(self::BLUE, self::pixel($frames[0]['rgba'], 2, 0, 1));
        $this->assertSame([0, 0, 0, 128], self::pixel($frames[0]['rgba'], 2, 1, 1));
    }

    public function testZeroDenominatorFallsBackToCentiseconds(): void
    {
        // APNG requires a non-zero denominator; a malformed 0 is treated as
        // hundredths-of-a-second, so delayNum 5 → 5/100 s → 50 ms.
        $apng = F::apng(1, 1, [[
            'w' => 1, 'h' => 1, 'x' => 0, 'y' => 0,
            'delayNum' => 5, 'delayDen' => 0, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED],
        ]]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        $this->assertSame(50, $frames[0]['delayMs']);
    }

    public function testDisposeBackgroundClearsRectangleForNextFrame(): void
    {
        // frame0 paints the whole canvas red then disposes to background, so
        // frame1 (a single blue cell) must show transparency everywhere else.
        $apng = F::apng(2, 2, [
            [
                'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
                'delayNum' => 1, 'delayDen' => 100, 'dispose' => 1, 'blend' => 0,
                'pixels' => [self::RED, self::RED, self::RED, self::RED],
            ],
            [
                'w' => 1, 'h' => 1, 'x' => 1, 'y' => 1,
                'delayNum' => 2, 'delayDen' => 100, 'dispose' => 0, 'blend' => 1,
                'pixels' => [self::BLUE],
            ],
        ]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        $this->assertCount(2, $frames);
        // frame1 emitted BEFORE its own dispose → shows blue at (1,1), cleared elsewhere.
        $this->assertSame(self::BLUE, self::pixel($frames[1]['rgba'], 2, 1, 1));
        $this->assertSame(self::CLEAR, self::pixel($frames[1]['rgba'], 2, 0, 0));
    }

    public function testDisposeNoneLeaksPaintIntoNextFrame(): void
    {
        $apng = F::apng(2, 2, [
            [
                'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
                'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
                'pixels' => [self::RED, self::RED, self::RED, self::RED],
            ],
            [
                'w' => 1, 'h' => 1, 'x' => 1, 'y' => 1,
                'delayNum' => 2, 'delayDen' => 100, 'dispose' => 0, 'blend' => 1,
                'pixels' => [self::BLUE],
            ],
        ]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        // frame0 was NOT disposed → its red still shows through at (0,0) in frame1.
        $this->assertSame(self::RED, self::pixel($frames[1]['rgba'], 2, 0, 0));
        $this->assertSame(self::BLUE, self::pixel($frames[1]['rgba'], 2, 1, 1));
    }

    public function testDisposePreviousRestoresEarlierCanvas(): void
    {
        $apng = F::apng(2, 2, [
            [ // frame0: full green, dispose NONE → canvas now green.
                'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
                'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
                'pixels' => [self::GREEN, self::GREEN, self::GREEN, self::GREEN],
            ],
            [ // frame1: red cell, dispose PREVIOUS → canvas rolls back to green.
                'w' => 1, 'h' => 1, 'x' => 0, 'y' => 0,
                'delayNum' => 2, 'delayDen' => 100, 'dispose' => 2, 'blend' => 0,
                'pixels' => [self::RED],
            ],
            [ // frame2: blue cell → composes on the restored green canvas.
                'w' => 1, 'h' => 1, 'x' => 1, 'y' => 1,
                'delayNum' => 3, 'delayDen' => 100, 'dispose' => 0, 'blend' => 1,
                'pixels' => [self::BLUE],
            ],
        ]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        $this->assertCount(3, $frames);
        // frame1 snapshot shows the red cell painted before rollback.
        $this->assertSame(self::RED, self::pixel($frames[1]['rgba'], 2, 0, 0));
        // frame2 sees green (restored) at (0,0), not red.
        $this->assertSame(self::GREEN, self::pixel($frames[2]['rgba'], 2, 0, 0));
        $this->assertSame(self::BLUE, self::pixel($frames[2]['rgba'], 2, 1, 1));
    }

    public function testBlendOverAlphaComposites(): void
    {
        $half = [0, 0, 255, 128];
        $apng = F::apng(1, 1, [
            [
                'w' => 1, 'h' => 1, 'x' => 0, 'y' => 0,
                'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
                'pixels' => [self::RED],
            ],
            [
                'w' => 1, 'h' => 1, 'x' => 0, 'y' => 0,
                'delayNum' => 2, 'delayDen' => 100, 'dispose' => 0, 'blend' => 1,
                'pixels' => [$half],
            ],
        ]);
        $frames = ApngDecoder::decode($apng, 50_000_000);

        [$r, $g, $b, $a] = self::pixel($frames[1]['rgba'], 1, 0, 0);
        // 50%-blue over opaque red: still opaque, red channel reduced, blue raised.
        $this->assertSame(255, $a);
        $this->assertLessThan(255, $r);
        $this->assertGreaterThan(0, $b);
    }

    public function testPerFramePixelCeiling(): void
    {
        $apng = F::apng(2, 2, [[
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED, self::RED, self::RED, self::RED],
        ]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed/i');
        ApngDecoder::decode($apng, 3);
    }

    public function testAggregatePixelCeiling(): void
    {
        // 2×2 canvas × 3 frames = 12 px total; a 6 px ceiling passes the
        // per-frame check (4 ≤ 6) but trips the aggregate one.
        $frame = [
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED, self::RED, self::RED, self::RED],
        ];
        $apng = F::apng(2, 2, [$frame, $frame, $frame]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pixels|aggregate|too many/i');
        ApngDecoder::decode($apng, 6);
    }

    public function testBadChunkCrcRejected(): void
    {
        $apng = F::apng(1, 1, [[
            'w' => 1, 'h' => 1, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED],
        ]]);
        // Corrupt a byte inside the IHDR data block (leaves the type intact so
        // the walk trips the checksum, not a missing-header error).
        $apng[24] = chr(ord($apng[24]) ^ 0xFF);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/crc|checksum/i');
        ApngDecoder::decode($apng, 50_000_000);
    }

    public function testTruncatedStreamRejected(): void
    {
        $apng = F::apng(2, 2, [[
            'w' => 2, 'h' => 2, 'x' => 0, 'y' => 0,
            'delayNum' => 1, 'delayDen' => 100, 'dispose' => 0, 'blend' => 0,
            'pixels' => [self::RED, self::RED, self::RED, self::RED],
        ]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/truncat/i');
        ApngDecoder::decode(substr($apng, 0, 20), 50_000_000);
    }

    public function testUnsupportedBitDepthRejected(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NN', 2, 2) . chr(16) . chr(6) . chr(0) . chr(0) . chr(0))
            . self::chunk('acTL', pack('N', 1) . pack('N', 0));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bit depth|depth/i');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    public function testUnsupportedInterlaceRejected(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NN', 2, 2) . chr(8) . chr(6) . chr(0) . chr(0) . chr(1))
            . self::chunk('acTL', pack('N', 1) . pack('N', 0));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/interlac/i');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    public function testMissingFramesRejected(): void
    {
        // acTL claims 2 frames but carries none.
        $bytes = "\x89PNG\r\n\x1a\n"
            . self::chunk('IHDR', pack('NN', 2, 2) . chr(8) . chr(6) . chr(0) . chr(0) . chr(0))
            . self::chunk('acTL', pack('N', 2) . pack('N', 0))
            . self::chunk('IEND', '');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/frame/i');
        ApngDecoder::decode($bytes, 50_000_000);
    }
}
