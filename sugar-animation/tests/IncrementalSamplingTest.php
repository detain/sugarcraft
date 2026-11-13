<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Frame;

/**
 * Pins for the incremental walk sampler (SA-D3 -013).
 *
 * The walk now carries a persistent cell grid and re-samples only the
 * cells whose windows intersect the canvas writes of an iteration (the
 * previous frame's DISPOSAL_BACKGROUND clear, the DISPOSAL_PREVIOUS full
 * restore, and the frame's own composited rect). These fixtures paint
 * SMALL rects on streams with cells far away from them, then pin every
 * corner of the grid: a dirty rect computed too narrow leaves a stale
 * cell exactly where the assertion bites — these are stale-cell
 * detectors, not smoke tests.
 *
 * The canvas-RGBA pin doubles as the oracle for the row-packed grab
 * rewrite: the expected byte string is spelled out here with the naive
 * per-pixel formula, byte for byte.
 */
final class IncrementalSamplingTest extends TestCase
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
     * Three frames, each painting a 2x2 corner on a 4x4 screen. Every
     * carried cell must keep the colour it was first sampled with and
     * every painted cell the fresh one — all sixteen pins per frame.
     */
    public function testCarriedCellsAfterPartialPaint(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Frame 0: solid red, opaque, full screen.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));

        // Frame 1: blue 2x2 bottom-right, rest transparent index.
        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 0));
        imagefilledrectangle($f1, 2, 2, 3, 3, imagecolorexact($f1, 0, 0, 255));

        // Frame 2: green 2x2 top-left, rest transparent index.
        $f2 = $this->newSharedPaletteImage();
        imagefilledrectangle($f2, 0, 0, 3, 3, imagecolorexact($f2, 0, 0, 0));
        imagefilledrectangle($f2, 0, 0, 1, 1, imagecolorexact($f2, 0, 255, 0));

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($this->gdGifBytes($f0), Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlock($this->gdGifBytes($f1), Frame::DISPOSAL_NONE, true, 0)
            . $this->composeFrameBlock($this->gdGifBytes($f2), Frame::DISPOSAL_NONE, true, 0)
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/incr-carry-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 4, cellsH: 4);
        $this->assertCount(3, $frames);

        $red = [255, 0, 0];
        $green = [0, 255, 0];
        $blue = [0, 0, 255];

        // Frame 1: the untouched far corner carries red, the painted corner
        // flips to blue, the two off-diagonal corners stay red.
        $this->assertSame($red, $frames[1]->cells[0][0], 'frame 1 far corner carries red');
        $this->assertSame($red, $frames[1]->cells[0][3], 'frame 1 top-right carries red');
        $this->assertSame($red, $frames[1]->cells[3][0], 'frame 1 bottom-left carries red');
        $this->assertSame($blue, $frames[1]->cells[3][3], 'frame 1 painted corner is blue');

        // Frame 2: green lands top-left while blue SURVIVES bottom-right —
        // a too-narrow dirty rect on this iteration is exactly what would
        // lose it.
        $this->assertSame($green, $frames[2]->cells[0][0], 'frame 2 painted corner is green');
        $this->assertSame($blue, $frames[2]->cells[3][3], 'frame 2 carries the blue from frame 1');
        $this->assertSame($red, $frames[2]->cells[0][3], 'frame 2 carries red');
        $this->assertSame($red, $frames[2]->cells[3][0], 'frame 2 carries red');
    }

    /**
     * A 1x1 paint whose pixel sits at the INNER EDGE of a 2x2 cell window
     * (canvas (1,1), cell (0,0) spans x0..1/y0..1). The intersecting cell
     * must be re-averaged — 3 red + 1 blue — while every other cell is
     * carried. The rounding is only reproducible if the intersection test
     * includes the touching edge; an off-by-one there leaves [255,0,0].
     */
    public function testStraddlingPaintReSamplesIntersectedCell(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));

        // A solid blue 4x4 whose LZW is re-declared as a 1x1 at (1,1):
        // the frame's first pixel is what GD yields at that extent.
        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 255));

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($this->gdGifBytes($f0), Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlockAt($this->gdGifBytes($f1), Frame::DISPOSAL_NONE, false, 0, 1, 1, 1, 1)
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/incr-straddle-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 2, cellsH: 2);
        $this->assertCount(2, $frames);

        $red = [255, 0, 0];
        $this->assertSame($red, $frames[0]->cells[0][0], 'frame 0 is solid red');
        $this->assertSame([191, 0, 64], $frames[1]->cells[0][0],
            'cell (0,0) re-averages 3 red + 1 blue (191.25→191, 63.75→64)');
        $this->assertSame($red, $frames[1]->cells[0][1], 'top-right carries');
        $this->assertSame($red, $frames[1]->cells[1][0], 'bottom-left carries');
        $this->assertSame($red, $frames[1]->cells[1][1], 'bottom-right carries');
    }

    /**
     * DISPOSAL_PREVIOUS restores the whole canvas, so EVERY cell is dirty
     * afterwards. The full-grid expectation below is the byte-for-byte
     * truth a full resample must yield; if the restore leg skipped
     * re-sampling, the restored half would stay green and this fails.
     */
    public function testDisposalPreviousRestoreResamplesFullGrid(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));

        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 0));
        imagefilledrectangle($f1, 0, 0, 1, 1, imagecolorexact($f1, 0, 255, 0));

        $f2 = $this->newSharedPaletteImage();
        imagefilledrectangle($f2, 0, 0, 3, 3, imagecolorexact($f2, 0, 0, 0));
        imagefilledrectangle($f2, 2, 2, 3, 3, imagecolorexact($f2, 0, 0, 255));

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($this->gdGifBytes($f0), Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlock($this->gdGifBytes($f1), Frame::DISPOSAL_PREVIOUS, true, 0)
            . $this->composeFrameBlock($this->gdGifBytes($f2), Frame::DISPOSAL_NONE, true, 0)
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/incr-restore-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = Decoder::decode($this->tmpPath, cellsW: 4, cellsH: 4);
        $this->assertCount(3, $frames);

        $red = [255, 0, 0];
        $blue = [0, 0, 255];
        $expected = [
            [$red, $red, $red, $red],
            [$red, $red, $red, $red],
            [$red, $red, $blue, $blue],
            [$red, $red, $blue, $blue],
        ];
        $this->assertSame($expected, $frames[2]->cells,
            'after the full restore the top-left half is red again and only the fresh paint is blue');
    }

    /**
     * Byte oracle for the row-packed canvas grab (SA-D3 -013): every pixel
     * of this stream's canvas is in one of four fully-known states (red,
     * green, blue, or never-painted transparent black), so the expected
     * RGBA payload is spelled out here with the naive per-pixel formula.
     * A row-chunking off-by-one or argument-order slip shifts or garbles
     * bytes and this assertSame fails.
     */
    public function testCanvasRgbaBytesMatchNaiveGrab(): void
    {
        if (extension_loaded('gd') === false) {
            $this->markTestSkipped('ext-gd not available');
        }

        // Frame 0 paints only the top half, so the bottom half of the
        // canvas stays the never-painted transparent black — exercising the
        // alpha byte 0 leg of the grab, not just the opaque leg.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 1, imagecolorexact($f0, 255, 0, 0));

        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 0, 0));
        imagefilledrectangle($f1, 2, 2, 3, 3, imagecolorexact($f1, 0, 0, 255));

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($this->gdGifBytes($f0), Frame::DISPOSAL_NONE, false, 0)
            . $this->composeFrameBlock($this->gdGifBytes($f1), Frame::DISPOSAL_NONE, true, 0)
            . "\x3B";

        $this->tmpPath = sys_get_temp_dir() . '/incr-rgba-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $gif);

        $frames = iterator_to_array(Decoder::decodeBytes(file_get_contents($this->tmpPath), 4, 4, true), preserve_keys: false);
        $this->assertCount(2, $frames);

        // Canvas truth per pixel: rows 0-1 red throughout; rows 2-3 are the
        // imagefill(transparentBg) leg — and that fill is a GD no-op on a
        // fresh truecolor canvas (fully-transparent brush over the default
        // black under blend), so the "never painted" band reads as OPAQUE
        // BLACK bytes here. Pinned as observed, not as the spec wishes.
        $expect = static function (int $r, int $g, int $b, int $a = 255): string {
            return chr($r) . chr($g) . chr($b) . chr($a);
        };

        $want0 = '';
        $want1 = '';
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $want0 .= $y < 2 ? $expect(255, 0, 0) : $expect(0, 0, 0);
                $painted = $y >= 2 && $x >= 2;
                $want1 .= $y < 2
                    ? $expect(255, 0, 0)
                    : ($painted ? $expect(0, 0, 255) : $expect(0, 0, 0));
            }
        }

        $this->assertSame($want0, $frames[0]->rgbaBytes(), 'frame 0 canvas bytes match the naive grab');
        $this->assertSame($want1, $frames[1]->rgbaBytes(), 'frame 1 canvas bytes match the naive grab');
    }

    // ------------------------------------------------------------------
    // Shared-palette fixture builders (same vocabulary as
    // DecoderCompositingTest — GD's own LZW bytes, indices consistent
    // across frames because the global colour table is shared).
    // ------------------------------------------------------------------

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
     * Build one frame block (GCE + Image Descriptor + LZW), reusing GD's
     * descriptor and LZW but clearing the local-table flag so the shared
     * global table applies.
     */
    private function composeFrameBlock(string $gd, int $disposal, bool $transparent, int $transparentIndex): string
    {
        [$id10, $lzw] = $this->extractDescriptorAndLzw($gd);
        $id = substr($id10, 0, 9) . "\x00";
        $gce = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . "\x01\x00" // delay = 1 centisecond
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";    // GCE block terminator
        return $gce . $id . $lzw;
    }

    /**
     * Like {@see composeFrameBlock()}, but the Image Descriptor is spelled
     * out fresh at an arbitrary (left, top) rectangle instead of reused
     * from GD's (0,0) placement. Sizes stay below 256, high bytes zero.
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
            . "\x00";
        $gce = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . "\x01\x00"
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";
        return $gce . $id . $lzw;
    }

    /**
     * Pull the 10-byte Image Descriptor and the LZW payload (min-code byte
     * + sub-blocks + terminator) out of a GD single-frame GIF.
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
                    $i += 8;
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
}
