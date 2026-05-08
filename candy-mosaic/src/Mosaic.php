<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\Renderer;

/**
 * Public facade — the "Picker" from ratatui-image.
 *
 * Probe the terminal once at startup, cache the protocol + font size,
 * then mint renderers from a single state object. All rendering
 * routes through a {@see Renderer} instance.
 *
 * Usage:
 *
 * ```php
 * $mosaic = Mosaic::probe();          // detect best protocol
 * $ansi   = $mosaic->render($image, width: 40, height: 20);
 *
 * $mosaic = Mosaic::halfBlock();      // force half-block
 * $mosaic = Mosaic::sixel();
 * $mosaic = Mosaic::kitty();
 * $mosaic = Mosaic::iterm2();
 * ```
 */
final class Mosaic
{
    private function __construct(
        private readonly Renderer $renderer,
        private readonly Capability $capability,
        private readonly ?int $forcedWidth,
        private readonly ?int $forcedHeight,
    ) {}

    /**
     * Probe the terminal and pick the best available protocol.
     *
     * Uses environment variables; DA1 probing is handled separately
     * (PR5). Caches the result per-process via {@see Detect::cached()}.
     */
    public static function probe(): self
    {
        $cap     = Detect::cached();
        $renderer = self::bestBackend($cap);

        return new self($renderer, $cap, null, null);
    }

    /** Force the iTerm2 / WezTerm inline-image renderer. */
    public static function iterm2(): self
    {
        return new self(
            new Iterm2Renderer(),
            Capability::universal(),
            null,
            null,
        );
    }

    /** Force the half-block Unicode renderer (always available). */
    public static function halfBlock(): self
    {
        return new self(
            new HalfBlockRenderer(),
            Capability::universal(),
            null,
            null,
        );
    }

    /**
     * Expose the detected capability snapshot.
     */
    public function capability(): Capability
    {
        return $this->capability;
    }

    /**
     * Stable backend name: 'sixel' | 'kitty' | 'iterm2' | 'halfblock'.
     */
    public function protocol(): string
    {
        return $this->renderer->name();
    }

    /**
     * Best-effort font-size derived from capability detection.
     * Returns null if font-size probing hasn't been implemented yet.
     *
     * @return array{cellWidth:int,cellHeight:int}|null
     */
    public function fontSize(): ?array
    {
        $cs = $this->capability->cellSize;
        if ($cs === null) {
            return null;
        }
        return ['cellWidth' => $cs->cellWidth, 'cellHeight' => $cs->cellHeight];
    }

    /**
     * Render the image to ANSI bytes at the given cell dimensions.
     *
     * @param ImageSource $image  Source image
     * @param int         $width  Width in terminal cells
     * @param int|null    $height Height in terminal cells
     *                            (auto-derived from aspect ratio when null)
     * @return string             Raw ANSI escape bytes
     */
    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $w = $width > 0 ? $width : 1;
        $h = $height;
        return $this->renderer->render($image, $w, $h);
    }

    /**
     * Builder for fine-grained configuration.
     *
     * ```php
     * $mosaic = Mosaic::builder()
     *     ->withRenderer(new HalfBlockRenderer())
     *     ->withResize(width: 40, height: 20)
     *     ->build();
     * ```
     */
    public static function builder(): MosaicBuilder
    {
        return new MosaicBuilder();
    }

    /**
     * Pick the best available renderer for the given capability snapshot.
     * Precedence: Kitty > iTerm2 > Sixel > HalfBlock.
     *
     * PR3 swaps Kitty renderer in; PR4 swaps Sixel renderer in.
     */
    private static function bestBackend(Capability $cap): Renderer
    {
        if ($cap->kitty) {
            // PR3: return new KittyRenderer();
            return new HalfBlockRenderer();
        }
        if ($cap->iterm2) {
            return new Iterm2Renderer();
        }
        if ($cap->sixel) {
            // PR4: return new SixelRenderer();
            return new HalfBlockRenderer();
        }

        return new HalfBlockRenderer();
    }
}

/**
 * Builder for {@see Mosaic} with optional renderer swap and dimension defaults.
 */
final class MosaicBuilder
{
    private ?Renderer $renderer = null;
    private ?int $width = null;
    private ?int $height = null;

    public function withRenderer(Renderer $renderer): self
    {
        $clone = clone $this;
        $clone->renderer = $renderer;
        return $clone;
    }

    public function withResize(int $width, ?int $height = null): self
    {
        $clone = clone $this;
        $clone->width  = $width;
        $clone->height = $height;
        return $clone;
    }

    public function build(): Mosaic
    {
        return new Mosaic(
            $this->renderer ?? new HalfBlockRenderer(),
            $this->renderer !== null
                ? Capability::universal()
                : Capability::unknown(),
            $this->width,
            $this->height,
        );
    }
}
