<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Lang;
use SugarCraft\Image\Support\OutputOptimizer;
use SugarCraft\ColorProfile\Color;

/**
 * ASCII / ANSI renderer — maps each pixel to a luminance character, optionally
 * tinted (see {@see AsciiColorMode}).
 *
 * Unlike the half/quarter-block renderers (which pack 2 or 4 pixels into one
 * cell), this maps **one source pixel to one terminal cell**, so the output is
 * one terminal line per pixel row — a true text grid. That makes it cheap, it
 * works in any terminal (Mono needs no colour at all), and crucially it *tiles*:
 * a poster grid can stitch these cards side by side just like the block
 * renderers, which the pixel-graphics protocols (sixel/kitty/iTerm2) cannot.
 *
 * Luminance is BT.601: `Y = (77R + 150G + 29B) >> 8`. Rows are joined with
 * `"\n"` (never `"\r\n"` — a stray carriage return collapses a stitched rail).
 *
 * The luminance ramp is injectable (A-11): sugar-video's renderer bridge threads
 * user-selected character tables (minimal/dense) through here so the canonical
 * quantizer stays the single implementation. The default ramp is byte-frozen —
 * every existing golden pins it.
 */
final class AsciiRenderer implements Renderer
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;

    /** Standard 15-step luminance ramp, darkest → brightest. */
    private const RAMP = ' .,:;i1tfLCG08@';

    public function __construct(
        private readonly AsciiColorMode $color = AsciiColorMode::TrueColor,
        private readonly string $ramp = self::RAMP,
    ) {
        if ($ramp === '') {
            // Fail fast at construction: an empty table cannot index a glyph.
            throw new \InvalidArgumentException('ascii ramp must contain at least one character');
        }
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $src = imagecreatefromstring($image->bytes);
        if ($src === false) {
            throw new \RuntimeException(Lang::t('renderer.gd_load_failed'));
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        // One pixel per cell: resample to exactly width × effectiveHeight.
        $scaled = imagecreatetruecolor($width, $effectiveHeight);
        if ($scaled === false) {
            imagedestroy($src);
            throw new \RuntimeException(Lang::t('renderer.gd_resize_failed'));
        }
        imagecopyresampled(
            $scaled, $src,
            0, 0, 0, 0,
            $width, $effectiveHeight,
            imagesx($src), imagesy($src),
        );
        imagedestroy($src);

        $rampMax = strlen($this->ramp) - 1;
        $pen = new OutputOptimizer();

        try {
            $lines = [];
            for ($y = 0; $y < $effectiveHeight; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $idx = imagecolorat($scaled, $x, $y);
                    $rgb = $idx === false ? ['red' => 0, 'green' => 0, 'blue' => 0] : imagecolorsforindex($scaled, $idx);
                    $r = $rgb['red'];
                    $g = $rgb['green'];
                    $b = $rgb['blue'];

                    $luma = ColorUtil::luma($r, $g, $b);
                    $ch = $this->ramp[(int) round($luma / 255 * $rampMax)];

                    $pen->cell($ch, match ($this->color) {
                        // Mono rides the optimizer too: a null pen never
                        // emits a byte, so the row is pure runes as before.
                        AsciiColorMode::Mono      => null,
                        AsciiColorMode::TrueColor => OutputOptimizer::trueColor($r, $g, $b),
                        AsciiColorMode::Ansi256   => OutputOptimizer::indexed256((new Color($r, $g, $b))->toAnsi256Index()),
                        AsciiColorMode::Ansi16    => (string) Color::ansi16Sgr((new Color($r, $g, $b))->toAnsi16Index(), false),
                    });
                }
                $lines[] = $pen->finishRow();
            }
        } finally {
            imagedestroy($scaled);
        }

        return implode("\n", $lines);
    }

    /**
     * A-06: the protocol slot carries the protocol, not the colour ramp.
     * All three AsciiColorMode arms encode through the same ASCII-symbol
     * protocol, so name() is the constant 'ascii'; the colour depth a
     * particular renderer instance encodes at is its own axis, exposed by
     * {@see colorMode()} for cache-key discriminators and future callers.
     */
    public function name(): string
    {
        return 'ascii';
    }

    /**
     * The colour-ramp mode this renderer encodes with (Mono/Ansi16/Ansi256/TrueColor).
     * A-06 relocated this value out of {@see name()} so protocol() stops
     * leaking 'ansi256'/'truecolor' into the PROTOCOL slot; anything that
     * previously differentiated ascii variants by name() must key on this.
     */
    public function colorMode(): AsciiColorMode
    {
        return $this->color;
    }

    public function supportsAlpha(): bool
    {
        return false;
    }

    public function isInline(): bool
    {
        return true;
    }

    /** Plain-text SGR output — no stored image identity to delete. */
    public function delete(string $imageId): string
    {
        return '';
    }
}
