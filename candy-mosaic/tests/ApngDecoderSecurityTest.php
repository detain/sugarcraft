<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ApngDecoder;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Round-1 review regressions for the pure-PHP APNG walk (PR #1442). These pin the
 * adversarial-input behaviours the reviewers demonstrated were bypassable: the
 * aggregate pixel budget derived from the wrong (attacker-declared) count, the
 * unbounded inflate, escape sequences smuggled through an exception message, and
 * spec violations decoded silently instead of failing loud. Each is constructed
 * from hand-built chunks so the normal-bytes path cannot mask the defect.
 *
 * @covers \SugarCraft\Mosaic\ApngDecoder
 */
final class ApngDecoderSecurityTest extends TestCase
{
    /** @param list<array{0:string,1:string}> $pairs */
    private function png(array $pairs): string
    {
        return F::png(array_map(
            static fn(array $p): array => ['type' => $p[0], 'data' => $p[1]],
            $pairs,
        ));
    }

    /**
     * A valid single-frame colour-type-6 APNG whose frame 0 image data is the
     * supplied IDAT bytes — lets a test control the inflated length directly.
     */
    private function singleFrame(string $idat, int $w = 1, int $h = 1, int $colorType = 6, string $plte = ''): string
    {
        $pairs = [['IHDR', F::ihdr($w, $h, $colorType)]];
        if ($plte !== '') {
            $pairs[] = ['PLTE', $plte];
        }
        $pairs[] = ['acTL', F::actl(1)];
        $pairs[] = ['fcTL', F::fctl(0, $w, $h, 0, 0, 1, 100)];
        $pairs[] = ['IDAT', $idat];
        $pairs[] = ['IEND', ''];

        return $this->png($pairs);
    }

    /**
     * C1 — an `acTL` that UNDER-declares the frame count must not shrink the
     * aggregate guard; the declared count is reconciled against the frames the
     * stream actually carries.
     */
    public function testUnderDeclaredAcTLIsRefusedAsMismatch(): void
    {
        $frame = static fn(int $seq, array $px): string => F::frameStream([$px, $px], 1, 1);
        $bytes = $this->png([
            ['IHDR', F::ihdr(1, 1)],
            ['acTL', F::actl(1)], // lie: claims a single frame
            ['fcTL', F::fctl(0, 1, 1, 0, 0, 1, 100)],
            ['IDAT', $frame(0, [255, 0, 0, 255])],
            ['fcTL', F::fctl(1, 1, 1, 0, 0, 1, 100)],
            ['fdAT', pack('N', 2) . $frame(2, [0, 255, 0, 255])],
            ['fcTL', F::fctl(3, 1, 1, 0, 0, 1, 100)],
            ['fdAT', pack('N', 4) . $frame(4, [0, 0, 255, 255])],
            ['IEND', ''],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('carries 3');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * C1 — an `acTL` claiming zero frames is never an entry ticket to a real,
     * multi-frame decode.
     */
    public function testZeroAcTLWithRealFramesIsRefused(): void
    {
        $px = [[255, 0, 0, 255], [255, 0, 0, 255]];
        $bytes = $this->png([
            ['IHDR', F::ihdr(1, 2)],
            ['acTL', F::actl(0)], // claim none…
            ['fcTL', F::fctl(0, 1, 2, 0, 0, 1, 100)],
            ['IDAT', F::frameStream($px, 1, 2)],
            ['fcTL', F::fctl(1, 1, 2, 0, 0, 1, 100)],
            ['fdAT', pack('N', 2) . F::frameStream($px, 1, 2)],
            ['IEND', ''],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('carries 2');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * C2 — a frame whose zlib stream inflates to far more than its declared
     * geometry must be refused by a BOUNDED inflate, not materialised first.
     */
    public function testOversizedInflateIsRefused(): void
    {
        // 1×1 RGBA expects 5 inflated bytes; ship a 1 MiB expansion behind it.
        $bomb = gzcompress(str_repeat("\x00", 1_048_576));
        $bytes = $this->singleFrame($bomb);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inflated');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * C2 — an inflate SHORTER than the declared geometry is corruption, reported
     * with its own reason rather than a phantom truncation further down.
     */
    public function testUndersizedInflateReportsBadFrameLength(): void
    {
        $bytes = $this->singleFrame(gzcompress('abc')); // 3 bytes, geometry wants 5

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected exactly 5');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * H2 — a chunk type is four ASCII letters; a hostile non-printable type must
     * never be interpolated raw into an exception message (TUI escape injection).
     */
    public function testNonAsciiChunkTypeIsRejectedWithoutLeakingEscapeBytes(): void
    {
        $bytes = $this->png([
            ['IHDR', F::ihdr(1, 1)],
            ['acTL', F::actl(1)],
            ["\x1b[2J", ''], // erase-screen control as a "chunk type"
        ]);

        try {
            ApngDecoder::decode($bytes, 50_000_000);
            $this->fail('a non-ASCII chunk type must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\x1b", $e->getMessage(), 'the raw ESC must not reach the message');
            $this->assertStringContainsString('1b5b324a', $e->getMessage(), 'the type is reported as hex');
        }
    }

    /**
     * M3 — a torn PLTE (length not a multiple of three) is corruption, not a
     * silently split palette entry.
     */
    public function testTornPaletteIsRefused(): void
    {
        $bytes = $this->singleFrame(F::frameStream([[0, 0, 0, 0]], 1, 1, 1), 1, 1, 3, "\x01\x02\x03\x04\x05\x06\x07");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PLTE');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * M3 — a palette index past the end of the table is a fatal spec violation,
     * not opaque black.
     */
    public function testPaletteIndexOutOfRangeIsRefused(): void
    {
        // One palette entry (index 0 only) but the scanline references index 5.
        $bytes = $this->singleFrame(gzcompress("\x00\x05"), 1, 1, 3, "\xaa\xbb\xcc");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('palette index 5');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * L1 — an out-of-range scanline filter byte is an unsupported filter, not a
     * phantom truncation.
     */
    public function testUnsupportedScanlineFilterIsRefused(): void
    {
        $bytes = $this->singleFrame(gzcompress("\x09\x00\x00\x00\x00")); // filter 9, one RGBA pixel

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('filter 9');
        ApngDecoder::decode($bytes, 50_000_000);
    }

    /**
     * m5 — a reserved dispose_op is refused rather than coerced to a neighbour.
     */
    public function testReservedDisposeOpIsRefused(): void
    {
        $pairs = [
            ['IHDR', F::ihdr(1, 1)],
            ['acTL', F::actl(1)],
            ['fcTL', F::fctl(0, 1, 1, 0, 0, 1, 100, dispose: 3)],
            ['IDAT', F::frameStream([[255, 0, 0, 255]], 1, 1)],
            ['IEND', ''],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dispose_op');
        ApngDecoder::decode($this->png($pairs), 50_000_000);
    }

    /**
     * m5 — a reserved blend_op is refused rather than coerced to "over".
     */
    public function testReservedBlendOpIsRefused(): void
    {
        $pairs = [
            ['IHDR', F::ihdr(1, 1)],
            ['acTL', F::actl(1)],
            ['fcTL', F::fctl(0, 1, 1, 0, 0, 1, 100, blend: 2)],
            ['IDAT', F::frameStream([[255, 0, 0, 255]], 1, 1)],
            ['IEND', ''],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('blend_op');
        ApngDecoder::decode($this->png($pairs), 50_000_000);
    }
}
