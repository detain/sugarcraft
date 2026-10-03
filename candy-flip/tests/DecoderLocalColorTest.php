<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Decoder;
use PHPUnit\Framework\TestCase;

/**
 * Per-frame Local Color Tables.
 *
 * Every fixture here is written byte by byte (descriptor, LCT, a spec-valid
 * LZW stream) rather than by GD, because GD's encoder never emits a local
 * table — a GD-built fixture cannot exercise the LCT path at all.
 *
 * Regression: `Decoder::assembleFrameGif()` used to write a frame's LCT
 * BEFORE its Image Descriptor. GIF89a puts the LCT after the descriptor (the
 * descriptor's packed byte is what announces it), so GD read palette bytes
 * where it expected a block introducer. Depending on those palette bytes GD
 * either rejected the frame (flip dropped it, down to zero frames) or decoded
 * it with garbage colours — which is why these tests pin exact cell colours,
 * not just frame counts.
 */
final class DecoderLocalColorTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        $this->temp = [];
    }

    /**
     * A frame's LCT overrides the GCT: index 1 is red globally but green
     * locally, so every cell must come out green.
     */
    public function testLocalColorTableOverridesGlobalTable(): void
    {
        $gif = $this->gif(4, 4, gct: [[0, 0, 0], [255, 0, 0]], frames: [
            ['lct' => [[0, 0, 0], [0, 255, 0]], 'pixels' => array_fill(0, 16, 1)],
        ]);

        $frames = Decoder::decode($this->write($gif), 2, 2);

        self::assertCount(1, $frames, 'a single-frame GIF with an LCT must decode to one frame');
        self::assertSame([[[0, 255, 0], [0, 255, 0]], [[0, 255, 0], [0, 255, 0]]], $frames[0]->cells);
    }

    /**
     * No global table at all — each frame draws from its own palette, and the
     * same pixel index resolves to a different colour per frame.
     */
    public function testEveryFrameDecodesThroughItsOwnLocalTable(): void
    {
        $gif = $this->gif(2, 2, gct: null, frames: [
            ['lct' => [[255, 0, 0], [0, 0, 0]], 'pixels' => [0, 0, 0, 0]],
            ['lct' => [[0, 0, 255], [0, 0, 0]], 'pixels' => [0, 0, 0, 0]],
            ['lct' => [[10, 20, 30], [200, 100, 50]], 'pixels' => [1, 1, 1, 1]],
        ]);

        $frames = Decoder::decode($this->write($gif), 2, 2);

        self::assertCount(3, $frames, 'flip must not drop frames that carry a local colour table');
        self::assertSame([255, 0, 0], $frames[0]->cells[0][0]);
        self::assertSame([0, 0, 255], $frames[1]->cells[0][0]);
        self::assertSame([200, 100, 50], $frames[2]->cells[1][1]);
    }

    /**
     * Frames with and without an LCT interleave in one stream, the LCT frame is
     * offset inside the logical screen and its table is larger than 2 entries
     * (8 entries / 24 bytes) — the descriptor's left/top and the LCT size must
     * both survive re-assembly, and the GCT-only frames around it must not be
     * disturbed.
     */
    public function testMixedGlobalAndLocalFramesWithOffsetAndWideTable(): void
    {
        $lct = [[0, 0, 0], [1, 1, 1], [2, 2, 2], [3, 3, 3], [4, 4, 4], [5, 5, 5], [6, 6, 6], [0, 200, 200]];
        $gif = $this->gif(4, 4, gct: [[255, 0, 0], [0, 0, 0]], frames: [
            ['pixels' => array_fill(0, 16, 0)],
            ['lct' => $lct, 'left' => 2, 'top' => 2, 'w' => 2, 'h' => 2, 'pixels' => [7, 7, 7, 7]],
            ['pixels' => array_fill(0, 16, 1)],
        ]);

        $frames = Decoder::decode($this->write($gif), 4, 4);

        self::assertCount(3, $frames);
        self::assertSame([255, 0, 0], $frames[0]->cells[3][3]);
        // Frame 2 paints only its 2x2 rect at (2,2); the rest is frame 1's red.
        self::assertSame([255, 0, 0], $frames[1]->cells[0][0]);
        self::assertSame([0, 200, 200], $frames[1]->cells[2][2]);
        self::assertSame([0, 200, 200], $frames[1]->cells[3][3]);
        self::assertSame([0, 0, 0], $frames[2]->cells[2][2]);
    }

    /**
     * Byte-level pin on the re-assembled single-frame GIF: GCE, then the
     * 10-byte descriptor, then the LCT, then the LZW data — the order GIF89a
     * mandates.
     */
    public function testAssembledFrameWritesDescriptorBeforeLocalTable(): void
    {
        $lct = [[0, 255, 0], [9, 8, 7]];
        $gif = $this->gif(2, 2, gct: null, frames: [['lct' => $lct, 'pixels' => [1, 0, 0, 1]]]);

        $parse = new \ReflectionMethod(Decoder::class, 'parseHeader');
        $header = $parse->invoke(null, $gif);
        $info = $header['frameInfos'][0];
        $assemble = new \ReflectionMethod(Decoder::class, 'assembleFrameGif');
        $out = $assemble->invoke(null, $gif, $info, $header);

        // 13-byte header (no GCT) + 8-byte GCE.
        $descriptorAt = 13 + 8;
        self::assertSame("\x2C", $out[$descriptorAt], 'the Image Descriptor must directly follow the GCE');
        self::assertSame(substr($gif, $info['offset'], 10), substr($out, $descriptorAt, 10));
        self::assertSame("\x00\xFF\x00\x09\x08\x07", substr($out, $descriptorAt + 10, 6), 'the LCT must follow the descriptor');
        self::assertSame("\x03", $out[$descriptorAt + 16], 'LZW minimum code size must follow the LCT');
        self::assertNotFalse(@imagecreatefromstring($out), 'GD must accept the re-assembled frame');
    }

    /**
     * @param list<array{int, int, int}>|null $gct
     * @param list<array{pixels: list<int>, lct?: list<array{int, int, int}>, left?: int, top?: int, w?: int, h?: int}> $frames
     */
    private function gif(int $w, int $h, ?array $gct, array $frames): string
    {
        $buf = 'GIF89a' . pack('v', $w) . pack('v', $h);
        $buf .= $gct === null ? "\x00" : chr(0x80 | $this->tableSizeBits(count($gct)));
        $buf .= "\x00\x00";
        if ($gct !== null) {
            $buf .= $this->table($gct);
        }
        foreach ($frames as $f) {
            // GCE: no disposal, 100ms delay, no transparency.
            $buf .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
            $buf .= "\x2C" . pack('v', $f['left'] ?? 0) . pack('v', $f['top'] ?? 0)
                . pack('v', $f['w'] ?? $w) . pack('v', $f['h'] ?? $h);
            if (isset($f['lct'])) {
                $buf .= chr(0x80 | $this->tableSizeBits(count($f['lct'])));
                $buf .= $this->table($f['lct']);
            } else {
                $buf .= "\x00";
            }
            $buf .= $this->lzw($f['pixels']);
        }

        return $buf . "\x3B";
    }

    private function tableSizeBits(int $entries): int
    {
        // Entry count is 2^(n+1); the fixtures use exact powers of two.
        return (int) log($entries, 2) - 1;
    }

    /** @param list<array{int, int, int}> $entries */
    private function table(array $entries): string
    {
        $out = '';
        foreach ($entries as [$r, $g, $b]) {
            $out .= chr($r) . chr($g) . chr($b);
        }
        return $out;
    }

    /**
     * Spec-valid LZW image data at minimum code size 3 (indices 0..7; clear=8,
     * end=9, 4-bit codes). A clear code before every literal means no string
     * table entry ever pushes the width past 4 bits, so the stream needs no
     * real compressor yet decodes in any conforming reader.
     *
     * @param list<int> $pixels
     */
    private function lzw(array $pixels): string
    {
        $codes = [];
        foreach ($pixels as $p) {
            $codes[] = 8;
            $codes[] = $p;
        }
        $codes[] = 9;

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

    private function write(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/flip-lct-' . uniqid('', true) . '.gif';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;
        return $path;
    }
}
