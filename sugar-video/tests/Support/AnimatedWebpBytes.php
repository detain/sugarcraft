<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests\Support;

/**
 * E-05: byte-level animated WebP builders for the decoder tests.
 *
 * The STILL bitstreams embedded here were produced ONCE with the host's real
 * ffmpeg 6.1.1 (`-f lavfi -i color=...:size=32x32 -c:v libwebp -q:v 75`) and
 * transcribed to hex constants: they are lossy VP8 encodes whose exact bytes
 * depend on that libwebp build, which is why they ride as literals instead of
 * committed binaries. NOTHING animates through ffmpeg here — the animation
 * container is assembled by {@see animContainer()} per the RFC-9649/ANMF
 * layout the decoder documents (verified field-for-field against a real
 * ffmpeg+libwebp WebPAnimEncoder output; see the lane report). Full-canvas
 * offsets are the one value where the spec's half-unit encoding and ffmpeg's
 * own muxer agree trivially (0), so the builder sidesteps that swamp.
 *
 * Frame geometry note baked into the literals: the 32x32 stills decode to
 * 32x32; the ALPH-carrying one is 16x16 mid-blue at ~50% alpha; the small
 * one is an opaque 16x16 red.
 */
final class AnimatedWebpBytes
{
    /** 32x32 opaque red (lossy VP8; corner pixel measures 0xFE0001). */
    public const RED_32 = '524946464c0000005745425056503820400000005003009d012a200020003e91429c4a25a3a2a1a80800b012096500c6aa800040511c0000feeed3dffee3b0375e2dfffb9c0ffb9c0ffb9c0fe36ca427670a0000';

    /** 32x32 opaque blue (corner pixel 0x0000FF family). */
    public const BLUE_32 = '524946464a00000057454250565038203e0000001003009d012a200020003e91429c4a25a3a2a1a80800b012096500cc1c16a96a0000fef558bfffb9c0fffd9c0fffd9c0fe3aff5ea96b938e02cb78000000';

    /** 32x32 opaque green. */
    public const GREEN_32 = '524946464800000057454250565038203c0000003003009d012a200020003e91429c4a25a3a2a1a80800b012096500c902800040505000feeed3dffc5b9fb75d21fff8b43ad0eb434451489f08000000';

    /** 16x16 mid-blue at ~50% alpha (VP8X + ALPH + VP8). */
    public const BLUE_ALPHA_16 = '524946465c00000057454250565038580a000000100000000f00000f0000414c50480a0000000107d0bf88084444ff03565038202c0000009001009d012a1000100002003425a00274ba00039800fef9936fff901fff901fff901fff203fe2177b203000';

    /** 16x16 opaque red. */
    public const RED_16 = '524946463c000000574542505650382030000000d001009d012a1000100002003425a00274ba01f80003b000fef0e8f7ff20b96175c8d7ff203fe407fc80fff8f2000000';

    /**
     * The chunk-sequence body of a still WebP (everything after RIFF/WEBP),
     * with a leading VP8X chunk dropped — inside an ANMF the canvas geometry
     * lives in the ANMF header, and libwebp's encoder does not repeat a VP8X
     * per frame.
     */
    public static function innerChunks(string $stillWebp): string
    {
        $body = substr($stillWebp, 12);
        if (str_starts_with($body, 'VP8X')) {
            $size = unpack('V', substr($body, 4, 4))[1];
            $body = substr($body, 8 + $size + ($size & 1));
        }

        return $body;
    }

    /**
     * Build a spec-true animated WebP container: VP8X(+anim bit, canvas-1
     * raw) + ANIM(bg, loop) + one ANMF per frame.
     *
     * @param list<array{still:string,x?:int,y?:int,w?:int,h?:int,duration?:int,blend?:bool,dispose?:bool}> $frames
     *        still = full still-WebP bytes; x/y are ACTUAL pixel offsets
     *        (stored halved); w/h override the declared box (defaults to the
     *        canvas; the decoder ignores the field either way — pinned);
     *        blend true sets the no-blend bit (raw copy), matching how
     *        ffmpeg/libwebp flags opaque color frames.
     * @param int $loop ANIM loop count (0 = forever)
     */
    public static function animContainer(int $canvasW, int $canvasH, array $frames, int $loop = 0): string
    {
        $chunks = 'VP8X' . pack('V', 10) . chr(0x02) . "\x00\x00\x00"
            . substr(pack('V', $canvasW - 1), 0, 3)
            . substr(pack('V', $canvasH - 1), 0, 3);

        $chunks .= 'ANIM' . pack('V', 6) . "\x00\x00\x00\x00" . pack('v', $loop);

        foreach ($frames as $frame) {
            $inner = self::innerChunks($frame['still']);
            $flags = (($frame['blend'] ?? false) ? 0x02 : 0x00)
                | (($frame['dispose'] ?? false) ? 0x04 : 0x00);
            // The width/height fields carry the ffmpeg-style raw (w-1) of the
            // declared box; the decoder provably ignores them (a pin sets
            // them to 255 and still decodes) — kept only for container truth.
            $w = (int) ($frame['w'] ?? $canvasW);
            $h = (int) ($frame['h'] ?? $canvasH);
            $payload = self::le24((int) ($frame['x'] ?? 0) >> 1)
                . self::le24((int) ($frame['y'] ?? 0) >> 1)
                . self::le24(max(0, $w - 1))
                . self::le24(max(0, $h - 1))
                . self::le24((int) ($frame['duration'] ?? 100))
                . chr($flags)
                . $inner;

            $chunks .= 'ANMF' . pack('V', strlen($payload)) . $payload . (strlen($payload) % 2 === 1 ? "\x00" : '');
        }

        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    /** @return string 24-bit little-endian of an int */
    private static function le24(int $v): string
    {
        return chr($v & 0xFF) . chr(($v >> 8) & 0xFF) . chr(($v >> 16) & 0xFF);
    }

    /**
     * Build a WebP container from raw chunk specs — for malformed/negative
     * tests (truncation, VP8X-claims-animation-without-ANMF, exotic sizes).
     *
     * @param list<array{fourcc:string,payload:string}> $chunks
     */
    public static function container(array $chunks): string
    {
        $body = '';
        foreach ($chunks as $chunk) {
            $body .= $chunk['fourcc'] . pack('V', strlen($chunk['payload'])) . $chunk['payload']
                . (strlen($chunk['payload']) % 2 === 1 ? "\x00" : '');
        }

        return 'RIFF' . pack('V', 4 + strlen($body)) . 'WEBP' . $body;
    }

    /** Write bytes to a temp .webp under a unique per-test dir; returns the path. */
    public static function write(string $bytes, string $name): string
    {
        $dir = sys_get_temp_dir() . '/reel-webp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException("could not create fixture dir {$dir}");
        }

        $path = $dir . '/' . $name;
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("could not write fixture {$path}");
        }

        return $path;
    }
}
