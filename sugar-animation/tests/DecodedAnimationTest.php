<?php

declare(strict_types=1);

namespace SugarCraft\Animation\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Animation\DecodedAnimation;
use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Frame;
use SugarCraft\Animation\Lang;

/**
 * Pins for the value-object canonical decode (SA-G1 -006).
 *
 * `Decoder::decodeFile()` seals frames + loop policy + walk stop reason in
 * one value. These tests pin both stop reasons, the documented
 * exactly-256 false positive of the frame-cap heuristic, the loop block
 * riding the SAME audited bytes, and — the compat half of the finding —
 * that the frames are byte-identical to what the legacy `decode()` ritual
 * collects.
 */
final class DecodedAnimationTest extends TestCase
{
    private ?string $tmpPath = null;

    protected function tearDown(): void
    {
        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
            $this->tmpPath = null;
        }
    }

    public function testExactlyCapFramesReportsTheFrameCapHeuristic(): void
    {
        $gif = $this->streamWithFrames(Decoder::MAX_FRAMES);

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertCount(Decoder::MAX_FRAMES, $animation->frames());
        $this->assertTrue($animation->truncated(),
            'exactly-256 is the documented false-positive leg of the cap boundary — reported, not hidden');
        $this->assertSame(DecodedAnimation::STOP_FRAME_CAP, $animation->stopReason());
    }

    public function testSlicedFloodReportsFrameCap(): void
    {
        $gif = $this->streamWithFrames(Decoder::MAX_FRAMES + 44);

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertCount(Decoder::MAX_FRAMES, $animation->frames(), 'the silent slice still yields exactly the cap');
        $this->assertTrue($animation->truncated());
        $this->assertSame(DecodedAnimation::STOP_FRAME_CAP, $animation->stopReason());
    }

    public function testShortCleanStreamHasNoStopReasonAndMatchesLegacyDecode(): void
    {
        $gif = $this->streamWithFrames(3, loopRaw: 0);

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertCount(3, $animation->frames());
        $this->assertFalse($animation->truncated());
        $this->assertNull($animation->stopReason());
        $this->assertEquals(
            Decoder::decode($this->tmpPath, 4, 4),
            $animation->frames(),
            'decodeFile frames are the legacy decode() bytes — purely additive, nothing reinterpreted',
        );
    }

    public function testLoopPlaysRidesTheSameBytes(): void
    {
        $gif = $this->streamWithFrames(2, loopRaw: 2);

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertSame(3, $animation->loopPlays(), 'raw 2 = two extra repeats = three total playthroughs');
        $this->assertNull(Decoder::decodeFile($this->pathFor($this->streamWithFrames(2)), 4, 4)->loopPlays(),
            'no loop block at all = infinite');
    }

    public function testUndecodableFrameReportsItsReason(): void
    {
        // Same poisoned middle frame the -026 corpus pins: three advertised,
        // two survive. The walk now says so out loud.
        $f0 = $this->newSharedPaletteImage();
        imagefilledrectangle($f0, 0, 0, 3, 3, imagecolorexact($f0, 255, 0, 0));

        $f1 = $this->newSharedPaletteImage();
        imagefilledrectangle($f1, 0, 0, 3, 3, imagecolorexact($f1, 0, 255, 0));
        [$id10] = $this->extractDescriptorAndLzw($this->gdGifBytes($f1));
        $id = substr($id10, 0, 9) . "\x00";
        $gce = "\x21\xF9\x04" . chr((Frame::DISPOSAL_BACKGROUND & 0x07) << 2 | 0x01)
            . "\x01\x00" . "\x00" . "\x00";

        $f2 = $this->newSharedPaletteImage();
        imagefilledrectangle($f2, 0, 0, 3, 3, imagecolorexact($f2, 0, 0, 255));

        $gif = $this->sharedPaletteHeader()
            . $this->composeFrameBlock($this->gdGifBytes($f0), Frame::DISPOSAL_NONE, false, 0)
            . $gce . $id . "\xFF\x00"
            . $this->composeFrameBlock($this->gdGifBytes($f2), Frame::DISPOSAL_NONE, false, 0)
            . "\x3B";

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertCount(2, $animation->frames());
        $this->assertSame(DecodedAnimation::STOP_UNDECODABLE_FRAME, $animation->stopReason());
        $this->assertTrue($animation->truncated());
    }

    public function testStaticFallbackRefusalReportsUndecodable(): void
    {
        // A walkable header with no Image Descriptors at all: the fallback
        // pseudo-frame gets rejected by GD, and the empty list must say
        // WHY instead of looking like a zero-frame animation.
        $gif = $this->sharedPaletteHeader() . "\x3B";

        $animation = Decoder::decodeFile($this->pathFor($gif), 4, 4);

        $this->assertSame([], $animation->frames());
        $this->assertSame(DecodedAnimation::STOP_UNDECODABLE_FRAME, $animation->stopReason());
        $this->assertTrue($animation->truncated());
    }

    public function testBudgetRefusalStillThrowsBeforeAnyAnimation(): void
    {
        // The gates stay gates: 256 frames × a 200x200 grid crosses
        // MAX_TOTAL_CELLS, and decodeFile must refuse exactly like
        // decodeBytes does — no value object, no quiet short list.
        $gif = $this->streamWithFrames(Decoder::MAX_FRAMES);
        $path = $this->pathFor($gif);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('decoder.total_cells_too_large', ['max' => (string) Decoder::MAX_TOTAL_CELLS]));

        Decoder::decodeFile($path, 200, 200);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /** A solid-red 4x4 stream with $frames frames and an optional loop block. */
    private function streamWithFrames(int $frames, int $loopRaw = -1): string
    {
        $f = $this->newSharedPaletteImage();
        imagefilledrectangle($f, 0, 0, 3, 3, imagecolorexact($f, 255, 0, 0));
        $block = $this->composeFrameBlock($this->gdGifBytes($f), Frame::DISPOSAL_NONE, false, 0);

        $gif = $this->sharedPaletteHeader();
        if ($loopRaw >= 0) {
            $gif .= "\x21\xFF\x0B" . 'NETSCAPE2.0' . "\x03\x01" . pack('v', $loopRaw) . "\x00";
        }
        for ($i = 0; $i < $frames; $i++) {
            $gif .= $block;
        }

        return $gif . "\x3B";
    }

    private function pathFor(string $bytes): string
    {
        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
        }
        $this->tmpPath = sys_get_temp_dir() . '/decoded-anim-' . uniqid() . '.gif';
        file_put_contents($this->tmpPath, $bytes);

        return $this->tmpPath;
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

    private function composeFrameBlock(string $gd, int $disposal, bool $transparent, int $transparentIndex): string
    {
        [$id10, $lzw] = $this->extractDescriptorAndLzw($gd);
        $id = substr($id10, 0, 9) . "\x00";
        $gce = "\x21\xF9\x04"
            . chr((($disposal & 0x07) << 2) | ($transparent ? 0x01 : 0x00))
            . "\x01\x00"
            . chr($transparent ? $transparentIndex : 0)
            . "\x00";
        return $gce . $id . $lzw;
    }

    /**
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
