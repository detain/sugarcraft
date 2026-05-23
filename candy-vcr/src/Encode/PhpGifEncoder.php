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

        for ($i = 0; $i < $frameCount; $i++) {
            $image = $this->loadPng($pngPaths[$i]);
            if ($image === false) {
                throw new \RuntimeException('Failed to load frame ' . $i . ': ' . $pngPaths[$i]);
            }

            try {
                $this->writeGraphicCtrlExt($gif, $delayCentiseconds[$i]);
                $this->writeImageBlock($gif, $image, $width, $height);
            } finally {
                imagedestroy($image);
            }
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

    private function writeImageBlock(\SplFileObject $gif, \GdImage $image, int $width, int $height): void
    {
        \assert($width >= 1 && $height >= 1);
        $gif->fwrite("\x2c");
        $gif->fwrite(pack('v', 0));
        $gif->fwrite(pack('v', 0));
        $gif->fwrite(pack('v', $width));
        $gif->fwrite(pack('v', $height));

        // Local color table flag (0x80) + size 256 = 0x87 ((1<<3)+7 bits → 256 entries).
        // Every frame writes its own LCT because we don't share a GCT.
        $gif->fwrite("\x87");

        imagetruecolortopalette($image, false, 256);
        $paletteSize = imagecolorstotal($image);

        for ($idx = 0; $idx < 256; $idx++) {
            if ($idx < $paletteSize) {
                $rgba = imagecolorsforindex($image, $idx);
                $gif->fwrite(chr($rgba['red'] & 0xff) . chr($rgba['green'] & 0xff) . chr($rgba['blue'] & 0xff));
            } else {
                $gif->fwrite("\x00\x00\x00");
            }
        }

        $pixels = '';
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $idx = imagecolorat($image, $x, $y);
                $pixels .= chr($idx & 0xff);
            }
        }

        $lzw = $this->lzwEncode($pixels, 8);
        $gif->fwrite(pack('C', 8));
        $gif->fwrite($lzw);
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
