<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

/**
 * Pure-PHP GIF encoder fallback.
 *
 * Assembles animated GIFs using GD's imagegif() with a custom animation
 * extension. Slow (~5-10x slower than ffmpeg) but requires no external binaries.
 *
 * Mirrors charmbracelet/x/vhs PhpGifEncoder.
 */
final class PhpGifEncoder implements GifEncoder
{
    public function encode(
        array $pngPaths,
        string $outputPath,
        int $fps = 30,
        ?array $durations = null,
    ): bool {
        if ($pngPaths === []) {
            throw new \RuntimeException('No frames provided to encode');
        }

        $frameCount = count($pngPaths);
        $delayCentiseconds = $this->buildDelayArray($durations, $fps, $frameCount);

        $firstImage = $this->loadPng($pngPaths[0]);
        if ($firstImage === false) {
            throw new \RuntimeException('Failed to load first frame: ' . $pngPaths[0]);
        }

        $width = imagesx($firstImage);
        $height = imagesy($firstImage);
        imagedestroy($firstImage);

        $gif = new \SplFileObject($outputPath, 'w');

        $this->writeHeader($gif, $width, $height);
        $this->writeNetscapeExt($gif);

        $previousImage = null;
        for ($i = 0; $i < $frameCount; $i++) {
            $image = $this->loadPng($pngPaths[$i]);
            if ($image === false) {
                throw new \RuntimeException('Failed to load frame ' . $i . ': ' . $pngPaths[$i]);
            }

            $delay = (int) (($delayCentiseconds[$i] + 5) / 10);
            $this->writeGraphicCtrlExt($gif, $delay);
            $this->writeImageBlock($gif, $image, $width, $height, $i === 0);

            if ($previousImage !== null) {
                imagedestroy($previousImage);
            }
            $previousImage = $image;
        }

        if ($previousImage !== null) {
            imagedestroy($previousImage);
        }

        $gif->fwrite("\x3b");
        $gif = null;

        return is_file($outputPath) && filesize($outputPath) > 0;
    }

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    public function name(): string
    {
        return 'php';
    }

    /**
     * @param list<int>|null $durations
     * @return list<int>
     */
    private function buildDelayArray(?array $durations, int $fps, int $frameCount): array
    {
        if ($durations !== null) {
            $result = [];
            for ($i = 0; $i < $frameCount; $i++) {
                $ms = $durations[$i] ?? (1000 / $fps);
                $result[] = (int) round($ms / 10);
            }
            return $result;
        }

        $delay = (int) round(1000 / $fps / 10);
        return array_fill(0, $frameCount, $delay);
    }

    private function loadPng(string $path): \GdImage|false
    {
        return @imagecreatefrompng($path);
    }

    private function writeHeader(\SplFileObject $gif, int $width, int $height): void
    {
        $gif->fwrite('GIF89a');
        $gif->fwrite(pack('v', $width));
        $gif->fwrite(pack('v', $height));
        $gif->fwrite("\x70");
        $gif->fwrite("\x00");
        $gif->fwrite("\x00");
    }

    private function writeNetscapeExt(\SplFileObject $gif): void
    {
        $gif->fwrite("\x21\xff\x0b");
        $gif->fwrite('NETSCAPE2.0');
        $gif->fwrite("\x03\x01");
        $gif->fwrite("\x00\x00\x00");
    }

    private function writeGraphicCtrlExt(\SplFileObject $gif, int $delay): void
    {
        $delay = max(1, min(65535, $delay));
        $gif->fwrite("\x21\xf9\x04");
        $gif->fwrite("\x00");
        $gif->fwrite(pack('v', $delay));
        $gif->fwrite("\x00");
        $gif->fwrite("\x00");
    }

