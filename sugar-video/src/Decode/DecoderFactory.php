<?php

declare(strict_types=1);

namespace SugarCraft\Video\Decode;

use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\Probe;

/**
 * Factory for creating the appropriate Decoder based on the source file.
 *
 * Decision is made at creation time (not open time):
 *   - If source is an http(s) URL → FfmpegDecoder (a URL is fetched, never
 *     opened locally — only ffmpeg's protocols read the network, and only its
 *     open() accepts the request headers; a .gif URL is still decoded by
 *     ffmpeg, not by the in-process GIF reader)
 *   - Else if source ends with .gif (case-insensitive) → GifDecoder
 *   - Else if source is a LOCAL animated WebP (E-05.2) → AnimatedWebpDecoder
 *     (ffmpeg cannot read WebP animation at all — it skips ANMF — so the old
 *     ffmpeg route showed one silent base frame; the sniff is a header-only
 *     container walk, and a still .webp never enters this branch, keeping its
 *     ffmpeg/fallback route byte-unchanged. When this PHP's GD has no WebP,
 *     the decoder raises the loud typed refusal instead of degrading.)
 *   - Else if ffmpeg is available → FfmpegDecoder
 *   - Else → GifDecoder (fallback; will fail on non-GIF source)
 */
final class DecoderFactory
{
    /**
     * Create an appropriate decoder for the given source file.
     *
     * @param string $source Path to the video source
     * @param int $cellsW Target width in terminal cells
     * @param int $cellsH Target height in terminal cells
     * @param float $fps Target frames per second
     * @param Mode|null $mode Rendering mode (null = HalfBlock for backward compatibility)
     * @param float $startSec Seconds to seek into the source before the first frame (0 = start)
     * @param int $cellPxW Pixel width of a terminal cell (graphics modes decode at cells·cellPx)
     * @param int $cellPxH Pixel height of a terminal cell
     * @param array<array-key, string> $headers HTTP request headers for an http(s)
     *        source, forwarded verbatim to the decoder's open(). Empty for a local file.
     * @return Decoder
     */
    public static function new(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0, int $cellPxW = 10, int $cellPxH = 20, array $headers = []): Decoder
    {
        $isGif = preg_match('/\.gif$/i', $source) === 1;
        $isNetwork = FfmpegDecoder::isNetworkSource($source);

        if ($isNetwork) {
            // URLs go to ffmpeg even for .gif: GifDecoder reads local files in
            // pure PHP and has no transport, so a remote GIF handed to it would
            // die on a bogus path and its headers would have nowhere to ride.
            // Missing ffmpeg then fails loudly in FfmpegDecoder::open().
            // The same is true of a remote .webp — animated sniffing is local-only.
            $decoder = new FfmpegDecoder($cellPxW, $cellPxH);
        } elseif ($isGif) {
            $decoder = new GifDecoder($cellPxW, $cellPxH);
        } elseif (self::isLocalAnimatedWebp($source)) {
            // E-05.2: animated WebP is a GD/container job, never an ffmpeg one.
            // The decoder's open() raises the loud refusal when GD lacks WebP
            // — silently handing the file to ffmpeg would show one base frame.
            $decoder = new AnimatedWebpDecoder($cellPxW, $cellPxH);
        } elseif (Probe::hasFFmpeg()) {
            $decoder = new FfmpegDecoder($cellPxW, $cellPxH);
        } else {
            // Fallback to GIF decoder — will fail gracefully on non-GIF sources
            $decoder = new GifDecoder($cellPxW, $cellPxH);
        }

        $decoder->open($source, $cellsW, $cellsH, $fps, $mode, $startSec, $headers);
        return $decoder;
    }

    /**
     * True when $source names an existing LOCAL file with a .webp suffix
     * (case-insensitive) whose container claims animation.
     *
     * Cheap by design: the suffix test short-circuits first, so a .mp4 never
     * even opens; the sniff itself walks chunk headers only and stops at the
     * first ANMF chunk or VP8X animation bit. Static pure (path → bool),
     * unit-testable without spawning anything.
     */
    public static function isLocalAnimatedWebp(string $source): bool
    {
        if (preg_match('/\.webp$/i', $source) !== 1) {
            return false;
        }

        if (FfmpegDecoder::isNetworkSource($source)) {
            return false;
        }

        return is_file($source) && AnimatedWebpDecoder::isAnimated($source);
    }
}
