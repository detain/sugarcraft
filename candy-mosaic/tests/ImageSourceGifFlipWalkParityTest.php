<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\Decoder as FlipDecoder;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Tests\Support\AnimatedImageFixtures as F;

/**
 * Pins candy-mosaic's byte-only clone of candy-flip's GIF header walk
 * (`ImageSource::gifFlipWalkDescriptorOffsets()`) against what flip ACTUALLY does.
 *
 * The clone used to mirror flip's pre-resync walk (image data skipped from
 * descriptor+10, i.e. the LZW minimum-code-size byte read as a sub-block length, no
 * LCT skip). candy-flip fixed its own walk in `2d5117e1e`, so the stale clone made the
 * offset-equality gate in `fromAnimatedFile()` refuse every valid GIF whose LZW data
 * carries full 255-byte sub-blocks — i.e. everything a real encoder emits — with
 * "frame decoder disagrees with the container". The tiny GD fixtures elsewhere in the
 * suite only passed because their short sub-blocks happened to re-sync the old walk.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceGifFlipWalkParityTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd required to encode GIF frames');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        $this->temp = [];
    }

    public function testRealEncoderGifWithFullSubBlocksLoads(): void
    {
        $gif = $this->noiseGif(3, localTables: false);
        self::assertTrue($this->hasFullSubBlock($gif), 'fixture must carry a 255-byte LZW sub-block');

        $this->assertWalksAgreeWithFlip($gif, 3);

        $anim = ImageSource::fromAnimatedFile($this->write($gif));
        self::assertSame(3, $anim->frameCount());
        self::assertSame([100, 100, 100], $anim->delaysMs);
    }

    public function testGifWithLocalColourTablesPassesLayoutGate(): void
    {
        $gif = $this->noiseGif(2, localTables: true);
        self::assertSame(0, ord($gif[10]) & 0x80, 'fixture must carry no global colour table');

        $honest = $this->offsets('gifHonestDescriptorOffsets', $gif);
        self::assertCount(2, $honest);
        self::assertSame($honest, $this->flipWalk($gif), 'clone must skip the LCT exactly as flip\'s walk does');

        // candy-flip's walk lands on the right descriptors, but its per-frame
        // re-assembly (`Decoder::assembleFrameGif()`) currently writes the LCT BEFORE
        // the image descriptor, so GD rejects every LCT frame and flip returns none.
        // Whatever flip materialises, the layout gate must NOT be what refuses this
        // valid file: either the whole animation loads or flip's own decode failure (or the
        // frame-COUNT defence in depth) is what surfaces.
        try {
            self::assertSame(2, ImageSource::fromAnimatedFile($this->write($gif))->frameCount());
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('disagrees with the container', $e->getMessage());
            self::assertMatchesRegularExpression('/carries 2 frames|could not be decoded/', $e->getMessage());
        }
    }

    /**
     * The 8- and 10-frame small GD GIFs the suite once pinned as "flip desyncs, refuse"
     * are perfectly valid; with flip's resync they decode to every frame, so the loader
     * must return them whole rather than refuse.
     */
    public function testFormerlyDesyncingMultiFrameGifsLoadWhole(): void
    {
        $colors = [[0, 0, 0], [255, 0, 0], [0, 255, 0]];
        foreach ([8, 10] as $n) {
            $frames = [];
            for ($i = 0; $i < $n; $i++) {
                $frames[] = ['pixels' => array_fill(0, 4, ($i % 3) + 1), 'delay' => 10 + $i];
            }
            $gif = F::gif(2, 2, $colors, $frames);

            $this->assertWalksAgreeWithFlip($gif, $n);
            self::assertSame($n, ImageSource::fromAnimatedFile($this->write($gif))->frameCount());
        }
    }

    /**
     * flip throws `decoder.truncated` on an image descriptor cut short by EOF, so the
     * clone must refuse it too rather than record a frame flip never produces.
     */
    public function testCloneRefusesTruncatedDescriptorLikeFlip(): void
    {
        $bytes = 'GIF89a'
            . pack('v', 4) . pack('v', 4) . chr(0x00) . chr(0x00) . chr(0x00)
            . chr(0x2C) . pack('v', 0) . pack('v', 0) . pack('v', 1) . pack('v', 1);

        try {
            FlipDecoder::decode($this->write($bytes), 4, 4);
            self::fail('candy-flip is expected to refuse a truncated image descriptor');
        } catch (\RuntimeException) {
            // flip refuses — the clone must agree.
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->flipWalk($bytes);
    }

    public function testCloneRefusesGlobalColourTablePastEofLikeFlip(): void
    {
        // Packed 0x87 declares a 256-entry (768-byte) GCT the stream does not carry.
        $bytes = 'GIF89a' . pack('v', 4) . pack('v', 4) . chr(0x87) . chr(0) . chr(0) . str_repeat("\x00", 9);

        try {
            FlipDecoder::decode($this->write($bytes), 4, 4);
            self::fail('candy-flip is expected to refuse a GCT past EOF');
        } catch (\RuntimeException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->flipWalk($bytes);
    }

    /**
     * Byte-exact drift guard: the clone's offset list must equal the one candy-flip's
     * own `Decoder::parseHeader()` computes, on every committed GIF fixture (including
     * the round-4 attacker artifacts) and on the encoder-shaped GIFs built here. If flip
     * changes its walk again this goes red before the loader's gate starts refusing
     * valid files (or accepting ones flip walks differently).
     */
    public function testCloneMatchesFlipParseHeaderOffsetsExactly(): void
    {
        $cases = [];
        foreach (glob(__DIR__ . '/fixtures/*.gif') ?: [] as $path) {
            $cases[basename($path)] = (string) file_get_contents($path);
        }
        self::assertNotSame([], $cases, 'expected committed GIF fixtures');
        $cases['noise-gct'] = $this->noiseGif(3, localTables: false);
        $cases['noise-lct'] = $this->noiseGif(2, localTables: true);
        $cases['gd-10f']    = F::gif(2, 2, [[0, 0, 0], [255, 0, 0]], array_fill(0, 10, ['pixels' => [1, 0, 0, 1], 'delay' => 5]));

        $parse = new \ReflectionMethod(FlipDecoder::class, 'parseHeader');
        foreach ($cases as $name => $bytes) {
            /** @var array{frameInfos: list<array{offset: int}>} $header */
            $header = $parse->invoke(null, $bytes);
            self::assertSame(
                array_column($header['frameInfos'], 'offset'),
                $this->flipWalk($bytes),
                "clone diverges from candy-flip's parseHeader() on {$name}",
            );
        }
    }

    private function assertWalksAgreeWithFlip(string $gif, int $frames): void
    {
        $honest = $this->offsets('gifHonestDescriptorOffsets', $gif);
        $clone  = $this->flipWalk($gif);

        self::assertCount($frames, $honest);
        self::assertSame($honest, $clone, 'clone of flip\'s walk must land on the honest descriptor offsets');
        self::assertCount($frames, FlipDecoder::decode($this->write($gif), (int) unpack('v', $gif, 6)[1], (int) unpack('v', $gif, 8)[1]));
    }

    /**
     * An animated GIF spliced from GD-encoded 32×32 16-colour noise frames — noise
     * defeats LZW, so each frame's data runs to several full 255-byte sub-blocks, the
     * shape every real encoder emits. With `$localTables` the palette moves from the
     * global table into a per-frame local table.
     */
    private function noiseGif(int $frames, bool $localTables): string
    {
        $out = '';
        for ($f = 0; $f < $frames; $f++) {
            mt_srand(1000 + $f);
            $im = imagecreate(32, 32);
            for ($c = 0; $c < 16; $c++) {
                imagecolorallocate($im, $c * 16, 255 - $c * 16, ($c * 37) % 256);
            }
            for ($y = 0; $y < 32; $y++) {
                for ($x = 0; $x < 32; $x++) {
                    imagesetpixel($im, $x, $y, mt_rand(0, 15));
                }
            }
            ob_start();
            imagegif($im);
            $single = (string) ob_get_clean();
            imagedestroy($im);

            $packed = ord($single[10]);
            self::assertNotSame(0, $packed & 0x80, 'GD is expected to emit a global colour table');
            $tableBytes = 3 * (1 << (($packed & 0x07) + 1));
            $table = substr($single, 13, $tableBytes);
            $descAt = strpos($single, "\x2C", 13 + $tableBytes);
            self::assertIsInt($descAt);
            $descriptor = substr($single, $descAt, 9);
            $lzw = self::rechunk(substr($single, $descAt + 10, strlen($single) - 1 - ($descAt + 10)));

            if ($f === 0) {
                $out = substr($single, 0, 6) . substr($single, 6, 4)
                    . ($localTables ? chr(0x00) : chr($packed)) . "\x00\x00"
                    . ($localTables ? '' : $table);
            }
            $out .= "\x21\xF9\x04\x00" . pack('v', 10) . "\x00\x00";
            $out .= $localTables
                ? $descriptor . chr(0x80 | ($packed & 0x07)) . $table . $lzw
                : $descriptor . "\x00" . $lzw;
        }

        return $out . "\x3B";
    }

    /**
     * Re-split GD's LZW sub-blocks (GD emits 254-byte runs) into the 255-byte runs
     * giflib/ffmpeg/ImageMagick emit — the exact shape the stale clone mis-walked.
     */
    private static function rechunk(string $lzw): string
    {
        $data = '';
        $j = 1;
        while ($j < strlen($lzw)) {
            $sub = ord($lzw[$j]);
            if ($sub === 0) {
                break;
            }
            $data .= substr($lzw, $j + 1, $sub);
            $j += $sub + 1;
        }

        $out = $lzw[0];
        foreach (str_split($data, 255) as $chunk) {
            $out .= chr(strlen($chunk)) . $chunk;
        }

        return $out . "\x00";
    }

    private function hasFullSubBlock(string $gif): bool
    {
        $len = strlen($gif);
        $at = strpos($gif, "\x2C", 13);
        $packed = ord($gif[$at + 9]);
        $j = $at + 10 + (($packed & 0x80) !== 0 ? 3 * (1 << (($packed & 0x07) + 1)) : 0) + 1;
        while ($j < $len) {
            $sub = ord($gif[$j]);
            if ($sub === 255) {
                return true;
            }
            if ($sub === 0) {
                return false;
            }
            $j += $sub + 1;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function flipWalk(string $bytes): array
    {
        return $this->offsets('gifFlipWalkDescriptorOffsets', $bytes);
    }

    /**
     * @return list<int>
     */
    private function offsets(string $method, string $bytes): array
    {
        $rm = new \ReflectionMethod(ImageSource::class, $method);

        /** @var list<int> $r */
        $r = $rm->invoke(null, $bytes);

        return $r;
    }

    private function write(string $bytes): string
    {
        $path = sys_get_temp_dir() . '/mosaic-flipwalk-' . bin2hex(random_bytes(6)) . '.gif';
        file_put_contents($path, $bytes);
        $this->temp[] = $path;

        return $path;
    }
}
