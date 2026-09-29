<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

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
    ) {}

    public function withRenderer(Renderer $renderer): self
    {
        return new self($renderer, $this->width, $this->height, $this->dither, $this->scale);
    }

    public function withResize(int $width, ?int $height = null): self
    {
        return new self($this->renderer, $width, $height, $this->dither, $this->scale);
    }

    /**
     * Set the dither algorithm for the Sixel renderer.
     * Only takes effect when the built renderer is a SixelRenderer.
     */
    public function withDither(Dither $dither): self
    {
        return new self($this->renderer, $this->width, $this->height, $dither, $this->scale);
    }

    /**
     * Set the scale mode for rendering.
     */
    public function withScale(Scale $scale): self
    {
        return new self($this->renderer, $this->width, $this->height, $this->dither, $scale);
    }

    /**
     * Configure the builder to use Sixel rendering with an optional dither.
     *
     * This is the explicit way to pin Sixel — build() no longer defaults to
     * it, auto-detecting instead — so using this method makes the intent
     * unambiguous in calling code.
     *
     * ```php
     * $mosaic = Mosaic::builder()
     *     ->sixel()                       // Floyd–Steinberg (default)
     *     ->sixel(Dither::Stucki)         // Stucki dithering
     *     ->sixel(Dither::None)           // no dithering
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
        );
    }

    /**
     * Build the configured Mosaic.
     *
     * When no renderer was set, the terminal is auto-detected exactly as
     * {@see Mosaic::auto()} does — never silently defaulting to Sixel, which
     * would emit DCS payloads a non-Sixel terminal cannot display. A dither
     * configured on the builder is honoured on any Sixel backend, explicit
     * or auto-detected, wrapped in tmux passthrough or bare.
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

        // Builder dither overrides whatever dither the resolved Sixel backend
        // carries; on non-Sixel backends it is (as everywhere else) a no-op.
        if ($this->dither !== null) {
            $renderer = self::applyDither($renderer, $this->dither);
        }

        return new Mosaic($renderer, $cap, $this->width, $this->height, $this->scale);
    }

    /**
     * Swap the dither of a Sixel backend without disturbing a wrapping
     * tmux passthrough envelope or the renderer's own colour/cell tuning;
     * non-Sixel renderers are returned as-is (dither only parameterises
     * Sixel encoding).
     */
    private static function applyDither(Renderer $renderer, Dither $dither): Renderer
    {
        if ($renderer instanceof TmuxPassthroughDecorator) {
            $inner = $renderer->inner();

            return $inner instanceof SixelRenderer
                ? new TmuxPassthroughDecorator($inner->withDither($dither))
                : $renderer;
        }

        return $renderer instanceof SixelRenderer
            ? $renderer->withDither($dither)
            : $renderer;
    }
}