    private function writeImageBlock(\SplFileObject $gif, \GdImage $image, int $width, int $height, bool $first): void
    {
        \assert($width >= 1 && $height >= 1);
        $gif->fwrite("\x2c");
        $gif->fwrite(pack('v', 0));
        $gif->fwrite(pack('v', 0));
        $gif->fwrite(pack('v', $width));
        $gif->fwrite(pack('v', $height));

        if ($first) {
            $gif->fwrite("\x00");
        } else {
            $gif->fwrite("\x87");
        }

        $palette = $this->extractPalette($image, $width, $height);
        foreach (array_slice($palette, 0, 256) as $rgb) {
            $gif->fwrite(pack('ccc', $rgb[0], $rgb[1], $rgb[2]));
        }

        while (count($palette) < 256) {
            $gif->fwrite("\x00\x00\x00");
            $palette[] = [0, 0, 0];
        }

        $indexed = imagecreatetruecolor($width, $height);
        imagetruecolortopalette($image, false, 256);
        imagecopy($indexed, $image, 0, 0, 0, 0, $width, $height);

        $pixels = '';
        for ($y = 0; $y < $height; $y++) {
            $pixels .= "\x00";
            for ($x = 0; $x < $width; $x++) {
                $idx = imagecolorat($indexed, $x, $y);
                $pixels .= chr($idx & 0xff);
            }
        }

        imagedestroy($indexed);

        $lzw = $this->lzwEncode($pixels, 8);
        $gif->fwrite(pack('C', 8));
        $gif->fwrite($lzw);
    }

