<?php

declare(strict_types=1);

namespace SugarCraft\Flip;

use SugarCraft\Flip\Lang;

/**
 * Decode a GIF on disk into a list of {@see Frame}s using ext-gd's
 * `imagecreatefromstring()` for in-memory single-frame extraction and
 * a hand-rolled GIF89a parser for per-frame timing.
 *
 * Parses the GIF Logical Screen Descriptor and Global Color Table (GCT)
 * from the header, then walks the frame stream to:
 *   1. Find each frame's Graphic Control Extension (GCE) delay, disposal,
 *      and transparent-index values.
 *   2. Extract the per-frame local color table when present.
 *   3. Build a single-frame GIF in memory compatible with
 *      `imagecreatefromstring()`.
 *   4. Downsample each frame using the area-average method.
 *
 * Disposal methods are tracked per-frame and passed to {@see Frame}
 * so callers can render correctly when animating.
 */
final class Decoder
{
    /**
     * Maximum allowed cell-grid product (cellsW * cellsH) to prevent
     * excessive memory allocation from untrusted input.
     */
    private const MAX_CELLS = 100_000;

    /** @return list<Frame> */
    public static function decode(string $path, int $cellsW, int $cellsH): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(Lang::t('decoder.no_file', ['path' => $path]));
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException(Lang::t('decoder.no_gd'));
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 6
            || (substr($bytes, 0, 6) !== 'GIF87a' && substr($bytes, 0, 6) !== 'GIF89a')) {
            throw new \RuntimeException(Lang::t('decoder.not_gif'));
        }
        if ($cellsW <= 0 || $cellsH <= 0) {
            throw new \RuntimeException(Lang::t('decoder.grid_too_large', ['max' => (string) self::MAX_CELLS]));
        }
        if ($cellsW * $cellsH > self::MAX_CELLS) {
            throw new \RuntimeException(Lang::t('decoder.grid_too_large', ['max' => (string) self::MAX_CELLS]));
        }
        $header = self::parseHeader($bytes);
        $frames = [];
        foreach ($header['frameInfos'] as $info) {
            $frame = self::renderSingleFrame($bytes, $info, $header, $cellsW, $cellsH);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        if ($frames === []) {
            // Static fallback — still returns one frame.
            $info = [
                'offset' => 0,
                'delay' => 10,
                'disposal' => Frame::DISPOSAL_NONE,
                'transparent' => false,
                'transparentIndex' => -1,
                'hasLct' => false,
                'lctBytes' => 0,
            ];
            $frame = self::renderSingleFrame($bytes, $info, $header, $cellsW, $cellsH);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    /**
     * Parse the GIF header: logical screen dimensions, GCT flag + size,
     * and per-frame info (GCE delay + image descriptor offset + disposal
     * + transparency).
     *
     * @return array{
     *   width: int,
     *   height: int,
     *   hasGct: bool,
     *   gctBytes: int,
     *   frameInfos: list<array{offset: int, delay: int, disposal: int, transparent: bool, transparentIndex: int, hasLct: bool, lctBytes: int}>
     * }
     */
    private static function parseHeader(string $bytes): array
    {
        $width  = ord($bytes[6]) | (ord($bytes[7]) << 8);
        $height = ord($bytes[8]) | (ord($bytes[9]) << 8);
        $packed = ord($bytes[10]);
        $hasGct = (bool) ($packed & 0x80);
        $gctSizeExp = $packed & 0x07;
        $gctEntryCount = $hasGct ? (1 << ($gctSizeExp + 1)) : 0;
        $gctBytes = $gctEntryCount * 3;

        $frameInfos = [];
        $lastDelay = 10; // Default 100ms (10 centiseconds) per GIF spec.
        $lastDisposal = Frame::DISPOSAL_NONE;
        $lastTransparent = false;
        $lastTransparentIndex = -1;
        $len = strlen($bytes);

        // Walk the GIF byte stream block-by-block.
        $i = 13 + $gctBytes;
        while ($i < $len) {
            $blockType = ord($bytes[$i] ?? '');
            if ($blockType === 0x3B) {
                // GIF trailer — done.
                break;
            }
            if ($blockType === 0x21) {
                // Extension block. Check label at $i+1, skip block content, handle GCE delay.
                $label = ord($bytes[$i + 1] ?? '');
                if ($label === 0xF9) {
                    // GCE: 0x21 0xF9 0x04 <packed> <delayL> <delayH> <transparent> 0x00
                    $gcePacked = ord($bytes[$i + 3] ?? '');
                    $disposal = ($gcePacked >> 2) & 0x07;
                    $transparent = (bool) ($gcePacked & 0x01);
                    $transparentIndex = ord($bytes[$i + 6] ?? '');
                    $delay = ord($bytes[$i + 4]) | (ord($bytes[$i + 5]) << 8);
                    if ($delay > 0) {
                        $lastDelay = $delay;
                    }
                    $lastDisposal = $disposal;
                    $lastTransparent = $transparent;
                    $lastTransparentIndex = $transparentIndex;
                    $i += 8; // Fixed 8-byte GCE: skip to next block.
                } else {
                    // Skip extension block: read sub-block length bytes until 0x00 terminator.
                    $j = $i + 2;
                    while ($j < $len) {
                        $subLen = ord($bytes[$j]);
                        $j++;
                        if ($subLen === 0) {
                            break; // Block terminator.
                        }
                        if ($j + $subLen > $len) {
                            break; // Would overrun — treat truncated tail as end-of-data.
                        }
                        $j += $subLen; // Skip the sub-block data.
                    }
                    $i = $j;
                }
                continue;
            }
            if ($blockType === 0x2C) {
                // Image Descriptor — record its offset and the last-seen GCE values.
                $descPacked = ord($bytes[$i + 9] ?? '');
                $hasLct = (bool) ($descPacked & 0x80);
                $lctSizeExp = $descPacked & 0x07;
                $lctEntryCount = $hasLct ? (1 << ($lctSizeExp + 1)) : 0;
                $lctBytes = $lctEntryCount * 3;

                $frameInfos[] = [
                    'offset' => $i,
                    'delay' => $lastDelay,
                    'disposal' => $lastDisposal,
                    'transparent' => $lastTransparent,
                    'transparentIndex' => $lastTransparentIndex,
                    'hasLct' => $hasLct,
                    'lctBytes' => $lctBytes,
                ];
                // Skip image data: LZW sub-blocks (length-prefixed) until block terminator (0x00).
                $j = $i + 10;
                while ($j < $len) {
                    $subLen = ord($bytes[$j]);
                    $j++;
                    if ($subLen === 0) {
                        break; // Sub-block terminator.
                    }
                    if ($j + $subLen > $len) {
                        break; // Would overrun — treat truncated tail as end-of-data.
                    }
                    $j += $subLen; // Skip the sub-block data.
                }
                $i = $j;
                continue;
            }
            // Unexpected byte — step forward cautiously.
            $i++;
        }
        // Cap pathological GIFs at 256 frames so the decoder doesn't OOM.
        return [
            'width' => $width,
            'height' => $height,
            'hasGct' => $hasGct,
            'gctBytes' => $gctBytes,
            'frameInfos' => array_slice($frameInfos, 0, 256),
        ];
    }

    /**
     * Build a single-frame GIF payload in memory and render it with
     * `imagecreatefromstring()` — no temp files needed.
     *
     * Passes transparent pixels through as null so downsampling can
     * skip them in area-average mode.
     */
    private static function renderSingleFrame(
        string $bytes,
        array $info,
        array $header,
        int $cellsW,
        int $cellsH,
    ): ?Frame {
        $offset = $info['offset'];
        $delay = $info['delay'];
        $disposal = $info['disposal'];
        $transparent = $info['transparent'];
        $transparentIndex = $info['transparentIndex'];
        $hasLct = $info['hasLct'];
        $lctBytes = $info['lctBytes'];

        // Determine effective color table for this frame.
        $hasGlobal = $header['hasGct'];
        $globalBytes = $header['gctBytes'];
        $effectiveHasColorTable = $hasGlobal || $hasLct;
        $effectiveColorTableBytes = $hasGlobal ? $globalBytes : $lctBytes;

        // Build a minimal single-frame GIF in memory:
        //   GIF header (13 bytes) + effective color table + one GCE block
        //   + one Image Descriptor + image data + trailer.
        $gifData = '';
        // Header slice (first 13 bytes).
        $gifData .= substr($bytes, 0, 13);
        // Color table: global (first) or local (after GCE).
        if ($hasGlobal) {
            $gifData .= substr($bytes, 13, $globalBytes);
        }
        // GCE block for this frame.
        $delayLo = $delay & 0xFF;
        $delayHi = ($delay >> 8) & 0xFF;
        $disposalByte = ($disposal & 0x07) << 2;
        $transparentByte = $transparent ? 0x01 : 0x00;
        $gifData .= "\x21\xF9\x04"
            . chr($disposalByte | $transparentByte)
            . chr($delayLo) . chr($delayHi)
            . chr($transparent && $transparentIndex >= 0 ? $transparentIndex : 0)
            . "\x00";
        // Local color table when present (after the Image Descriptor header).
        if ($hasLct) {
            // The Image Descriptor is always 10 bytes; the LCT follows it directly.
            $lctOffset = $offset + 10;
            $gifData .= substr($bytes, $lctOffset, $lctBytes);
        }
        // The Image Descriptor (10 bytes) is always present and must precede the LZW data.
        $gifData .= substr($bytes, $offset, 10);
        // Extract the LZW image data: find where the LZW sub-blocks end.
        // LZW minimum code size follows the Image Descriptor (offset + 10).
        $lzwStart = $offset + 10 + ($hasLct ? $lctBytes : 0);
        $imgDataEnd = self::findImageDataEnd($bytes, $lzwStart);
        // Extract just the LZW data (LZW min code + compressed bytes + terminator).
        $frameData = substr($bytes, $lzwStart, $imgDataEnd - $lzwStart + 1);
        $gifData .= $frameData;
        // GIF trailer.
        $gifData .= "\x3B";

        $img = @imagecreatefromstring($gifData);
        if ($img === false) {
            return null;
        }
        $frame = self::sample($img, $cellsW, $cellsH, $delay, $disposal, $transparent, $transparentIndex, $hasLct, $lctBytes, $bytes, $offset);
        return $frame;
    }

    /**
     * Area-average downsampling with transparent-pixel awareness.
     */
    private static function sample(
        \GdImage $img,
        int $cellsW,
        int $cellsH,
        int $delay,
        int $disposal,
        bool $transparent,
        int $transparentIndex,
        bool $hasLct,
        int $lctBytes,
        string $bytes,
        int $frameOffset,
    ): Frame {
        $w = imagesx($img);
        $h = imagesy($img);

        // If the frame has transparency, allocate the transparent color index
        // so we can test individual pixels for transparency.
        $transparentColor = null;
        if ($transparent && $transparentIndex >= 0) {
            $transparentColor = imagecolortransparent($img);
        }

        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                $x0 = (int) ($cx * $w / $cellsW);
                $x1 = (int) (($cx + 1) * $w / $cellsW) - 1;
                $y0 = (int) ($cy * $h / $cellsH);
                $y1 = (int) (($cy + 1) * $h / $cellsH) - 1;
                $x1 = max($x0, $x1);
                $y1 = max($y0, $y1);

                $sumR = 0;
                $sumG = 0;
                $sumB = 0;
                $count = 0;
                $allTransparent = true;
                for ($sy = $y0; $sy <= $y1; $sy++) {
                    for ($sx = $x0; $sx <= $x1; $sx++) {
                        // GIFs decode to a PALETTE image, so imagecolorat()
                        // returns the palette INDEX, not a packed RGB value —
                        // it must be resolved through the color table.
                        $index = imagecolorat($img, $sx, $sy);
                        // A pixel is transparent when it uses the transparent color index.
                        if ($transparent && $index === $transparentColor) {
                            continue; // Skip transparent pixel in average.
                        }
                        $allTransparent = false;
                        $rgb = imagecolorsforindex($img, $index);
                        $sumR += $rgb['red'];
                        $sumG += $rgb['green'];
                        $sumB += $rgb['blue'];
                        $count++;
                    }
                }
                if ($count > 0) {
                    $row[] = [
                        (int) round($sumR / $count),
                        (int) round($sumG / $count),
                        (int) round($sumB / $count),
                    ];
                } elseif ($allTransparent) {
                    // Every pixel in the cell was transparent.
                    $row[] = null;
                } else {
                    // No opaque pixels in this cell.
                    $row[] = null;
                }
            }
            $rows[] = $row;
        }
        imagedestroy($img);
        return new Frame($rows, $delay, $disposal, $transparent);
    }

    /**
     * Walk the LZW image data starting at $start (the minimum-code-size
     * byte) and return the index of the 0x00 sub-block terminator.
     *
     * Sub-block lengths are full bytes (1–255) — only 0x00 ends the
     * chain. An earlier version also broke on any length ≥ 0x80, but
     * GIF encoders routinely emit 254-byte sub-blocks, so that truncated
     * the LZW stream and made `imagecreatefromstring()` reject every
     * real frame.
     */
    private static function findImageDataEnd(string $bytes, int $start): int
    {
        // Skip the leading LZW minimum-code-size byte before the sub-blocks.
        $j = $start + 1;
        $len = strlen($bytes);
        while ($j < $len) {
            $subLen = ord($bytes[$j]);
            $j++;
            if ($subLen === 0) {
                break;
            }
            if ($j + $subLen > $len) {
                break; // Would overrun — treat truncated tail as end-of-data.
            }
            $j += $subLen;
        }
        return $j - 1;
    }
}
