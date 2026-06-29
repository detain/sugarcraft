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
            // so each frame is a different moment in the color cycle.
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $r = (int) min(255, 255 * $x / $w);
                    $g = (int) min(255, 255 * $y / $h);
                    $b = (int) min(255, 255 * (($x + $y + $f * (int) ($w / $frames)) % $w) / $w);
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
        // Header (first frame) gives us the Logical Screen Descriptor + GCT.
        $gif = $frameBytes[0];

        // Inject NETSCAPE2.0 looping app extension so the player restarts.
        // Bytes: 0x21 0xFF 0x0B "NETSCAPE2.0" 0x03 0x01 <loop_count_lo> <loop_count_hi> 0x00
        // loop_count 0x0000 means "loop forever".
        $loopExt = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        // Insert after the 13-byte header + GCT (if present).
        // The header is always 13 bytes; GCT follows at byte 13.
        // We need to find where the first frame's Image Descriptor starts
        // so we can splice the loop extension in front of it.
        $firstImgDescOffset = 13 + self::gctSizeFromHeader($gif);
        $gif = substr($gif, 0, $firstImgDescOffset)
            . $loopExt
            . substr($gif, $firstImgDescOffset);

        // Walk remaining frames: extract GCE + Image Descriptor + LZW data,
        // and append to the main gif.  The trailer from each individual frame
        // is discarded; we will write a single trailer at the end.
        $delayLo = $delayCs & 0xFF;
        $delayHi = ($delayCs >> 8) & 0xFF;

        for ($f = 1; $f < $frames; $f++) {
            $frame = $frameBytes[$f];
            // Skip the 13-byte header and any GCT in this frame.
            $frameDataOffset = 13 + self::gctSizeFromHeader($frame);
            // The trailer (0x3B) is the last byte; strip it.
            $frameData = substr($frame, $frameDataOffset, -1);

            // Build a Graphic-Control-Extension for this frame.
            $gce = "\x21\xF9\x04\x00" // disposal=0, transparent=0
                . chr($delayLo) . chr($delayHi)
                . "\x00\x00";

            $gif .= $gce . $frameData;
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
            if ($tmp === '' || $fp === false) {
                // tempnam also failed — last resort: direct write (non-atomic, but better than fatal).
                file_put_contents($path, $data);
                return;
            }
            $fp = fopen($tmp, 'wb');
            if ($fp === false) {
                file_put_contents($path, $data);
                return;
            }
        }
        fwrite($fp, $data);
        fclose($fp);
        rename($tmp, $path);
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
