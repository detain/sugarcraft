<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;

/**
 * E-01: bound GIF frame descriptors.
 *
 * The MAX_TOTAL_PIXELS header gate sizes the compositing canvas, but a
 * per-frame Image Descriptor used to be trusted verbatim: frameW/frameH/
 * left/top were recorded by parseHeader() and never compared against the
 * logical screen, and assembleFrameGif() copied the declared bytes
 * straight into the payload handed to imagecreatefromstring(). Browsers
 * clip frame rects to the canvas; so does the decoder now — a partly
 * off-canvas frame is stored (and re-emitted) clipped, a fully off-canvas
 * or zero-extent one is dropped from the walk while its sub-blocks are
 * still consumed. The hostile shape pinned here is a 65535x65535
 * descriptor inside a 4x4 screen; the end-to-end test carries its own
 * wall-clock and memory-delta budgets because the lib ships no
 * alarm/timeout idiom and memory_get_peak_usage() is process-global —
 * the cap measures the decode's rise over the phpunit boot peak.
 * (Grounding note from the lane probe: this box's GD silently clamps
 * per-frame dims to the LSD, which is why the bypass never visibly
 * OOMed here — relying on an external component's mercy is exactly what
 * the fix removes; pre-fix the same file decoded to ZERO frames, and the
 * `count === 1` arm below is the red-before proof.)
 */
final class DecoderHugeDescriptorTest extends TestCase
{
    private const WALL_BUDGET_MS = 100;
    private const MEM_DELTA_BUDGET_MB = 100;

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->temp = [];
    }

    public function testParseHeaderClipsFrameRectsToTheScreenAndDropsFullyOutsideOnes(): void
    {
        // Screen 4x4.  Frame 1 fits (1x1 at 0,0).  Frame 2 declares
        // 65535x65535 at (2,2) — visible part is 2x2.  Frame 3 sits entirely
        // to the right of the canvas (left=8) and must not join the list,
        // yet frame 4 (after it) must still be parsed: dropping a frame
        // without consuming its sub-blocks would desynchronise the walk.
        $bytes = $this->framesGif(4, 4, [
            [0, 0, 1, 1],
            [2, 2, 65535, 65535],
            [8, 0, 1, 1],
            [0, 3, 4, 1],
        ]);
        $parse = new \ReflectionMethod(Decoder::class, 'parseHeader');
        $header = $parse->invoke(null, $bytes);

        $rects = array_map(
            static fn (array $info): array => [$info['left'], $info['top'], $info['frameW'], $info['frameH']],
            $header['frameInfos'],
        );
        self::assertSame(
            [[0, 0, 1, 1], [2, 2, 2, 2], [0, 3, 4, 1]],
            $rects,
            'hugest frame clips to the screen remainder, off-canvas frame is skipped, walk stays synced',
        );
    }

    public function testAssembledFrameDescriptorCarriesTheClippedRectNotTheDeclaredOne(): void
    {
        $bytes = $this->framesGif(4, 4, [[2, 2, 65535, 65535]]);
        $parse = new \ReflectionMethod(Decoder::class, 'parseHeader');
        $header = $parse->invoke(null, $bytes);
        $info = $header['frameInfos'][0];

        $assemble = new \ReflectionMethod(Decoder::class, 'assembleFrameGif');
        $out = $assemble->invoke(null, $bytes, $info, ['hasGct' => true, 'gctBytes' => 12]);

        // Payload layout: 13 header + 12 GCT + 8 GCE + 10 descriptor.
        self::assertSame(
            "\x2C" . pack('v', 2) . pack('v', 2) . pack('v', 2) . pack('v', 2) . "\x00",
            substr($out, 13 + 12 + 8, 10),
            'GD must be handed the clipped 2x2 rect, never the declared 65535 squared',
        );
    }

    public function testHostileHugeDescriptorDecodesClippedOnASelfGuardedBudget(): void
    {
        if (extension_loaded('gd') === false) {
            self::markTestSkipped('ext-gd not available');
        }
        // 4x4 screen, one frame declaring 65535x65535 at (0,0) whose LZW
        // really paints the screen's 16 pixels in index 1 (red).  Pre-E-01
        // this decoded to [] (the declared-size payload was rejected);
        // post-fix the descriptor is re-emitted clipped to 4x4, GD accepts,
        // the red lands — and the budgets prove no giant allocation is ever
        // attempted on the way.
        $bytes = $this->framesGif(4, 4, [[0, 0, 65535, 65535]], 16);
        $path = sys_get_temp_dir() . '/flip-e01-' . uniqid('', true) . '.gif';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        $peakBefore = memory_get_peak_usage(true);
        $t0 = hrtime(true);
        $frames = Decoder::decode($path, 2, 2);
        $wallMs = (int) ((hrtime(true) - $t0) / 1_000_000);
        $deltaMb = (int) ((memory_get_peak_usage(true) - $peakBefore) / 1_048_576);

        self::assertCount(1, $frames, 'the clipped frame must decode, not be silently dropped');
        self::assertSame([255, 0, 0], $frames[0]->cells[0][0] ?? null, 'the red fill must survive the clip');
        self::assertLessThan(self::WALL_BUDGET_MS, $wallMs, "decode took {$wallMs} ms");
        self::assertLessThan(self::MEM_DELTA_BUDGET_MB, $deltaMb, "decode peaked {$deltaMb} MB over boot");
    }

    /**
     * House builder (DecoderLocalColorTest::gif convention, own copy): GIF89a
     * with a 4-entry GCT (black, red, green, blue) and one GCE+descriptor
     * pair per rect.  Packed byte 0x00 — no LCT, no interlace.
     *
     * @param list<array{0:int,1:int,2:int,3:int}> $rects  left, top, w, h (declared)
     * @param int $pixelCount  pixels of LZW each frame's data carries
     */
    private function framesGif(int $screenW, int $screenH, array $rects, int $pixelCount = 4): string
    {
        $buf = 'GIF89a' . pack('v', $screenW) . pack('v', $screenH) . "\x81\x00\x00"
            . "\x00\x00\x00\xff\x00\x00\x00\xff\x00\x00\x00\xff";
        foreach ($rects as [$left, $top, $w, $h]) {
            // GCE: no disposal, 100ms delay, no transparency.
            $buf .= "\x21\xF9\x04\x00\x0A\x00\x00\x00"
                . "\x2C" . pack('v', $left) . pack('v', $top) . pack('v', $w) . pack('v', $h) . "\x00"
                . $this->clearCodeLzw($pixelCount);
        }

        return $buf . "\x3B";
    }

    /**
     * Spec-valid LZW at minimum code size 3 (clear=8, end=9, 4-bit codes); a
     * clear code before every literal index 1 keeps the table width at 4
     * bits, so the stream needs no real compressor yet decodes in any
     * conforming reader.
     */
    private function clearCodeLzw(int $pixels): string
    {
        $codes = [];
        for ($i = 0; $i < $pixels; $i++) {
            $codes[] = 8;
            $codes[] = 1;
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
}
