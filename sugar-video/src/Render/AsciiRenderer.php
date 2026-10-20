<?php

declare(strict_types=1);

namespace SugarCraft\Video\Render;

use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\AsciiColorMode;
use SugarCraft\Image\Renderer\AsciiRenderer as ImageAsciiRenderer;
use SugarCraft\Video\Decode\RgbFrame;

/**
 * A-11: ASCII / ANSI renderer — thin bridge over sugar-image's canonical AsciiRenderer.
 *
 * Formerly a full independent copy (own pen-delta emitters, full-ESC[0m row
 * resets, floor-quantized LUT glyphs, "\r\n" joins) while the HalfBlock and
 * QuarterBlock siblings already delegated. Now the canonical library owns the
 * single ascii implementation: round-to-nearest glyph quantization, the A-01
 * pen-delta with blank-cell copy-through, the A-13/A-14 scoped row-end
 * restore, and the "\n" join shared by every text protocol.
 *
 * Frames cross the bridge through {@see ImageSource::fromRgb()} rather than a
 * toGd() round-trip: rgb24 bytes map pixel-exactly, and an undersized buffer
 * fails loudly where the legacy path painted silent black columns — the
 * documented RgbFrame contract already demands exactly w*h*3 bytes. ext-gd is
 * enforced transitively by sugar-image's composer requirement.
 *
 * The ramp name ('standard'/'minimal'/'dense') resolves here to its character
 * table and threads through the canonical ramp knob, keeping Reel::withRamp()
 * a live feature without re-forking the quantizer.
 *
 * No single upstream — drawn from maxcurzi/tplay, seatedro/glyph, joelibaceta/video-to-ascii.
 */
final class AsciiRenderer implements FrameRenderer
{
    public function __construct(private readonly string $ramp = 'standard')
    {
    }

    /**
     * A-06 accessor route: the video Mode enum projects onto the canonical
     * colour-depth enum. Graphics modes never reach this renderer (the factory
     * routes them elsewhere), so they default to Mono — mirroring the legacy
     * match's default arm, which emitted plain characters for them too.
     */
    public static function colorModeFor(Mode $mode): AsciiColorMode
    {
        return match ($mode) {
            Mode::Ansi256   => AsciiColorMode::Ansi256,
            Mode::TrueColor => AsciiColorMode::TrueColor,
            default         => AsciiColorMode::Mono,
        };
    }

    /**
     * @inheritDoc
     */
    public function render(RgbFrame $frame, Mode $mode): string
    {
        if ($frame->w <= 0 || $frame->h <= 0) {
            return '';
        }

        $source = ImageSource::fromRgb($frame->bytes, $frame->w, $frame->h);

        return (new ImageAsciiRenderer(self::colorModeFor($mode), self::rampChars($this->ramp)))
            ->render($source, $frame->w, $frame->h);
    }

    /**
     * Ramp name → ramp characters. Unknown names fall back to the standard
     * table exactly as {@see LumaRamp::char()} always has.
     */
    private static function rampChars(string $ramp): string
    {
        return match ($ramp) {
            'minimal'  => LumaRamp::CHARS1,
            'dense'    => LumaRamp::CHARS3,
            default    => LumaRamp::CHARS2,
        };
    }

    /**
     * @inheritDoc
     */
    public function cellDimensions(Mode $mode): array
    {
        // All ASCII modes render one source pixel per terminal cell.
        return ['w' => 1, 'h' => 1];
    }
}
