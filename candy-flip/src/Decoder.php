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
 *   1. Find each frame's Graphic Control Extension (GCE) delay value.
 *   2. Extract the corresponding image data as an in-memory single-frame
 *      GIF compatible with `imagecreatefromstring()`.
 *
 * Plenty of GIFs out there don't quite follow the spec — when the decoder
 * can't find another image descriptor, it stops. Callers still get
 * whatever frames did parse.
 */
final class Decoder
{
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
            $info = ['offset' => 0, 'delay' => 10];
            $frame = self::renderSingleFrame($bytes, $info, $header, $cellsW, $cellsH);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    /**
     * Parse the GIF header: logical screen dimensions, GCT flag + size,
     * and per-frame info (GCE delay + image descriptor offset).
     *
     * @return array{
     *   width: int,
     *   height: int,
     *   hasGct: bool,
     *   gctBytes: int,
     *   frameInfos: list<array{offset: int, delay: int}>
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
                    $delay = ord($bytes[$i + 4]) | (ord($bytes[$i + 5]) << 8);
                    $lastDelay = $delay > 0 ? $delay : $lastDelay;
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
                        $j += $subLen; // Skip the sub-block data.
                    }
                    $i = $j;
                }
                continue;
            }
            if ($blockType === 0x2C) {
                // Image Descriptor — record its offset and the last-seen GCE delay.
                $frameInfos[] = ['offset' => $i, 'delay' => $lastDelay];
                // Skip image data: LZW sub-blocks (length-prefixed) until block terminator (0x00).
                $j = $i + 10;
                while ($j < $len) {
                    $subLen = ord($bytes[$j]);
                    $j++;
                    if ($subLen === 0) {
                        break; // Block terminator found.
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

        // Build a minimal single-frame GIF in memory:
        //   GIF header (13 bytes) + GCT (if present) + one GCE block + one Image Descriptor + image data + trailer.
        $gifData = '';
        // Header slice (first 13 bytes).
        $gifData .= substr($bytes, 0, 13);
        // GCT if present.
        if ($header['hasGct']) {
            $gifData .= substr($bytes, 13, $header['gctBytes']);
        }
        // GCE block for this frame (if delay > 0 or non-default).
        // Write GCE block: 0x21 0xF9 0x04 <packed> <delayL> <delayH> <transparent> 0x00
        $delayLo = $delay & 0xFF;
        $delayHi = ($delay >> 8) & 0xFF;
        $gifData .= "\x21\xF9\x04\x00" . chr($delayLo) . chr($delayHi) . "\x00\x00";
        // Now extract the image data for this frame: skip Image Descriptor + LZW sub-blocks.
        $j = $offset + 10; // skip Image Descriptor header (10 bytes: separator + left/top/width/height + packed + LZW min)
        while ($j < strlen($bytes)) {
            $subLen = ord($bytes[$j]);
            $j++;
            if ($subLen === 0) {
                break; // Block terminator found.
            }
            $j += $subLen; // Skip the sub-block data.
        }
        $imgDataEnd = $j - 1; // $j is past the 0x00 terminator; $imgDataEnd is the terminator byte
        $frameData = substr($bytes, $offset, $imgDataEnd - $offset);
        $gifData .= $frameData;
        // GIF trailer.
        $gifData .= "\x3B";

        $img = @imagecreatefromstring($gifData);
        if ($img === false) {
            return null;
        }
        $frame = self::sample($img, $cellsW, $cellsH, $delay);
        return $frame;
    }

    private static function sample(\GdImage $img, int $cellsW, int $cellsH, int $delay): Frame
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $rows = [];
        for ($cy = 0; $cy < $cellsH; $cy++) {
            $row = [];
            for ($cx = 0; $cx < $cellsW; $cx++) {
                $sx = (int) (($cx + 0.5) * $w / $cellsW);
                $sy = (int) (($cy + 0.5) * $h / $cellsH);
                $rgb = imagecolorat($img, min($w - 1, $sx), min($h - 1, $sy));
                $r = ($rgb >> 16) & 0xff;
                $g = ($rgb >>  8) & 0xff;
                $b = ($rgb)       & 0xff;
                $row[] = [$r, $g, $b];
            }
            $rows[] = $row;
        }
        imagedestroy($img);
        return new Frame($rows, $delay);
    }
}
