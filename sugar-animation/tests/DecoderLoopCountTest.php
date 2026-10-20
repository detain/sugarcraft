<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;

/**
 * E-02: NETSCAPE2.0 loop-extension intake of {@see Decoder::loopCount()}.
 *
 * Every expectation is stated in TOTAL playthroughs — the normalised shape
 * libnsgif reaches with its `if (loop_max > 0) loop_max++` (gif.c:862-871) —
 * and the raw wire value is what the fixtures spell. The byte fixtures are
 * assembled with pack(): header + Logical Screen Descriptor + the extension
 * block + trailer, so the walk is pinned without any GD involvement.
 */
final class DecoderLoopCountTest extends TestCase
{
    /**
     * A four-pixel screen: signature + LSD (no GCT) + body + trailer.
     */
    private static function stream(string $body, string $signature = 'GIF89a'): string
    {
        return $signature . pack('v2C5', 4, 4, 0, 0, 0, 0, 0) . $body . "\x3B";
    }

    /**
     * The NETSCAPE2.0 app extension carrying $raw centiseconds-of-loop wire
     * value (0 = forever), or the Animation Shop identifier twin.
     */
    private static function loopBlock(int $raw, string $identifier = 'NETSCAPE2.0'): string
    {
        return "\x21\xFF\x0B" . $identifier . "\x03\x01" . pack('v', $raw) . "\x00";
    }

    /**
     * A bare one-pixel image descriptor + a zero-data LZW payload, so the
     * walk has something frame-shaped to step over.
     */
    private static function frameBlock(): string
    {
        return "\x2C" . pack('v4C1', 0, 0, 1, 1, 0) . "\x02\x00\x00";
    }

    public function testStreamWithoutTheExtensionIsInfinite(): void
    {
        $this->assertNull(Decoder::loopCount(self::stream('')));
        $this->assertNull(Decoder::loopCount(self::stream(self::frameBlock())), 'frames without a loop block stay infinite');
    }

    public function testRawZeroMeansForever(): void
    {
        $this->assertNull(Decoder::loopCount(self::stream(self::loopBlock(0))));
    }

