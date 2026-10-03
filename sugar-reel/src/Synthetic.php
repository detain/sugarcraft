<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

/**
 * Synthetic animated test-pattern generator.
 *
 * Produces a valid GIF89a with multiple phase-shifted gradient frames so
 * that candy-flip's GifDecoder can extract and display an animated sequence.
 * This is the single source of truth for the built-in demo pattern used by
 * both Reel::new()->play() and examples/play.php.
 *
 * Falls back to a tiny 1×1 transparent GIF when ext-gd is absent, so callers
 * never fatal — the file is a valid (single-frame) GIF.
 */
final class Synthetic
{
    /** Default on-disk path for the generated demo GIF. */
    public const DEFAULT_PATH = '/tmp/sugar-reel-synthetic.gif';

    /** 1×1 transparent GIF used when GD is absent or no frames were produced. */
    private const FALLBACK_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x01\x00;";

    /**
     * Generate an animated rainbow-gradient GIF and return its path.
     *
     * The gradient hue is phase-shifted per frame so the pattern visibly
     * sweeps across the image, producing a genuine animation when decoded
     * and displayed frame-by-frame.
     *
     * @param string $path     Output file path
     * @param int    $w        Image width in pixels
     * @param int    $h        Image height in pixels
     * @param int    $frames   Number of animation frames (≥2 for animation)
     * @param int    $delayCs  Frame delay in centiseconds (1/100 second)
     * @return string The path where the GIF was written
     */
    public static function generate(
        string $path = self::DEFAULT_PATH,
        int $w = 120,
        int $h = 60,
        int $frames = 16,
        int $delayCs = 8,
    ): string {
        // GD-absent fallback: emit the same 1×1 transparent GIF bytes that
        // Reel.php has used historically.  Callers treat any returned path
        // as a valid GIF, so this single-frame fallback is safe.
        if (!extension_loaded('gd')) {
            self::writeAtomic($path, self::FALLBACK_GIF);
            return $path;
        }

        // ── Build frame payloads via GD + output-buffer ──────────────────────
        $frameBytes = [];
        for ($f = 0; $f < $frames; $f++) {
            $im = @imagecreatetruecolor($w, $h);
            if ($im === false) {
                continue;
            }
            // Phase-shifted hue sweep: B channel varies with x+y+frame offset
            // so each frame is a different moment in the color cycle. The
            // offset is f*w/frames rather than f*(int)(w/frames): with more
            // frames than pixel columns the latter steps by 0, and every
            // "animated" frame came out identical.
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $r = (int) min(255, 255 * $x / $w);
                    $g = (int) min(255, 255 * $y / $h);
                    $b = (int) min(255, 255 * (($x + $y + intdiv($f * $w, $frames)) % $w) / $w);
                    $col = @imagecolorallocate($im, $r, $g, $b);
                    if ($col !== false) {
                        imagesetpixel($im, $x, $y, $col);
                    }
                }
            }
            ob_start();
            imagegif($im, null);
            $bytes = ob_get_clean();
            imagedestroy($im);
            if (!is_string($bytes)) {
                continue;
            }
            $frameBytes[] = $bytes;
        }

        // If GD produced no usable frames, fall back to the 1×1 transparent GIF.
        if ($frameBytes === []) {
            self::writeAtomic($path, self::FALLBACK_GIF);
            return $path;
        }

        // ── Assemble GIF89a ──────────────────────────────────────────────────
        // Every frame GD emitted is a complete standalone GIF: signature,
        // Logical Screen Descriptor, its OWN quantised Global Color Table, one
        // Image Descriptor + LZW data, and a 0x3B trailer. Concatenating them
        // naively goes wrong three ways, all of which this assembly avoids:
        //   - frame 0's trailer left in place ends the stream after one frame,
        //     so every decoder (candy-flip's included) sees a still image;
        //   - each frame's indices point into that frame's palette, so frames
        //     1..n must carry their palette along as a Local Color Table —
        //     dropping it repaints them through frame 0's palette;
        //   - GD signs its output GIF87a, which has no extension blocks, but
        //     the stream carries NETSCAPE2.0 + Graphic Control Extensions.
        $delayLo = $delayCs & 0xFF;
        $delayHi = ($delayCs >> 8) & 0xFF;
        // disposal=1 (do not dispose: every frame is full-screen and opaque).
        $gce = "\x21\xF9\x04\x04" . chr($delayLo) . chr($delayHi) . "\x00\x00";

        $first = $frameBytes[0];
        $firstGct = self::gctSizeFromHeader($first);
        // NETSCAPE2.0 looping app extension; loop count 0x0000 = forever.
        $loopExt = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $gif = 'GIF89a'
            . substr($first, 6, 7 + $firstGct)
            . $loopExt;

        foreach ($frameBytes as $index => $frame) {
            $image = self::extractImage($frame, localPalette: $index > 0);
            if ($image === null) {
                continue;
            }
            $gif .= $gce . $image;
        }

        // Close with a single GIF trailer.
        $gif .= "\x3B";

        self::writeAtomic($path, $gif);

        return $path;
    }

    /**
     * Write data atomically to a path using O_EXCL + rename.
     *
     * First attempts to create a temp file with `fopen($path.'.'.getmypid().'.tmp', 'xb')`
     * which fails if the file already exists (prevents symlink attacks). On failure,
     * falls back to tempnam() so the function never fatals.
     *
     * The final rename() is atomic on POSIX so the path always holds complete data.
     */
    private static function writeAtomic(string $path, string $data): void
    {
        $dir = dirname($path) ?: sys_get_temp_dir();
        $tmp = $path . '.' . getmypid() . '.tmp';
        $fp = @fopen($tmp, 'xb');
        if ($fp === false) {
            // Path already exists or O_EXCL failed — fall back to tempnam.
            $tmp = (string) @tempnam($dir, 'reel');
            // Only tempnam()'s own result decides the fallback here: $fp is
            // necessarily false on this branch, so testing it as well sent
            // every collision to the direct write and left the fresh tempnam
            // file behind in $dir.
            if ($tmp === '') {
                // tempnam also failed — last resort: direct write (non-atomic, but better than fatal).
                file_put_contents($path, $data);
                return;
            }
            $fp = fopen($tmp, 'wb');
            if ($fp === false) {
                @unlink($tmp);
                file_put_contents($path, $data);
                return;
            }
        }
        fwrite($fp, $data);
        fclose($fp);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            file_put_contents($path, $data);
        }
    }

    /**
     * Pull the Image Descriptor, its colour table and its LZW data out of a
     * standalone single-image GIF, ready to append to an animation stream.
     *
     * With $localPalette the frame's Global Color Table is moved into a
     * Local Color Table on the descriptor (unless it already carries one),
     * because the frame's pixel indices are only meaningful against the
     * palette GD quantised that frame to. Extension blocks GD may emit ahead
     * of the descriptor are skipped — the caller writes its own GCE.
     *
     * Returns null when the frame holds no Image Descriptor.
     */
    private static function extractImage(string $frame, bool $localPalette): ?string
    {
        $len = strlen($frame);
        $gctBytes = self::gctSizeFromHeader($frame);
        $i = 13 + $gctBytes;
        while ($i < $len) {
            $block = ord($frame[$i]);
            if ($block === 0x21) {
                // Extension: introducer, label, then sub-blocks to 0x00.
                $i = self::skipSubBlocks($frame, $i + 2);
                continue;
            }
            if ($block !== 0x2C || $i + 10 > $len) {
                return null;
            }
            $packed = ord($frame[$i + 9]);
            $lctBytes = ($packed & 0x80) ? 3 * (1 << (($packed & 0x07) + 1)) : 0;
            $dataStart = $i + 10 + $lctBytes;
            // +1 skips the LZW minimum-code-size byte ahead of the sub-blocks.
            $dataEnd = self::skipSubBlocks($frame, $dataStart + 1);
            $descriptor = substr($frame, $i, 10);
            $table = substr($frame, $i + 10, $lctBytes);
            if ($localPalette && $lctBytes === 0 && $gctBytes > 0) {
                // Same size field as the GCT; set the LCT flag, keep
                // interlace, clear sort/reserved bits.
                $gctSizeBits = ord($frame[10]) & 0x07;
                $descriptor[9] = chr(0x80 | ($packed & 0x40) | $gctSizeBits);
                $table = substr($frame, 13, $gctBytes);
            }
            return $descriptor . $table . substr($frame, $dataStart, $dataEnd - $dataStart);
        }
        return null;
    }

    /**
     * Walk length-prefixed sub-blocks from $j and return the offset just past
     * the 0x00 block terminator (or the end of the string when truncated).
     */
    private static function skipSubBlocks(string $bytes, int $j): int
    {
        $len = strlen($bytes);
        while ($j < $len) {
            $subLen = ord($bytes[$j]);
            $j++;
            if ($subLen === 0) {
                break;
            }
            $j += $subLen;
        }
        return min($j, $len);
    }

    /**
     * Read the Global Color Table size from a frame's packed byte at offset 10.
     *
     * Returns 0 when GCT is absent.
     */
    private static function gctSizeFromHeader(string $frame): int
    {
        if (strlen($frame) < 13) {
            return 0;
        }
        $packed = ord($frame[10]);
        $hasGct = (bool) ($packed & 0x80);
        if (!$hasGct) {
            return 0;
        }
        $sizeExp = $packed & 0x07;
        $entryCount = 1 << ($sizeExp + 1); // 2^(exp+1) entries
        return $entryCount * 3; // 3 bytes per entry
    }
}
