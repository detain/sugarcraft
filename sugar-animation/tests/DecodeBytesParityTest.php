<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Frame;

/**
 * E-07: the bytes-ingress seam must be exactly as faithful as the path it
 * sits under. decode($path) is now defined as read-then-decodeBytes, so the
 * two routes must agree frame-for-frame on every observable; and the
 * canvas RGBA capture (withCanvasRgba) must reproduce — byte for byte — the
 * RGBA the old consumers reconstruct from the cell grid, at a 1:1 sampling
 * grid where cell and pixel coincide.
 *
 * The rgba-order legs are the mutation target for S6.3 review: swapping the
 * channel order or the alpha polarity of the capture reddens them while
 * every digest downstream (sugar-image, sugar-video) stays silent — which
 * is exactly why they are pinned here rather than trusted.
 *
 * @covers \SugarCraft\Animation\Decoder
 */
final class DecodeBytesParityTest extends TestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            @unlink($path);
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    private function requireGd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
    }

    /** Solid single-frame GIF bytes written through a temp path (the legacy route's shape). */
    private function solidGif(int $w, int $h, int $r, int $g, int $b): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $path = $this->tempPath();
        imagegif($im, $path);
        imagedestroy($im);

        return (string) file_get_contents($path);
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/sugar-anim-e07-' . uniqid('', true) . '.gif';
        $this->tempPaths[] = $path;
        return $path;
    }

    /**
     * Write bytes to a fresh temp file and return the path.
     */
    private function pathFor(string $bytes): string
    {
        $path = $this->tempPath();
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * @testdox decode(path) and decodeBytes(contents) agree frame-for-frame on a static GIF
     */
    public function testStaticGifRoutesAgree(): void
    {
        $this->requireGd();

        $bytes = $this->solidGif(4, 4, 12, 200, 40);
        $path = $this->pathFor($bytes);

        $viaPath = Decoder::decode($path, 4, 4);
        $viaBytes = iterator_to_array(Decoder::decodeBytes($bytes, 4, 4), preserve_keys: false);

        $this->assertSame($this->observables($viaPath), $this->observables($viaBytes));
        $this->assertNotEmpty($viaPath, 'the fixture must decode to at least one frame or this proves nothing');
    }

    /**
     * @testdox decode(path) and decodeBytes(contents) agree frame-for-frame on a composite multi-frame GIF
     */
    public function testMultiFrameRoutesAgree(): void
    {
        $this->requireGd();

        $frameA = $this->solidGif(8, 8, 0, 0, 255);
        $frameB = $this->solidGif(8, 8, 0, 255, 0);
        $multi = $this->assembleMultiFrameGif($frameA, $frameB);

        $path = $this->pathFor($multi);

        $viaPath = Decoder::decode($path, 8, 8);
        $viaBytes = iterator_to_array(Decoder::decodeBytes($multi, 8, 8), preserve_keys: false);

        $this->assertSame($this->observables($viaPath), $this->observables($viaBytes));
        $this->assertNotEmpty($viaPath);
    }

    /**
     * @testdox a non-opaque GIF's canvas RGBA equals the legacy cell grid at a 1:1 sampling grid (E-07)
     *
     * Transparent canvas pixels decode to null cells; the capture must map
     * them to \x00\x00\x00\x00 and opaque cells to R,G,B,\xff in row-major
     * order. This is the leg the channel-order and alpha-polarity mutations
     * have to redden.
     */
    public function testCanvasRgbaEqualsLegacyCellGridAtOneToOne(): void
    {
        $this->requireGd();

        // A 2x2 GIF with a transparent pixel: GD cannot emit transparency in
        // imagegif, so the compositing-test trick applies — inject a GCE
        // declaring index 0 transparent over a GD stream whose palette slot 0
        // is a used color; the decoder then nulls every pixel matching it.
        $bytes = $this->transparentCornerGif();
        $path = $this->pathFor($bytes);

        $viaBytes = iterator_to_array(Decoder::decodeBytes($bytes, 2, 2, true), preserve_keys: false);
        $legacy = Decoder::decode($path, 2, 2);

        $this->assertNotEmpty($viaBytes);
        $this->assertCount(count($legacy), $viaBytes);

        foreach ($viaBytes as $i => $frame) {
            $this->assertTrue($frame->hasRgba(), "frame $i must carry the capture when asked for it");
            $this->assertSame(
                $this->rgbaFromCells($legacy[$i]->cells),
                $frame->rgbaBytes(),
                "frame $i: capture must equal the consumer-side reconstruction cell for cell",
            );
            $this->assertSame(2, $frame->rgbaWidth());
            $this->assertSame(2, $frame->rgbaHeight());
        }

        $this->assertFalse($legacy[0]->hasRgba(), 'the default path route must NOT arm the capture');
    }

    /**
     * @testdox decodeBytes without the capture flag leaves frames exactly as before (E-07)
     */
    public function testDefaultBytesRouteCarriesNoCapture(): void
    {
        $this->requireGd();

        $bytes = $this->solidGif(3, 3, 99, 99, 99);
        $frames = iterator_to_array(Decoder::decodeBytes($bytes, 3, 3), preserve_keys: false);

        $this->assertNotEmpty($frames);
        foreach ($frames as $frame) {
            $this->assertFalse($frame->hasRgba());
        }
    }

    /**
     * @testdox a Frame without a capture refuses rgbaBytes() loudly (E-07)
     */
    public function testFrameWithoutCaptureRefuses(): void
    {
        $frame = new Frame([[[1, 2, 3]]]);

        $this->assertFalse($frame->hasRgba());
        try {
            $frame->rgbaBytes();
            $this->fail('expected LogicException for a frame without an RGBA capture');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('without a canvas RGBA capture', $e->getMessage());
        }
    }

    /**
     * @testdox decodeBytes gates fire at CALL time, not first iteration (E-07)
     *
     * The whole point of the eager split is that a bad grid or a bad header
     * is a programming/ingress error visible the moment the generator is
     * CONSTRUCTED — sugar-image's set_error_handler wrap and any future
     * mid-stream consumer both depend on the refusal not hiding in rewind().
     */
    public function testGatesThrowBeforeIteration(): void
    {
        $this->requireGd();

        $goodBytes = $this->solidGif(2, 2, 1, 2, 3);

        foreach ([
            'not a gif at all' => ['junkjunkjunkjunk', 2, 2, 'not a GIF'],
            'truncated signature' => ['GIF89a', 2, 2, 'not a GIF'],
            'zero grid' => [$goodBytes, 0, 2, 'cell grid'],
            'grid above cap' => [$goodBytes, 1000, 1000, 'maximum'],
        ] as $case => [$bytes, $cellsW, $cellsH, $needle]) {
            $threw = false;
            try {
                // NOTE: never iterated — the gates run during the CALL itself.
                Decoder::decodeBytes($bytes, $cellsW, $cellsH);
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString($needle, $e->getMessage(), "case '$case' must name its refusal");
            }
            $this->assertTrue($threw, "case '$case' must throw at call time, not first iteration");
        }
    }

    /**
     * @testdox decode() of a missing path still refuses loudly through the new plumbing
     */
    public function testDecodeMissingFileRefuses(): void
    {
        $ghost = sys_get_temp_dir() . '/sugar-anim-e07-absent-' . uniqid('', true) . '.gif';

        try {
            Decoder::decode($ghost, 1, 1);
            $this->fail('expected RuntimeException for a missing file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($ghost, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // fixtures + helpers
    // -------------------------------------------------------------------------

    /**
     * A 2x2 GIF89a with a global palette of two colors, a GCE declaring
     * index 1 transparent, and one pixel painted with it — hand-assembled so
     * GD's no-transparency-in-imagegif limit does not blunt the oracle.
     */
    private function transparentCornerGif(): string
    {
        // GCT: [0]=red, [1]=green (green becomes the transparent index).
        $header = 'GIF89a' . pack('v2', 2, 2) . "\x81" . "\x00" . "\x00"
            . "\xFF\x00\x00\x00\xFF\x00";

        // GCE: disposal 0, transparency flag on, transparent index 1, delay 1.
        $gce = "\x21\xF9\x04" . "\x01" . "\x01\x00" . "\x01" . "\x00";

        // Image descriptor 2x2 at (0,0), no local table.
        $descriptor = "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00";

        // Minimum LZW code size for a 2-entry palette is 2. Encode 4 pixels
        // [0, 1, 0, 1] as CLEAR=2, literal 0, literal 1, literal 0, literal 1, EOI=3
        // each as a fresh 3-bit code (no compression), sub-block packed LSB-first:
        // codes: 2,0,1,0,1,3 → 6 codes × 3 bits = 18 bits = 02 00 00 ... packed below.
        $codes = [2, 0, 1, 0, 1, 3];
        $bitBuffer = 0;
        $bitCount = 0;
        $lzw = '';
        foreach ($codes as $code) {
            $bitBuffer |= ($code << $bitCount);
            $bitCount += 3;
            while ($bitCount >= 8) {
                $lzw .= chr($bitBuffer & 0xFF);
                $bitBuffer >>= 8;
                $bitCount -= 8;
            }
        }
        if ($bitCount > 0) {
            $lzw .= chr($bitBuffer & 0xFF);
        }

        $blocks = chr(strlen($lzw)) . $lzw . "\x00"; // one sub-block + terminator
        $trailer = "\x3B";

        return $header . $gce . $descriptor . "\x02" . $blocks . $trailer;
    }

    /**
     * Assemble two single-frame GD GIFs into one multi-frame stream, exactly
     * as DecoderCompositingTest does (each frame gets a GCE; the decoder's
     * own tolerance for the embedded trailer is already pinned there).
     */
    private function assembleMultiFrameGif(string $bytes1, string $bytes2): string
    {
        $id1 = strpos($bytes1, "\x2C");
        $id2 = strpos($bytes2, "\x2C");
        if ($id1 === false || $id2 === false) {
            return $bytes1;
        }

        $header1 = substr($bytes1, 0, $id1);
        $gce = "\x21\xF9\x04" . chr(1 << 2) . "\x01\x00\x00\x00";

        return $header1 . $gce . substr($bytes1, $id1) . $gce . substr($bytes2, $id2);
    }

    /**
     * @param list<Frame> $frames
     * @return list<array{cells:list<list<array{0:int,1:int,2:int}|null>>,delay:int,disposal:int,transparent:bool}>
     */
    private function observables(array $frames): array
    {
        return array_map(static fn (Frame $f): array => [
            'cells' => $f->cells,
            'delay' => $f->delay,
            'disposal' => $f->disposal,
            'transparent' => $f->transparent,
        ], $frames);
    }

    /**
     * The consumer-side reconstruction (sugar-image's pre-E-07 rule, pinned
     * as the oracle): row-major, null cell → fully transparent, colored cell
     * → its RGB with full alpha.
     *
     * @param list<list<array{0:int,1:int,2:int}|null>> $cells
     */
    private function rgbaFromCells(array $cells): string
    {
        $out = '';
        foreach ($cells as $row) {
            foreach ($row as $cell) {
                $out .= $cell === null
                    ? "\x00\x00\x00\x00"
                    : chr($cell[0]) . chr($cell[1]) . chr($cell[2]) . "\xff";
            }
        }
        return $out;
    }
}