    #[DataProvider('rawExtraRepeatProvider')]
    public function testRawNMeansPlusOneTotalPlaythroughs(int $raw, int $expectedTotal): void
    {
        $this->assertSame($expectedTotal, Decoder::loopCount(self::stream(self::loopBlock($raw))));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function rawExtraRepeatProvider(): array
    {
        return [
            'play once extra = two total' => [1, 2],
            'two extra = three'           => [2, 3],
            'sixteen extra = seventeen'   => [16, 17],
            'u16 max'                     => [65535, 65536],
        ];
    }

    public function testAnimationShopIdentifierIsRecognised(): void
    {
        // libnsgif matches BOTH 11-byte identifiers (gif.c:808 region).
        $this->assertSame(3, Decoder::loopCount(self::stream(self::loopBlock(2, 'ANIMEXTS1.0'))));
    }

    public function testWrongIdentifierIsIgnored(): void
    {
        $this->assertNull(Decoder::loopCount(self::stream(self::loopBlock(2, 'NETSCOPE4.2'))));
    }

    public function testMalformedSubBlockShapeIsIgnored(): void
    {
        // The payload sub-block must be exactly 0x03 long with id byte 0x01
        // (libnsgif data[13]==0x03 && data[14]==0x01).
        $wrongSize = "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x04\x01" . pack('v', 5) . "\xFF\x00";
        $wrongId   = "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x02" . pack('v', 5) . "\x00";
        $this->assertNull(Decoder::loopCount(self::stream($wrongSize)), 'sub-block size other than 3 is not a loop block');
        $this->assertNull(Decoder::loopCount(self::stream($wrongId)), 'sub-block id other than 1 is not a loop block');
    }

    public function testLastLoopBlockWins(): void
    {
        // libnsgif overwrites loop_max on every recognised block.
        $bytes = self::stream(self::loopBlock(2) . self::loopBlock(7));
        $this->assertSame(8, Decoder::loopCount($bytes));
        $backToForever = self::stream(self::loopBlock(7) . self::loopBlock(0));
        $this->assertNull(Decoder::loopCount($backToForever), 'a later raw-0 overwrites an earlier count');
    }

    public function testBlockIsFoundBehindGceAndFrameBlocks(): void
    {
        $gce = "\x21\xF9\x04\x04\x0A\x00\x00\x00"; // 0x21 0xF9 fixed 8-byte GCE, delay 10cs.
        $bytes = self::stream($gce . self::frameBlock() . $gce . self::loopBlock(1) . self::frameBlock());
        $this->assertSame(2, Decoder::loopCount($bytes));
    }

    public function testOtherApplicationExtensionsAreSkippedInSync(): void
    {
        // An XMP-style app extension with a LONG identifier sub-block and
        // multiple payload sub-blocks must not desync the walk off the loop
        // block that follows it. Identifier block: declared 40 bytes
        // ("XMP DataXMP" + 29 NULs), then two payload sub-blocks, then 0x00.
        $xmp = "\x21\xFF\x28" . 'XMP DataXMP' . str_repeat("\x00", 29)
            . "\x03\x01\x02\x03" . "\x01\xFE" . "\x00";
        $bytes = self::stream($xmp . self::loopBlock(4));
        $this->assertSame(5, Decoder::loopCount($bytes));
    }

    public function testTruncatedStreamsEndTheWalkQuietly(): void
    {
        $full = self::stream(self::loopBlock(3));
        // Chop the loop payload at every depth: no warnings may surface (the
        // suite runs failOnWarning), and whatever completed is what answers.
        for ($cut = 13; $cut < strlen($full); $cut++) {
            $loops = Decoder::loopCount(substr($full, 0, $cut));
            $this->assertTrue($loops === null || $loops === 4, 'a truncated walk reports only fully captured state at cut ' . $cut);
        }
    }

    public function testTruncatedGlobalColorTableReportsNullWithoutThrowing(): void
    {
        // GCT declared for 2 entries (6 bytes) but only 2 bytes present: the
        // block stream does not exist — answer infinite, do not raise.
        $bytes = 'GIF89a' . pack('v2C5', 4, 4, 0x80, 0, 0, 0, 0) . "\x00\x00";
        $this->assertNull(Decoder::loopCount($bytes));
    }

    public function testSignatureGateThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Decoder::loopCount('not a gif at all, but long long long');
    }

    public function testShortHeaderThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Decoder::loopCount('GIF89a');
    }

    public function testGif87aSignatureIsAccepted(): void
    {
        // The walk gate is the decode gate: both signatures (decodeBytes()
        // :118-121 parity), even though 87a predates app extensions.
        $this->assertSame(2, Decoder::loopCount(self::stream(self::loopBlock(1), 'GIF87a')));
    }

    public function testReadFileReturnsTheBytesDecodeConsumes(): void
    {
        // E-02 host-ingress seam: one read, two consumers (frames + loops).
        $path = tempnam(sys_get_temp_dir(), 'animloop');
        $this->assertIsString($path);
        try {
            file_put_contents($path, self::stream(self::loopBlock(2)));
            $bytes = Decoder::readFile($path);
            $this->assertSame(3, Decoder::loopCount($bytes));
        } finally {
            @unlink($path);
        }
    }

    public function testReadFileRefusesMissingPath(): void
    {
        $this->expectException(\RuntimeException::class);
        Decoder::readFile('/definitely/not/here/chafa-s7-3.gif');
    }

    public function testDecodeStillAgreesWithItsReadFileTwin(): void
    {
        // decode() was refactored to readFile()+decodeBytes(); the parity
        // pin proves the refactor kept the exact failure shape.
        $this->expectException(\RuntimeException::class);
        Decoder::decode('/definitely/not/here/chafa-s7-3.gif', 4, 4);
    }
}
