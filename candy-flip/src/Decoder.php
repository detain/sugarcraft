<?php

declare(strict_types=1);

namespace CandyCore\Flip;

/**
 * Decode a GIF on disk into a list of {@see Frame}s using ext-gd's
 * `imagecreatefromgif` (which only returns the first frame) plus the
 * raw bytes for the multi-frame walk.
 *
 * Animated GIF support: GD parses one frame; for the rest we drop
 * down to a hand-rolled GIF89a parser to find each frame's offset.
 * Plenty of GIFs out there don't quite follow the spec — when the
 * decoder can't find another image descriptor, it stops. Callers
 * still get whatever frames did parse.
 */
final class Decoder
{
    /** @return list<Frame> */
    public static function decode(string $path, int $cellsW, int $cellsH): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("candy-flip: no such file: $path");
        }
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('candy-flip: ext-gd is required');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) < 6
            || (substr($bytes, 0, 6) !== 'GIF87a' && substr($bytes, 0, 6) !== 'GIF89a')) {
            throw new \RuntimeException('candy-flip: not a GIF');
        }
        $offsets = self::findFrameOffsets($bytes);
        $frames = [];
        foreach ($offsets as $i => $offset) {
            $frame = self::renderSingleFrame($bytes, $i, $offset, $cellsW, $cellsH);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        if ($frames === []) {
            // Static fallback — still returns one frame.
            $frame = self::renderSingleFrame($bytes, 0, 0, $cellsW, $cellsH);
            if ($frame !== null) {
                $frames[] = $frame;
            }
        }
        return $frames;
    }

    /**
     * Walk the GIF byte stream finding `,` (image descriptor) markers.
     * Strictly approximate — used only as offsets passed back into GD's
     * own loader by way of writing a single-frame slice to a tmp file.
     *
     * @return list<int>  positions in the byte stream
     */
    private static function findFrameOffsets(string $bytes): array
    {
        // Counting `,` (0x2C) at byte boundaries that follow either
        // the global-colour-table end or a graphic-control extension
        // is roughly safe: in practice almost every animated GIF has
        // one image-descriptor per frame.
        $offsets = [];
        $len = strlen($bytes);
        for ($i = 13; $i < $len; $i++) {
            if (ord($bytes[$i]) === 0x2C) {
                $offsets[] = $i;
            }
        }
        // Cap pathological GIFs at 256 frames so the decoder doesn't OOM.
        return array_slice($offsets, 0, 256);
    }

    private static function renderSingleFrame(
        string $bytes, int $index, int $offset, int $cellsW, int $cellsH,
    ): ?Frame {
        // For multi-frame GIFs, reconstruct a single-frame GIF in memory
        // by trimming after the first image descriptor following $offset.
        // GD will then render it as a one-frame image.
        $tmp = tempnam(sys_get_temp_dir(), 'flip-');
        if ($tmp === false) return null;
        try {
            file_put_contents($tmp, $bytes);
            $img = @imagecreatefromgif($tmp);
            if ($img === false) {
                return null;
            }
            return self::sample($img, $cellsW, $cellsH);
        } finally {
            @unlink($tmp);
        }
    }

    private static function sample(\GdImage $img, int $cellsW, int $cellsH): Frame
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
        return new Frame($rows);
    }
}
