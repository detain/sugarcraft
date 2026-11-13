<?php

declare(strict_types=1);

namespace SugarCraft\Image;

use SugarCraft\Image\Dither;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\Scale;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * Builder for {@see Mosaic} with optional renderer swap and dimension defaults.
 */
final class MosaicBuilder
{
    public function __construct(
        private readonly ?Renderer $renderer = null,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
        private readonly ?Dither $dither = null,
        private readonly ?Scale $scale = null,
        private readonly ?float $sixelIntensity = null,
        private readonly ?int $sixelMaxColors = null,
    ) {}

    public function withRenderer(Renderer $renderer): self
    {
        return new self($renderer, $this->width, $this->height, $this->dither, $this->scale, $this->sixelIntensity, $this->sixelMaxColors);
    }

    public function withResize(int $width, ?int $height = null): self
    {
        return new self($this->renderer, $width, $height, $this->dither, $this->scale, $this->sixelIntensity, $this->sixelMaxColors);
    }

    /**
     * Set the dither algorithm for the Sixel renderer.
     * Only takes effect when the built renderer is a SixelRenderer.
     */
    public function withDither(Dither $dither): self
    {
        return new self($this->renderer, $this->width, $this->height, $dither, $this->scale, $this->sixelIntensity, $this->sixelMaxColors);
    }

    /**
     * Set the scale mode for rendering.
     */
    public function withScale(Scale $scale): self
    {
        return new self($this->renderer, $this->width, $this->height, $this->dither, $scale, $this->sixelIntensity, $this->sixelMaxColors);
    }

    /**
     * Tune the Ordered/Noise dither bias (0.0..1.0) of the built Sixel
     * renderer; null (the unset default) leaves whatever bias the resolved
     * backend carries. Values above 1.0 clamp to full bias at the setter —
     * this is the same ladder {@see SixelRenderer::__construct()} speaks —
     * and NaN or negatives are refused there and then, not at build().
     * Only takes effect when the built renderer is a SixelRenderer.
     */
    public function withSixelIntensity(?float $intensity): self
    {
        if ($intensity !== null && \is_nan($intensity)) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.intensity_out_of_range', ['intensity' => $intensity])
            );
        }

        if ($intensity !== null && $intensity < 0.0) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.intensity_out_of_range', ['intensity' => $intensity])
            );
        }

        return new self(
            $this->renderer,
            $this->width,
            $this->height,
            $this->dither,
            $this->scale,
            $intensity === null ? null : min(1.0, $intensity),
            $this->sixelMaxColors,
        );
    }

    /**
     * Cap the palette budget (1..256 colors) of the built Sixel renderer;
     * null (the unset default) leaves whatever budget the resolved backend
     * carries — an explicitly constructed `new SixelRenderer(maxColors: 16)`
     * keeps its tuning through an untuned build. Out-of-range budgets are
     * refused at the setter with the renderer's own grammar. Only takes
     * effect when the built renderer is a SixelRenderer.
     */
    public function withSixelMaxColors(?int $maxColors): self
    {
        if ($maxColors !== null && ($maxColors < 1 || $maxColors > 256)) {
            throw new \InvalidArgumentException(
                Lang::t('sixel.max_colors_out_of_range', ['maxColors' => $maxColors])
            );
        }

        return new self(
            $this->renderer,
            $this->width,
            $this->height,
            $this->dither,
            $this->scale,
            $this->sixelIntensity,
            $maxColors,
        );
    }

    /**
     * Configure the builder to use Sixel rendering with an optional dither.
     *
     * This is the explicit way to pin Sixel — build() no longer defaults to
     * it, auto-detecting instead — so using this method makes the intent
     * unambiguous in calling code. The palette budget and bias intensity
     * are separate builder dials ({@see withSixelMaxColors()} /
     * {@see withSixelIntensity()}) and apply in any call order, so full
     * sixel tuning never needs a raw renderer constructor.
     *
     * ```php
     * $mosaic = Mosaic::builder()
     *     ->sixel()                       // Floyd–Steinberg (default)
     *     ->sixel(Dither::Stucki)         // Stucki dithering
     *     ->sixel(Dither::None)           // no dithering
     *     ->sixel(Dither::Ordered)        // ordered bias ...
     *     ->withSixelIntensity(0.8)       //     ... at 80% strength
     *     ->withSixelMaxColors(64)        //     ... on a 64-color budget
     *     ->build();
     * ```
     */
    public function sixel(Dither $dither = Dither::FloydSteinberg): self
    {
        return new self(
            new SixelRenderer($dither),
            $this->width,
            $this->height,
            $this->dither,
            $this->scale,
            $this->sixelIntensity,
            $this->sixelMaxColors,
        );
    }

    /**
     * Build the configured Mosaic.
     *
     * When no renderer was set, the terminal is auto-detected exactly as
     * {@see Mosaic::auto()} does — never silently defaulting to Sixel, which
     * would emit DCS payloads a non-Sixel terminal cannot display. Dither
     * and sixel tuning configured on the builder are honoured on any Sixel
     * backend, explicit or auto-detected, wrapped in tmux passthrough or
     * bare; on non-Sixel backends they are (as everywhere else) no-ops.
     */
    public function build(): Mosaic
    {
        $renderer = $this->renderer;

        if ($renderer === null) {
            $detected = Mosaic::auto();
            $renderer = $detected->renderer();
            $cap      = $detected->capability();
        } else {
            $cap = Capability::universal();
        }

        // Builder dials override whatever the resolved Sixel backend
        // carries; unset dials leave its own tuning intact, and non-Sixel
        // backends pass through untouched.
        $dither = $this->dither;
        if ($dither !== null) {
            $renderer = self::tuneSixel($renderer, static fn (SixelRenderer $sixel): SixelRenderer => $sixel->withDither($dither));
        }

        $intensity = $this->sixelIntensity;
        if ($intensity !== null) {
            $renderer = self::tuneSixel($renderer, static fn (SixelRenderer $sixel): SixelRenderer => $sixel->withIntensity($intensity));
        }

        $maxColors = $this->sixelMaxColors;
        if ($maxColors !== null) {
            $renderer = self::tuneSixel($renderer, static fn (SixelRenderer $sixel): SixelRenderer => $sixel->withMaxColors($maxColors));
        }

        return new Mosaic($renderer, $cap, $this->width, $this->height, $this->scale);
    }

    /**
     * Apply a sixel-only tuning closure to a Sixel backend without
     * disturbing a wrapping tmux passthrough envelope or any knob the
     * closure does not name; non-Sixel renderers are returned as-is
     * (these dials only parameterise Sixel encoding).
     */
    private static function tuneSixel(Renderer $renderer, \Closure $tune): Renderer
    {
        if ($renderer instanceof TmuxPassthroughDecorator) {
            $inner = $renderer->inner();

            return $inner instanceof SixelRenderer
                ? new TmuxPassthroughDecorator($tune($inner))
                : $renderer;
        }

        return $renderer instanceof SixelRenderer
            ? $tune($renderer)
            : $renderer;
    }
}
