<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

/**
 * Pure-PHP GIF encoder fallback.
 *
 * Assembles animated GIFs byte-by-byte by hand (GIF89a header, NETSCAPE loop
 * extension, per-frame Graphic Control + Local Color Table + LZW image data);
 * GD is used only to decode the input PNGs. Slow (~5-10x slower than ffmpeg)
 * but requires no external binaries.
 *
 * Host-invariant output ([ci-fix F4]): color reduction and palette ordering
 * are plain PHP arithmetic on the frame's own RGB values (see
 * {@see self::writeImageBlock()}), so identical input rasters produce
 * identical GIF bytes on every host. The old path delegated reduction to
 * libgd's imagetruecolortopalette(), whose palette ordering varies with the
 * libgd build — CI runners and golden-baking hosts disagree, which moved the
 * index bytes and the LCT stream for the SAME pixels. The only remaining
 * host dependency is the upstream truecolor raster itself, which
 * VisualRegressionTest gates with GOLDEN_RASTER_FINGERPRINT.
 *
 * No upstream counterpart: charmbracelet/vhs delegates GIF assembly wholly
 * to ffmpeg; a pure-PHP encoder fallback is original to SugarCraft.
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
        // The int-casting door. buildDelayArray divides by
        // $fps twice (1000/$fps and the durations fallback); fps <= 0 there
        // is an ArithmeticError (modulo by zero) or a silent infinite-loop
        // risk on the fps=0 float path, surfaced as a hang rather than a
        // message. Reject at entry, where the argument is named.
        if ($fps <= 0) {
            throw new \InvalidArgumentException(
                "fps must be a positive finite number of frames per second, got {$fps}"
            );
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

        // The return MUST re-stat: encodeAtomically() reserves $outputPath with
        // fopen('x') and hands it to us over an already-open SplFileObject, and
        // BOTH seed PHP's global stat cache with the creation-time size 0. The
        // bare filesize() below would then read the stale 0 and return false
        // for a fully written GIF (the write itself is real — the rename that
        // follows moved the bytes). Clearing the path's cache makes the bool
        // the honest verdict its @return contract promises.
        // The return MUST re-stat: encodeAtomically() reserves $outputPath with
        // fopen('x') and hands it to us over an already-open SplFileObject, and
        // BOTH seed PHP's global stat cache with the creation-time size 0. The
        // bare filesize() below would then read the stale 0 and return false
        // for a fully written GIF (the write itself is real — the rename that
        // follows moved the bytes). Clearing the path's cache makes the bool
        // the honest verdict its @return contract promises.
        clearstatcache(true, $outputPath);
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

        if (!imageistruecolor($image)) {
            // Callers may hand us an indexed PNG; GD's palette-mode pixel reads
            // return color-table indices, so normalize to truecolor RGB values
            // before the scan below (the old imagetruecolortopalette() step did
            // this conversion implicitly for every input).
            imagepalettetotruecolor($image);
        }

        // Host-invariant quantization ([ci-fix F4]): one truecolor pass builds
        // the color histogram and the flat RGB scan; the palette is then the
        // 256 most-frequent colors in a total order (count DESC, rgb ASC) that
        // depends on nothing but the frame's own bytes.
        $counts = [];
        $scan = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y) & 0xffffff;
                $counts[$rgb] = ($counts[$rgb] ?? 0) + 1;
                $scan[] = $rgb;
            }
        }

        $palette = $this->buildPalette($counts);
        $indices = $this->mapToPalette($counts, $palette);

        for ($idx = 0; $idx < 256; $idx++) {
            if (isset($palette[$idx])) {
                $rgb = $palette[$idx];
                $gif->fwrite(chr(($rgb >> 16) & 0xff) . chr(($rgb >> 8) & 0xff) . chr($rgb & 0xff));
            } else {
                $gif->fwrite("\x00\x00\x00");
            }
        }

        // Row chunks, not a single growing buffer: per-pixel appends onto the
        // whole-frame string re-alloc as the string grows (finding candy-vcr-016
        // third term). Rows reset the append target to frame-width scale.
        $pixels = '';
        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            $row = '';
            for ($x = 0; $x < $width; $x++) {
                $row .= chr($indices[$scan[$offset++]]);
            }
            $pixels .= $row;
        }

        $lzw = $this->lzwEncode($pixels, 8);
        $gif->fwrite(pack('C', 8));
        $gif->fwrite($lzw);
        // GIF89a spec: each LZW image data sub-block is terminated by a
        // zero-length sub-block (0x00). This ensures strict GIF parsers
        // correctly identify where the image data ends.
        $gif->fwrite("\x00");
    }

    /**
     * The 256 most frequent colors, ordered by (frequency DESC, RGB ASC).
     *
     * Both sort keys together form a total order, so the palette is a pure
     * function of the frame's color multiset: the same raster emits the same
     * Local Color Table bytes on every host. Colors beyond 256 are dropped
     * here and re-mapped to their nearest survivor by {@see self::mapToPalette()}.
     *
     * @param array<int,int> $counts rgb24 => occurrences
     * @return list<int> rgb24 values in palette-index order, at most 256
     */
    private function buildPalette(array $counts): array
    {
        $colors = array_keys($counts);
        usort($colors, static fn (int $a, int $b): int => $counts[$b] <=> $counts[$a] ?: $a <=> $b);
        if (count($colors) > 256) {
            $colors = array_slice($colors, 0, 256);
        }
        return $colors;
    }

    /**
     * Map every observed color to its palette index.
     *
     * Kept colors map exactly; dropped ones (frames with > 256 unique colors)
     * snap to the nearest palette entry by squared RGB distance, ties resolved
     * to the lowest index by iterating the palette in order. Pure PHP, so the
     * mapping is host-independent like the palette itself.
     *
     * @param array<int,int> $counts rgb24 => occurrences
     * @param list<int> $palette
     * @return array<int,int> rgb24 => palette index
     */
    private function mapToPalette(array $counts, array $palette): array
    {
        $map = [];
        foreach ($palette as $idx => $rgb) {
            $map[$rgb] = $idx;
        }
        if (count($counts) <= count($palette)) {
            return $map;
        }
        foreach (array_keys($counts) as $rgb) {
            if (isset($map[$rgb])) {
                continue;
            }
            $r = ($rgb >> 16) & 0xff;
            $g = ($rgb >> 8) & 0xff;
            $b = $rgb & 0xff;
            $best = 0;
            $bestDistance = -1;
            foreach ($palette as $idx => $candidate) {
                $dr = $r - (($candidate >> 16) & 0xff);
                $dg = $g - (($candidate >> 8) & 0xff);
                $db = $b - ($candidate & 0xff);
                $distance = $dr * $dr + $dg * $dg + $db * $db;
                if ($bestDistance === -1 || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $idx;
                }
            }
            $map[$rgb] = $best;
        }
        return $map;
    }

    /**
     * LZW-encode pixels with an integer-keyed code trie.
     *
     * The word dictionary is keyed `(prefixCode << 8) | byte` instead of the
     * word string. String keys made every byte of a shared prefix cost a fresh
     * concatenation and hash — a k-byte run burned O(k²) work, and long-run
     * terminal frames (the common case: thousands of identical background
     * pixels) were quadratic (finding candy-vcr-016, 551.7x measured on the
     * candy-code per-frame encode path lineage #51/#95/#99). Integer keys keep
     * the state in two ints: constant work per byte. The emitted code stream is
     * identical to the string-keyed construction — single-byte entries live at
     * sentinel prefix 256 (the CLEAR code, never a real prefix), 2-byte words
     * at prefix 0..255 (their first byte's code), 3+-byte words at their
     * prefix's assigned code (≥ 258), so the code space never collides.
     */
    private function lzwEncode(string $data, int $minCodeSize): string
    {
        $clearCode = 1 << $minCodeSize;
        $endCode = $clearCode + 1;
        $codeSize = $minCodeSize + 1;
        $nextCode = $endCode + 1;
        $limit = (1 << 12) - 1;

        $dictionary = $this->primeDictionary($clearCode);

        $output = '';

        $state = new class ($codeSize) {
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

        // Code of the current longest-match buffer; -1 means "empty".
        $bufferCode = -1;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($data[$i]);
            $key = (($bufferCode < 0 ? $clearCode : $bufferCode) << 8) | $byte;
            // Single hash lookup per byte; -1 is never a stored code.
            $found = $dictionary[$key] ?? -1;
            if ($found !== -1) {
                $bufferCode = $found;
                continue;
            }

            $emit($bufferCode);
            if ($nextCode <= $limit) {
                $dictionary[$key] = $nextCode++;
                if ($nextCode > (1 << $state->codeSize) && $state->codeSize < 12) {
                    $state->codeSize++;
                }
            } else {
                $emit($clearCode);
                $dictionary = $this->primeDictionary($clearCode);
                $nextCode = $endCode + 1;
                $state->codeSize = $minCodeSize + 1;
            }
            // Restart the match at this single byte (always primed).
            $bufferCode = $dictionary[($clearCode << 8) | $byte];
        }

        if ($bufferCode >= 0) {
            $emit($bufferCode);
        }

        $emit($endCode);

        if ($state->bitCount > 0) {
            $output .= chr($state->bitBuffer & 0xff);
        }

        return $this->packBytes($output, $minCodeSize);
    }

    /**
     * Fresh-dictionary single-byte entries at sentinel prefix CLEAR.
     *
     * @return array<int, int>
     */
    private function primeDictionary(int $clearCode): array
    {
        $dictionary = [];
        $base = $clearCode << 8;
        for ($i = 0; $i < $clearCode; $i++) {
            $dictionary[$base | $i] = $i;
        }

        return $dictionary;
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
