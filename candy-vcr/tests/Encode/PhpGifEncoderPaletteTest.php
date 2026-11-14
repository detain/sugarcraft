<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * Host-invariant color quantization ([ci-fix F4]).
 *
 * The old writeImageBlock() delegated palette construction to libgd's
 * imagetruecolortopalette(), whose ordering (and reduction choices) vary
 * with the libgd build — the same raster produced different LCT/pixel
 * bytes on different runners, breaking VisualRegressionTest's byte
 * fast-path. These tests pin the pure-PHP replacement: palette order is
 * a total function of (frequency DESC, RGB ASC), kept colors survive
 * exactly, dropped colors snap to their nearest kept entry, indexed
 * inputs are normalized before the scan, and identical rasters encode
 * to identical bytes.
 */
final class PhpGifEncoderPaletteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        if (!function_exists('imagepng') || !function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd required for palette quantization tests');
        }
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-palette-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testPaletteUnder256ColorsKeepsEveryColorExactAndOrdered(): void
    {
        $bg = [21, 22, 30];
        $text = [196, 197, 197];
        $accent = [122, 162, 247];

        $pixels = array_fill(0, 60, $bg);      // hottest → palette index 0
        $pixels[] = $text;                     // 3 occurrences → index 1
        $pixels[] = $text;
        $pixels[] = $text;
        $pixels[] = $accent;                   // coldest → index 2
        $png = $this->writeTruecolorPng('under256', 8, 8, $pixels);
        $gif = $this->encode($png);

        $decoded = $this->decode($gif);
        try {
            $expected = [];
            $index = 0;
            foreach ($pixels as [$r, $g, $b]) {
                $expected[] = [$index % 8, intdiv($index, 8), [$r, $g, $b]];
                $index++;
            }
            foreach ($expected as [$x, $y, $rgb]) {
                $index = imagecolorat($decoded, $x, $y);
                self::assertIsInt($index);
                $color = imagecolorsforindex($decoded, $index);
                self::assertSame(
                    $rgb,
                    [$color['red'], $color['green'], $color['blue']],
                    "pixel ({$x},{$y}) must decode to the exact source RGB"
                );
            }

            // The ordering contract: frequency DESC with an RGB-ASC tiebreak is
            // a total order, so the palette layout itself is host-invariant.
            self::assertSame(0, imagecolorat($decoded, 2, 2), 'hottest color sits at palette index 0');
            self::assertSame(1, imagecolorat($decoded, 6, 7), 'second-hottest sits at index 1');
            self::assertSame(2, imagecolorat($decoded, 7, 7), 'coldest sits at index 2');
        } finally {
            imagedestroy($decoded);
        }
    }

    public function testReductionBeyond256DropsColdestAndSnapsToNearest(): void
    {
        $bg = [10, 10, 10];       // 2840 px — kept at index 0
        $fg = [200, 200, 200];    // 1000 px — kept at index 1

        $pixels = array_fill(0, 2840, $bg);
        $pixels = array_merge($pixels, array_fill(0, 1000, $fg));
        // 256 one-pixel cold colors: (16,16,1)..(16,16,255) plus (16,17,1).
        // Exactly two exceed the 256-entry budget and, with the RGB-ASC
        // tiebreak on equal counts, they are deterministically the two
        // LARGEST: (16,16,255) and (16,17,1).
        $cold = [];
        for ($k = 1; $k <= 255; $k++) {
            $cold[] = [16, 16, $k];
        }
        $cold[] = [16, 17, 1];
        $pixels = array_merge($pixels, $cold);

        $png = $this->writeTruecolorPng('over256', 64, 64, $pixels);
        $gif = $this->encode($png);

        $decoded = $this->decode($gif);
        try {
            // Hot colors are untouchable: exact RGB, indices 0 and 1.
            $bgIndex = imagecolorat($decoded, 0, 0);
            $bgColor = imagecolorsforindex($decoded, $bgIndex);
            self::assertSame($bg, [$bgColor['red'], $bgColor['green'], $bgColor['blue']]);
            self::assertSame(0, $bgIndex, 'dominant background keeps palette slot 0');

            $fgPos = 2900; // inside the fg block
            $fgIndex = imagecolorat($decoded, $fgPos % 64, intdiv($fgPos, 64));
            $fgColor = imagecolorsforindex($decoded, $fgIndex);
            self::assertSame($fg, [$fgColor['red'], $fgColor['green'], $fgColor['blue']]);
            self::assertSame(1, $fgIndex, 'second-dominant keeps palette slot 1');

            // Kept cold colors survive exactly — spot-check a spread of indices.
            foreach ([1, 2, 128, 253, 254] as $k) {
                $pos = 3840 + ($k - 1); // cold block: 1-indexed k at 3840+k-1
                $x = $pos % 64;
                $y = intdiv($pos, 64);
                $rgb = imagecolorsforindex($decoded, imagecolorat($decoded, $x, $y));
                self::assertSame(
                    [16, 16, $k],
                    [$rgb['red'], $rgb['green'], $rgb['blue']],
                    "kept cold color (16,16,{$k}) must survive the reduction exactly"
                );
            }

            // Dropped colors snap to their nearest kept entry (ties → lowest
            // index): (16,16,255) sits at pixel 4094 (62,63); (16,17,1) at
            // pixel 4095 (63,63).
            $droppedA = imagecolorsforindex($decoded, imagecolorat($decoded, 62, 63));
            self::assertSame([16, 16, 254], [$droppedA['red'], $droppedA['green'], $droppedA['blue']]);
            $droppedB = imagecolorsforindex($decoded, imagecolorat($decoded, 63, 63));
            self::assertSame([16, 16, 1], [$droppedB['red'], $droppedB['green'], $droppedB['blue']]);
        } finally {
            imagedestroy($decoded);
        }
    }

    public function testSameRasterEncodesToIdenticalBytes(): void
    {
        $png = $this->writeTruecolorPng('det', 8, 8, array_merge(
            array_fill(0, 32, [21, 22, 30]),
            array_fill(0, 16, [196, 197, 197]),
            array_fill(0, 16, [122, 162, 247])
        ));

        $first = $this->encode($png);
        $second = $this->encode($png);

        self::assertSame(md5_file($first), md5_file($second), 'repeat encodes of one raster must be byte-identical');
    }

    public function testIndexedSourcePngEncodesLikeItsTruecolorTwin(): void
    {
        // Same visual built two ways: a truecolor PNG and an indexed (palette)
        // PNG. Without the imageistruecolor normalization in writeImageBlock,
        // the indexed input would be scanned as raw color-table indices and
        // paint the wrong bytes; both sources must now converge on one GIF.
        $bg = [21, 22, 30];
        $ink = [196, 197, 197];

        $truecolorPng = $this->writeTruecolorPng(
            'twin-truecolor',
            8,
            8,
            array_merge(array_fill(0, 63, $bg), [$ink])
        );

        $indexedPath = $this->tmpDir . '/twin-indexed.png';
        $image = imagecreate(8, 8);
        self::assertNotFalse($image);
        $bgIndex = imagecolorallocate($image, ...$bg);
        $inkIndex = imagecolorallocate($image, ...$ink);
        self::assertNotFalse($bgIndex);
        self::assertNotFalse($inkIndex);
        self::assertTrue(imagefill($image, 0, 0, $bgIndex));
        self::assertTrue(imagesetpixel($image, 7, 7, $inkIndex));
        self::assertTrue(imagepng($image, $indexedPath));
        imagedestroy($image);

        self::assertSame(
            md5_file($this->encode($truecolorPng)),
            md5_file($this->encode($indexedPath)),
            'indexed and truecolor sources of one raster must produce identical GIF bytes'
        );
    }

    /**
     * Lay the given RGB list left-to-right, top-to-bottom onto a truecolor
     * image and write it as a 24-bit PNG.
     *
     * @param list<array{int,int,int}> $pixels
     */
    private function writeTruecolorPng(string $name, int $width, int $height, array $pixels): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $allocated = [];
        $i = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = $pixels[$i++];
                $key = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];
                if (!isset($allocated[$key])) {
                    $color = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
                    self::assertNotFalse($color);
                    $allocated[$key] = $color;
                }
                self::assertTrue(imagesetpixel($image, $x, $y, $allocated[$key]));
            }
        }
        $path = $this->tmpDir . "/{$name}.png";
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);
        return $path;
    }

    /**
     * @return string path to the encoded GIF
     */
    private function encode(string $png): string
    {
        $gif = $this->tmpDir . '/' . basename($png, '.png') . '.gif';
        self::assertTrue((new PhpGifEncoder())->encode([$png], $gif, 30));
        return $gif;
    }

    private function decode(string $gif): \GdImage
    {
        $bytes = file_get_contents($gif);
        self::assertIsString($bytes);
        $decoded = imagecreatefromstring($bytes);
        self::assertNotFalse($decoded, 'encoded GIF must decode with GD');
        return $decoded;
    }
}
