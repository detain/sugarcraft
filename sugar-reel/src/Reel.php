<?php

declare(strict_types=1);

namespace SugarCraft\Reel;

/**
 * Terminal video player facade — plays a video file by decoding frames on the
 * fly and rendering them to ASCII / ANSI / truecolor half-block / sixel / kitty
 * output, the way `mpv -vo tct`, `tplay`, `video-to-ascii`, and `glyph` do.
 *
 * No single upstream: the decode → render → pace pipeline draws on prior art in
 * maxcurzi/tplay, seatedro/glyph, and joelibaceta/video-to-ascii. The rendering
 * stack is reused from the SugarCraft ecosystem (candy-mosaic image renderers,
 * candy-flip downsampling, candy-palette color mapping, candy-core TEA runtime)
 * rather than reinvented.
 *
 * This is the Step 0 scaffold stub: {@see open()} records the source path but
 * decodes nothing yet. Decoding, rendering, playback, and audio sync arrive in
 * subsequent steps. State is immutable — there are no in-place mutators.
 */
final class Reel
{
    private function __construct(
        public readonly string $path,
    ) {
    }

    /**
     * Construct an empty player with no source bound yet.
     */
    public static function new(): self
    {
        return new self('');
    }

    /**
     * Open a video source by path. Does not probe or decode — it only records
     * the path so the instance can be configured before playback.
     */
    public static function open(string $path): self
    {
        return new self($path);
    }

    /**
     * The source video path this player was opened with ('' when unbound).
     */
    public function path(): string
    {
        return $this->path;
    }
}
