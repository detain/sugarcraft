<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Core\Util\ColorUtil;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Dither\Ditherer;
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
final class AsciiRenderer implements Renderer, AcceptsOptimization
{
    use \SugarCraft\Image\Concerns\RenderValidationTrait;
    use \SugarCraft\Image\Concerns\OptimizationCarrierTrait;

    /** Standard 15-step luminance ramp, darkest → brightest. */
    private const RAMP = ' .,:;i1tfLCG08@';

    /** C-06: dither bias strength (null = chafa's config default 1.0). */
    private readonly ?float $intensity;

    /**
     * C-06: opt-in luminance dithering — chafa dithers inside the common
     * prepare_pixels pass, BEFORE the picker thresholds the value, and this
     * renderer had no error control at all (the C-06 gap: gradients band).
     * Dither::None (default) keeps the byte-frozen cold-quantise walk; the
     * ramp default is what every existing golden pins.
     *
     * @param float|null $intensity diffusion/bias strength 0.0..1.0 (null =
     *                              1.0; above 1.0 clamps, chafa
     *                              chafa_dither_init() MIN rule)
     * @param Dither $dither        kernel for the luminance axis
     * @param bool $serpentine      C-08 snake walk over cell rows (parity
     *                              reads LOCAL row index, same documented
     *                              phase rule as the colour path)
     */
    public function __construct(
        private readonly AsciiColorMode $color = AsciiColorMode::TrueColor,
        private readonly string $ramp = self::RAMP,
        ?float $intensity = null,
        private readonly Dither $dither = Dither::None,
        private readonly bool $serpentine = false,
    ) {
        if ($ramp === '') {
            // Fail fast at construction: an empty table cannot index a glyph.
            throw new \InvalidArgumentException('ascii ramp must contain at least one character');
        }
        // Same refusal shape as the ctor above (this renderer's house style is
        // plain literal messages) and the same NaN rule as SixelRenderer:
        // PHP orders NAN above every number, so it is refused by name.
        if ($intensity !== null && (is_nan($intensity) || $intensity < 0.0)) {
            throw new \InvalidArgumentException('ascii intensity must be between 0.0 and 1.0');
        }
        $this->intensity = $intensity === null ? null : min(1.0, $intensity);
    }

    /** C-06: the diffusion kernel in force (Dither::None = cold quantise). */
    public function dither(): Dither
    {
        return $this->dither;
    }

    /** C-06: the dither bias strength in force, or null for chafa's 1.0. */
    public function intensity(): ?float
    {
        return $this->intensity;
    }

    /** C-06: true when the diffusion walk snakes (odd cell rows reversed). */
    public function serpentine(): bool
    {
        return $this->serpentine;
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
        $pen = new OutputOptimizer(repeatCells: $this->emitsRepeatCells());

        try {
            $lines = [];
            if ($this->dither === Dither::None) {
                for ($y = 0; $y < $effectiveHeight; $y++) {
                    for ($x = 0; $x < $width; $x++) {
                        [$r, $g, $b] = $this->pixelAt($scaled, $x, $y);
                        $luma = ColorUtil::luma($r, $g, $b);
                        $ch = $this->ramp[(int) round($luma / 255 * $rampMax)];

                        $pen->cell($ch, $this->penSgr($r, $g, $b));
                    }
                    $lines[] = $pen->finishRow();
                }
            } else {
                // C-06: the luminance axis is dithered BEFORE the ramp
                // threshold — the chafa prepare_pixels order — so a gradient
                // walks the ramp instead of banding. Colour pens stay RAW
                // per-pixel (the dither chooses glyphs, not tints: mixing a
                // diffused colour into the pen would smear hue across cells
                // in a renderer whose contract is one pixel per cell).
                $lumaGrid = [];
                $pixels = [];
                for ($y = 0; $y < $effectiveHeight; $y++) {
                    $lrow = [];
                    $prow = [];
                    for ($x = 0; $x < $width; $x++) {
                        [$r, $g, $b] = $this->pixelAt($scaled, $x, $y);
                        $lrow[] = ColorUtil::luma($r, $g, $b);
                        $prow[] = [$r, $g, $b];
                    }
                    $lumaGrid[] = $lrow;
                    $pixels[] = $prow;
                }

                // Bias amplitude is scaled to the ramp's own bin width, not
                // a palette's: half a bin of error cannot cross a threshold
                // unless the bias carries it, mirroring chafa's noise note
                // (±128 table × magnitude stays inside one step).
                $magnitude = ($this->intensity ?? 1.0) * ((255.0 / max(1, $rampMax)) / 256.0);
                $texture = match ($this->dither) {
                    Dither::Ordered => OrderedTexture::bayer16($magnitude),
                    Dither::Noise   => OrderedTexture::blueNoise64($magnitude),
                    default         => null,
                };

                $quantize = static function (float $v) use ($rampMax): array {
                    $idx = $rampMax === 0 ? 0 : max(0, min($rampMax, (int) round($v / 255 * $rampMax)));
                    return [$idx, $rampMax === 0 ? 0.0 : $idx / $rampMax * 255.0];
                };

                $rows = Ditherer::lumaIndexRows(
                    $lumaGrid,
                    $quantize,
                    $this->dither,
                    $this->intensity ?? 1.0,
                    $texture,
                    $this->serpentine,
                );
                foreach ($rows as $y => $idxRow) {
                    foreach ($idxRow as $x => $idx) {
                        [$r, $g, $b] = $pixels[$y][$x];
                        $pen->cell($this->ramp[$idx], $this->penSgr($r, $g, $b));
                    }
                    $lines[] = $pen->finishRow();
                }
            }
        } finally {
            imagedestroy($scaled);
        }

        return implode("\n", $lines);
    }

    /**
     * Read one scaled pixel's RGB — the shared cold/dithered sampling arm
     * (the false-index fallback is the historical cold-walk behaviour).
     *
     * @return array{int,int,int}
     */
    private function pixelAt(\GdImage $scaled, int $x, int $y): array
    {
        $idx = imagecolorat($scaled, $x, $y);
        $rgb = $idx === false ? ['red' => 0, 'green' => 0, 'blue' => 0] : imagecolorsforindex($scaled, $idx);
        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    }

    /**
     * The colour pen for one cell — same four-arm match the cold walk used,
     * extracted so the dithered walk cannot drift from it.
     */
    private function penSgr(int $r, int $g, int $b): ?string
    {
        return match ($this->color) {
            // Mono rides the optimizer too: a null pen never
            // emits a byte, so the row is pure runes as before.
            AsciiColorMode::Mono      => null,
            AsciiColorMode::TrueColor => OutputOptimizer::trueColor($r, $g, $b),
            AsciiColorMode::Ansi256   => OutputOptimizer::indexed256((new Color($r, $g, $b))->toAnsi256Index()),
            AsciiColorMode::Ansi16    => (string) Color::ansi16Sgr((new Color($r, $g, $b))->toAnsi16Index(), false),
        };
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