    /**
     * Extract a 256-color palette from the image using a median-cut algorithm.
     *
     * @return array<array{0:int, 1:int, 2:int}>
     */
    private function extractPalette(\GdImage $image, int $width, int $height): array
    {
        $colors = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $idx = imagecolorat($image, $x, $y);
                if ($idx === false) {
                    continue;
                }
                if (imageistruecolor($image)) {
                    $a = ($idx >> 24) & 0x7F;
                    if ($a > 80) {
                        continue;
                    }
                    $colors[] = [
                        ($idx >> 16) & 0xFF,
                        ($idx >> 8) & 0xFF,
                        $idx & 0xFF,
                    ];
                } else {
                    $rgba = imagecolorsforindex($image, $idx);
                    $alpha = $rgba['alpha'];
                    if ($alpha > 80) {
                        continue;
                    }
                    $colors[] = [$rgba['red'], $rgba['green'], $rgba['blue']];
                }
            }
        }

        if ($colors === []) {
            return [[0, 0, 0], [255, 255, 255]];
        }

        $palette = $this->medianCut($colors, 256);

        while (count($palette) < 2) {
            $palette[] = [0, 0, 0];
        }

        return $palette;
    }

    /**
     * @param list<array{0:int, 1:int, 2:int}> $colors
     * @return array<array{0:int, 1:int, 2:int}>
     */
    private function medianCut(array $colors, int $maxColors): array
    {
        if (count($colors) <= $maxColors) {
            return $colors;
        }

        $buckets = [$colors];
        while (count($buckets) < $maxColors) {
            $largest = 0;
            $largestSize = 0;
            foreach ($buckets as $idx => $bucket) {
                if (count($bucket) > $largestSize) {
                    $largestSize = count($bucket);
                    $largest = $idx;
                }
            }

            if ($largestSize <= 1) {
                break;
            }

            $bucket = $buckets[$largest];
            unset($buckets[$largest]);
            $buckets = array_values($buckets);

            $split = $this->findSplit($bucket);
            $buckets[] = array_slice($bucket, 0, $split);
            $buckets[] = array_slice($bucket, $split);
        }

        $palette = [];
        foreach ($buckets as $bucket) {
            $avg = $this->averageColor($bucket);
            $palette[] = $avg;
        }

        return $palette;
    }

    /**
     * @param list<array{0:int, 1:int, 2:int}> $colors
     */
    private function findSplit(array $colors): int
    {
        $rMin = 255; $rMax = 0;
        $gMin = 255; $gMax = 0;
        $bMin = 255; $bMax = 0;

        foreach ($colors as $c) {
            if ($c[0] < $rMin) $rMin = $c[0];
            if ($c[0] > $rMax) $rMax = $c[0];
            if ($c[1] < $gMin) $gMin = $c[1];
            if ($c[1] > $gMax) $gMax = $c[1];
            if ($c[2] < $bMin) $bMin = $c[2];
            if ($c[2] > $bMax) $bMax = $c[2];
        }

        $rRange = $rMax - $rMin;
        $gRange = $gMax - $gMin;
        $bRange = $bMax - $bMin;

        if ($rRange >= $gRange && $rRange >= $bRange) {
            usort($colors, fn($a, $b) => $a[0] <=> $b[0]);
        } elseif ($gRange >= $bRange) {
            usort($colors, fn($a, $b) => $a[1] <=> $b[1]);
        } else {
            usort($colors, fn($a, $b) => $a[2] <=> $b[2]);
        }

        return (int) floor(count($colors) / 2);
    }

    /**
     * @param list<array{0:int, 1:int, 2:int}> $colors
     * @return array{0:int, 1:int, 2:int}
     */
    private function averageColor(array $colors): array
    {
        $count = count($colors);
        $sumR = 0; $sumG = 0; $sumB = 0;
        foreach ($colors as $c) {
            $sumR += $c[0];
            $sumG += $c[1];
            $sumB += $c[2];
        }
        return [
            (int) round($sumR / $count),
            (int) round($sumG / $count),
            (int) round($sumB / $count),
        ];
    }

    private function lzwEncode(string $data, int $minCodeSize): string
    {
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;
        $codeSize = $minCodeSize + 1;
        $nextCode = $endCode + 1;
        $limit = (1 << 12) - 1;

        $dictionary = [];
        for ($i = 0; $i < $clearCode; $i++) {
            $dictionary[(string) chr($i)] = $i;
        }

        $output = '';

        $state = new class($codeSize) {
            public int $bitBuffer = 0;
            public int $bitCount = 0;
            public int $codeSize;

            public function __construct(int $codeSize)
            {
                $this->codeSize = $codeSize;
            }
        };

        $emit = function (int $code) use (&$output, $state): void {
            $state->bitBuffer |= $code << $state->bitCount;
            $state->bitCount += $state->codeSize;

            while ($state->bitCount >= 8) {
                $output .= chr($state->bitBuffer & 0xff);
                $state->bitBuffer >>= 8;
                $state->bitCount -= 8;
            }
        };

        $emit($clearCode);

        $buffer = '';
        foreach (str_split($data) as $char) {
            $word = $buffer . $char;
            if (isset($dictionary[$word])) {
                $buffer = $word;
            } else {
                $emit($dictionary[$buffer]);
                if ($nextCode <= $limit) {
                    $dictionary[$word] = $nextCode++;
                    if ($nextCode > (1 << $state->codeSize) && $state->codeSize < 12) {
                        $state->codeSize++;
                    }
                } else {
                    $emit($clearCode);
                    $dictionary = [];
                    for ($i = 0; $i < $clearCode; $i++) {
                        $dictionary[(string) chr($i)] = $i;
                    }
                    $nextCode = $endCode + 1;
                    $state->codeSize = $minCodeSize + 1;
                }
                $buffer = $char;
            }
        }

        if ($buffer !== '') {
            $emit($dictionary[$buffer]);
        }

        $emit($endCode);

        if ($state->bitCount > 0) {
            $output .= chr($state->bitBuffer & 0xff);
        }

        return $this->packBytes($output, $minCodeSize);
    }

    private function packBytes(string $data, int $minCodeSize): string
    {
        $output = '';
        $len = strlen($data);
        $offset = 0;

        while ($offset < $len) {
            $chunk = substr($data, $offset, 255);
            $output .= chr(strlen($chunk));
            $output .= $chunk;
            $offset += 255;
        }

        return $output;
    }
}
