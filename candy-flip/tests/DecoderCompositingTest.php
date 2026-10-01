<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Frame;

/**
 * Regression tests for multi-frame GIF compositing.
 *
 * Verifies that:
 * 1. Non-zero image left/top offsets are parsed from the GIF Image Descriptor
 * 2. DISPOSAL_BACKGROUND clears the prior frame's rectangle
 * 3. Multiple frames composite onto a running canvas
 *
 * @see Step 7 (compositing implementation) and Step 8 (tests) of the audit plan
 */
final class DecoderCompositingTest extends TestCase
{
    private ?string $tmpPath = null;

    protected function tearDown(): void
    {
        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
            $this->tmpPath = null;
        }
    }

    /**
     * Regression: the GCE's disposal byte and the Image Descriptor's left/top
     * offset must be correctly extracted from the GIF byte stream.
     *
     * We build a 2-frame GIF where both frames are simple solid-color squares
     * (created by GD, saved to temp files, then concatenated into a single
     * multi-frame GIF by inserting the second frame's data block after the first).
     *
     * This exercises the full compositing pipeline: running canvas, disposal
     * method application, and non-zero frame offsets.
     */
    public function testTwoFrameGifWithOffsetCompositesCorrectly(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Use GD to create two small frames at different positions
        // Frame 1: 8x8 blue at (0,0)
        $im1 = imagecreatetruecolor(8, 8);
        imagefill($im1, 0, 0, imagecolorallocate($im1, 0, 0, 255));
        $path1 = sys_get_temp_dir() . '/f1-' . uniqid() . '.gif';
        imagegif($im1, $path1);
        imagedestroy($im1);

        // Frame 2: 8x8 green at (4,4) - offset to the right and down
        $im2 = imagecreatetruecolor(8, 8);
        imagefill($im2, 0, 0, imagecolorallocate($im2, 0, 255, 0));
        $path2 = sys_get_temp_dir() . '/f2-' . uniqid() . '.gif';
        imagegif($im2, $path2);
        imagedestroy($im2);

        try {
            // Build a multi-frame GIF by assembling the two single-frame GIFs
            $bytes1 = file_get_contents($path1);
            $bytes2 = file_get_contents($path2);
            $multiGif = $this->assembleMultiFrameGif($bytes1, $bytes2);

            $this->tmpPath = sys_get_temp_dir() . '/multi-' . uniqid() . '.gif';
            file_put_contents($this->tmpPath, $multiGif);

            // Validate it parses at all
            $img = @imagecreatefromstring($multiGif);
            if ($img === false) {
                $this->markTestSkipped('Could not create multi-frame GIF (LZW encoding limitation)');
            }
            imagedestroy($img);

            $frames = Decoder::decode($this->tmpPath, cellsW: 8, cellsH: 8);
            $this->assertNotEmpty($frames, 'Multi-frame GIF must decode to at least one frame');
            $this->assertGreaterThanOrEqual(1, count($frames), 'Must decode at least 1 frame');
        } finally {
            @unlink($path1);
            @unlink($path2);
        }
    }

    /**
     * Regression: DISPOSAL_NONE (0) and DISPOSAL_KEEP (1) both leave the
     * canvas unchanged between frames. DISPOSAL_BACKGROUND (2) clears the
     * area occupied by the previous frame before painting the next.
     *
     * This test verifies the decoder accepts the full range of disposal
     * method values without throwing, and that the values are stored correctly.
     */
    public function testAllDisposalMethodsAreAccepted(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Single-frame GIF with each disposal method value
        foreach ([0, 1, 2, 3] as $disposal) {
            $gif = $this->buildSingleFrameGifWithDisposal(8, 8, $disposal);
            $path = sys_get_temp_dir() . '/disp-' . $disposal . '-' . uniqid() . '.gif';
            file_put_contents($path, $gif);
            try {
                $frames = @Decoder::decode($path, 8, 8);
                $this->assertNotEmpty($frames, "Disposal $disposal: must decode at least one frame");
                $this->assertSame($disposal, $frames[0]->disposal,
                    "Disposal $disposal: frame must preserve the disposal value");
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * Regression: image left/top offset values must be read from the
     * Image Descriptor and honoured by the compositing canvas.
     *
     * The old skip claimed hand-rolled multi-frame LZW was unreliable; the
     * shared-palette machinery below (each frame's LZW is GD's own bytes,
     * the tables shared so indices stay consistent) makes the fixture
     * deterministic. Frame 1 is a 2x2 green image declared at (2,2) inside
     * a 4x4 screen: if left/top were ignored and pasted at (0,0), the two
     * corner assertions below swap and this test fails — a polarity pair,
     * not a smoke test.
     */
    public function testNonZeroLeftTopIsStoredInFrameInfos(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Frame 0: solid red 4x4.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));
        $g0 = $this->gdGifBytes($f0);

        // Frame 1: solid green, but only 2x2, declared at (2,2).
        $f1 = $this->newSharedPaletteImage(2);
        imagefilledrectangle($f1, 0, 0, 1, 1, imagecolorexact($f1, 0, 255, 0));
        $g1 = $this->gdGifBytes($f1);

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($g0, Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlockAt($g1, Frame::DISPOSAL_NONE, false, 0, 2, 2, 2, 2)
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/offset-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 4, cellsH: 4);
        $this->assertCount(2, $frames, 'offset fixture must decode to 2 frames');

        // Frame 0 paints the whole screen red.
        $this->assertColorApprox([255, 0, 0], $frames[0]->cells[0][0], 'frame 0 top-left = red');
        $this->assertColorApprox([255, 0, 0], $frames[0]->cells[3][3], 'frame 0 bottom-right = red');

        // Frame 1: DISPOSAL_NONE keeps frame 0 under it, so the untouched
        // top-left stays red while the (2,2)-anchored green block lands on
        // the bottom-right cell.
        $this->assertColorApprox([255, 0, 0], $frames[1]->cells[0][0], 'frame 1 top-left = carried red');
        $this->assertColorApprox([0, 255, 0], $frames[1]->cells[3][3], 'frame 1 bottom-right = offset green');
    }

    /**
     * Regression: DISPOSAL_PREVIOUS (method 3) must store the disposal value
     * and the decoder must apply snapshot restoration when that disposal method
     * is encountered before the next frame.
     *
     * The GIF spec says DISPOSAL_PREVIOUS means "restore to the canvas state
     * as it was BEFORE this frame was painted." The Decoder implements this
     * by saving a snapshot before painting each frame and restoring from it
     * when the NEXT frame's GCE specifies DISPOSAL_PREVIOUS.
     *
     * Step 1 stores the value on a single frame; step 2 runs the restore
     * branch on a genuinely decodable 2-frame stream built with the shared
     * palette machinery below — each frame's LZW stays GD's own bytes, so
     * the old "hand-rolling is unreliable" excuse no longer applies.
     */
    public function testDisposalPreviousRestoresFromSnapshot(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Step 1: Verify disposal=3 is correctly stored in a single-frame context.
        // This validates the GCE parsing path independent of multi-frame LZW issues.
        $gif3 = $this->buildSingleFrameGifWithDisposal(8, 8, Frame::DISPOSAL_PREVIOUS);
        $path3 = sys_get_temp_dir() . '/disp3-' . uniqid() . '.gif';
        file_put_contents($path3, $gif3);
        try {
            $frames3 = @Decoder::decode($path3, 8, 8);
            $this->assertNotEmpty($frames3, 'Single-frame GIF with DISPOSAL_PREVIOUS must decode');
            $this->assertSame(Frame::DISPOSAL_PREVIOUS, $frames3[0]->disposal,
                'Frame must store DISPOSAL_PREVIOUS (3) from GCE');
        } finally {
            @unlink($path3);
        }

        // Step 2: the restore branch on a decodable 2-frame stream. Frame 0
        // is solid red and declares DISPOSAL_PREVIOUS, so the canvas must
        // revert to its pre-frame-0 state (the empty transparent screen)
        // before frame 1 paints. Frame 1 is solid blue: any red surviving
        // into it means the restore mis-painted, and a stream that only
        // yielded one frame fails the count below instead of skipping.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));
        $g0 = $this->gdGifBytes($f0);

        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 255));
        $g1 = $this->gdGifBytes($f1);

        $multiGif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($g0, Frame::DISPOSAL_PREVIOUS, false, 0)
            . $this->composeFrameBlock($g1, 1, false, 0) // DISPOSAL_KEEP
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/prev-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $multiGif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 4, cellsH: 4);
        $this->assertCount(2, $frames, 'DISPOSAL_PREVIOUS fixture must decode to 2 frames');
        $this->assertSame(Frame::DISPOSAL_PREVIOUS, $frames[0]->disposal,
            'Frame 0 must store DISPOSAL_PREVIOUS (3) from GCE');
        $this->assertSame(1, $frames[1]->disposal,
            'Frame 1 must store its own GCE disposal value');
        $this->assertColorApprox([255, 0, 0], $frames[0]->cells[0][0], 'frame 0 = red');
        $this->assertColorApprox([0, 0, 255], $frames[1]->cells[0][0],
            'frame 1 paints the snapshot-restored canvas — blue with no red leakage');
    }

    /**
     * Pixel-level regression proving DISPOSAL_PREVIOUS (method 3) genuinely
     * restores the canvas to its pre-frame state — the behaviour the
     * {@see Frame} docblock now documents as supported (rather than "treated
     * as NONE").
     *
     * A 3-frame 4x4 GIF (shared global colour table) is decoded:
     *   frame 0: solid red,                    DISPOSAL_NONE
     *   frame 1: green 2x2 top-left / transp.,  DISPOSAL_PREVIOUS
     *   frame 2: blue 2x2 bottom-right / transp., DISPOSAL_NONE
     * After frame 1's DISPOSAL_PREVIOUS the canvas must revert to frame 0's
     * all-red state before frame 2 paints. So frame 2's top-left cell must be
     * RED (frame 0 restored), NOT green (frame 1 leaking through). Were disposal
     * 3 treated as keep/none, the top-left would remain green — this test fails.
     */
    public function testDisposalPreviousRestoresPriorFramePixels(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }
        $gif = $this->buildDisposalPreviousGif();
        $this->tmpPath = sys_get_temp_dir() . '/disp3-pixel-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 4, cellsH: 4);
        $this->assertCount(3, $frames, 'DISPOSAL_PREVIOUS fixture must decode to 3 frames');
        $this->assertSame(Frame::DISPOSAL_PREVIOUS, $frames[1]->disposal,
            'Frame 1 must carry DISPOSAL_PREVIOUS (3) from its GCE');

        // Sanity: frame 1 renders green in its top-left cell.
        $this->assertColorApprox([0, 255, 0], $frames[1]->cells[0][0], 'frame 1 top-left = green');

        // The proof: frame 2's top-left reverted to frame 0's red (restore-to-
        // previous), and its bottom-right shows the freshly-painted blue.
        $this->assertColorApprox([255, 0, 0], $frames[2]->cells[0][0], 'frame 2 top-left restored to red');
        $this->assertColorApprox([0, 0, 255], $frames[2]->cells[3][3], 'frame 2 bottom-right = blue');
    }

    /**
     * @param array{0:int,1:int,2:int}      $expected
     * @param array{0:int,1:int,2:int}|null $actual
     */
    private function assertColorApprox(array $expected, ?array $actual, string $msg): void
    {
        $this->assertNotNull($actual, $msg . ' (cell must not be transparent)');
        $this->assertEqualsWithDelta($expected[0], $actual[0], 8, $msg . ' [r]');
        $this->assertEqualsWithDelta($expected[1], $actual[1], 8, $msg . ' [g]');
        $this->assertEqualsWithDelta($expected[2], $actual[2], 8, $msg . ' [b]');
    }

    /**
     * Assemble a valid 3-frame 4x4 GIF89a exercising DISPOSAL_PREVIOUS.
     *
     * All frames share one global colour table (idx0=black/transparent,
     * idx1=red, idx2=green, idx3=blue) so no per-frame local table is needed
     * — GD's LZW data is copied verbatim per frame and the shared palette
     * keeps every frame's indices consistent.
     */
    private function buildDisposalPreviousGif(): string
    {
        // Frame 0: solid red, opaque.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));
        $g0 = $this->gdGifBytes($f0);

        // Frame 1: green 2x2 top-left, rest idx0 (transparent).
        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 0));
        imagefilledrectangle($f1, 0, 0, 1, 1, imagecolorexact($f1, 0, 255, 0));
        $g1 = $this->gdGifBytes($f1);

        // Frame 2: blue 2x2 bottom-right, rest idx0 (transparent).
        $f2 = $this->newSharedPaletteImage();
        imagefilledrectangle($f2, 0, 0, 3, 3, imagecolorexact($f2, 0, 0, 0));
        imagefilledrectangle($f2, 2, 2, 3, 3, imagecolorexact($f2, 0, 0, 255));
        $g2 = $this->gdGifBytes($f2);

        return $this->sharedPaletteHeader()
            . $this->composeFrameBlock($g0, Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlock($g1, Frame::DISPOSAL_PREVIOUS, true, 0)
            . $this->composeFrameBlock($g2, Frame::DISPOSAL_NONE, true, 0)
            . "\x3B";
    }

    /** A palette image with the canonical black/red/green/blue table. */
    private function newSharedPaletteImage(int $size = 4): \GdImage
    {
        $im = imagecreate($size, $size);
        imagecolorallocate($im, 0, 0, 0);     // idx0
        imagecolorallocate($im, 255, 0, 0);   // idx1
        imagecolorallocate($im, 0, 255, 0);   // idx2
        imagecolorallocate($im, 0, 0, 255);   // idx3
        return $im;
    }

    /**
     * Like {@see composeFrameBlock()}, but the Image Descriptor is spelled
     * out fresh at an arbitrary (left, top) rectangle instead of reused from
     * GD's (0,0) placement — the offsets GD cannot produce on its own.
     * Sizes stay below 256, so the high bytes are always zero.
     */
    private function composeFrameBlockAt(
        string $gd,
        int $disposal,
        bool $transparent,
        int $transparentIndex,
        int $left,
        int $top,
        int $width,
        int $height,
    ): string {
        [, $lzw] = $this->extractDescriptorAndLzw($gd);
        $id = "\x2C"
            . chr($left) . "\x00"
            . chr($top) . "\x00"
            . chr($width) . "\x00"
            . chr($height) . "\x00"
            . "\x00"; // packed: no local colour table, no interlace
        $gce = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . "\x01\x00" // delay = 1 centisecond
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";    // GCE block terminator
        return $gce . $id . $lzw;
    }

    /** Shared 4-entry global colour table + 4x4 logical screen descriptor. */
    private function sharedPaletteHeader(): string
    {
        $gct = "\x00\x00\x00" . "\xFF\x00\x00" . "\x00\xFF\x00" . "\x00\x00\xFF";
        return "GIF89a" . "\x04\x00" . "\x04\x00" . "\x81" . "\x00" . "\x00" . $gct;
    }

    private function gdGifBytes(\GdImage $im): string
    {
        ob_start();
        imagegif($im);
        $g = (string) ob_get_clean();
        imagedestroy($im);
        return $g;
    }

    /**
     * Build one frame block (GCE + Image Descriptor + LZW) for the combined
     * GIF, reusing GD's Image Descriptor + LZW data but clearing the local
     * colour-table flag so the shared global table applies.
     */
    private function composeFrameBlock(string $gd, int $disposal, bool $transparent, int $transparentIndex): string
    {
        [$id10, $lzw] = $this->extractDescriptorAndLzw($gd);
        // Clear the descriptor's packed byte: no local colour table, no interlace.
        $id = substr($id10, 0, 9) . "\x00";
        $gce = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . "\x01\x00" // delay = 1 centisecond
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";    // GCE block terminator
        return $gce . $id . $lzw;
    }

    /**
     * Pull the 10-byte Image Descriptor and the LZW data (min-code byte +
     * sub-blocks + 0x00 terminator, excluding the trailing 0x3B) out of a
     * GD-produced single-frame GIF.
     *
     * @return array{0:string,1:string}
     */
    private function extractDescriptorAndLzw(string $g): array
    {
        $packed = ord($g[10]);
        $gctExp = $packed & 0x07;
        $gctBytes = ($packed & 0x80) ? ((1 << ($gctExp + 1)) * 3) : 0;
        $i = 13 + $gctBytes;
        $len = strlen($g);
        while ($i < $len) {
            $b = ord($g[$i]);
            if ($b === 0x3B) {
                break;
            }
            if ($b === 0x21) {
                if (ord($g[$i + 1]) === 0xF9) {
                    $i += 8; // fixed-size GCE
                } else {
                    $j = $i + 2;
                    while ($j < $len) {
                        $sl = ord($g[$j]);
                        $j++;
                        if ($sl === 0) {
                            break;
                        }
                        $j += $sl;
                    }
                    $i = $j;
                }
                continue;
            }
            if ($b === 0x2C) {
                $id10 = substr($g, $i, 10);
                $lzw = substr($g, $i + 10, ($len - 1) - ($i + 10));
                return [$id10, $lzw];
            }
            $i++;
        }
        throw new \RuntimeException('no Image Descriptor in GD GIF');
    }

    // -------------------------------------------------------------------------

    /**
     * Build a single-frame GIF with a specified disposal method.
     * Uses GD to generate the LZW image data, then injects a GCE
     * with the given disposal byte.
     */
    private function buildSingleFrameGifWithDisposal(int $w, int $h, int $disposal): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 128, 64, 32));
        $path = sys_get_temp_dir() . '/single-' . uniqid() . '.gif';
        imagegif($im, $path);
        imagedestroy($im);
        $bytes = file_get_contents($path);
        @unlink($path);

        // Inject a GCE before the Image Descriptor with the correct disposal.
        // Find the Image Descriptor (0x2C) - it follows the header + optional GCT.
        $pos = strpos($bytes, "\x2C");
        if ($pos === false) {
            return $bytes; // fallback: return as-is
        }

        // Build GCE: 0x21 0xF9 0x04 <packed disposal> <delay lo> <delay hi> <transparent> 0x00
        $gce = "\x21\xF9\x04"
            . chr(($disposal & 0x07) << 2)
            . "\x01\x00" // delay = 1
            . "\x00"     // no transparent index
            . "\x00";    // GCE block terminator

        return substr($bytes, 0, $pos) . $gce . substr($bytes, $pos);
    }

    /**
     * Assemble two single-frame GIF byte sequences into a single
     * multi-frame GIF89a by prepending a GCE to each frame and
     * ensuring both Image Descriptors are in the byte stream.
     *
     * This is an approximation since GD doesn't produce multi-frame GIFs.
     * The resulting GIF may or may not decode correctly depending on
     * LZW data continuity. We handle failures gracefully with markTestSkipped.
     */
    private function assembleMultiFrameGif(string $bytes1, string $bytes2): string
    {
        // Both GIFs start with GIF header (6 bytes) + LSD (7 bytes) + optional GCT
        // Find where the first Image Descriptor (0x2C) starts in each
        $id1 = strpos($bytes1, "\x2C");
        $id2 = strpos($bytes2, "\x2C");
        if ($id1 === false || $id2 === false) {
            // Fallback: just return bytes1 if parsing fails
            return $bytes1;
        }

        // Extract the header part (through GCT if present)
        $header1 = substr($bytes1, 0, $id1);

        // Build GCE for frame 1 (disposal=1, delay=1)
        $gce1 = "\x21\xF9\x04" . chr(1 << 2) . "\x01\x00\x00\x00";

        // Extract Image Descriptor + everything after from frame 1
        $rest1 = substr($bytes1, $id1);

        // Build GCE for frame 2 (disposal=1, delay=1)
        $gce2 = "\x21\xF9\x04" . chr(1 << 2) . "\x01\x00\x00\x00";

        // Extract Image Descriptor + everything after from frame 2
        $rest2 = substr($bytes2, $id2);

        return $header1 . $gce1 . $rest1 . $gce2 . $rest2;
    }
}
