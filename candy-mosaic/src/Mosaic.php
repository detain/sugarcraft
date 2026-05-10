<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\Renderer\ChafaRenderer;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;

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
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Capability $capability,
        private readonly ?int $forcedWidth,
        private readonly ?int $forcedHeight,
        private ?Scale $scale,
    ) {}

    /**
     * Probe the terminal and pick the best available protocol.
     *
     * Uses environment variables; DA1 probing is handled separately
     * (PR5). Caches the result per-process via {@see Detect::cached()}.
     */
    public static function probe(): self
    {
        // Use Detect::probe() for full capability resolution including
        // DA1 querying (sixel) and XTWINOPS font-size probing.  The
        // result is cached per-process, so the TTY I/O happens once.
        $cap      = Detect::probe();
        $renderer = self::bestBackend($cap);

        // When running inside tmux, wrap all renderer output in the
        // tmux passthrough envelope so DCS/APC/OSC sequences pass
        // through to the inner terminal.
        if ($cap->inTmux) {
            $renderer = new TmuxPassthroughDecorator($renderer);
        }

        return new self($renderer, $cap, null, null, null);
    }

    /** Force the iTerm2 / WezTerm inline-image renderer. */
    public static function iterm2(): self
    {
        return new self(
            new Iterm2Renderer(),
            Capability::universal(),
            null,
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
            null,
        );
    }

    /**
     * Force the Sixel renderer with an optional dither algorithm.
     *
     * ```php
     * $mosaic = Mosaic::sixel();                        // Floyd–Steinberg (default)
     * $mosaic = Mosaic::sixel(Dither::Stucki);          // Stucki dithering
     * $mosaic = Mosaic::sixel(Dither::None);            // no dithering
     * ```
     */
    public static function sixel(Dither $dither = Dither::FloydSteinberg): self
    {
        return new self(
            new SixelRenderer($dither),
            Capability::universal(),
            null,
            null,
            null,
        );
    }

    /**
     * Force the Chafa renderer with optional CLI options.
     *
     * ```php
     * $mosaic = Mosaic::chafa();                        // 256 colors (default)
     * $mosaic = Mosaic::chafa('--colors=16', '--work=n'); // custom options
     * ```
     *
     * @param string ...$options  Chafa CLI options
     */
    public static function chafa(string ...$options): self
    {
        if ($options === []) {
            $options = ['--colors=256'];
        }

        return new self(
            new ChafaRenderer($options),
            Capability::universal(),
            null,
            null,
            null,
        );
    }

    /**
     * Return a new Mosaic with a different dither algorithm.
     * Only meaningful when the current renderer is a SixelRenderer;
     * returns the same instance otherwise.
     */
    public function withDither(Dither $dither): self
    {
        if ($this->renderer instanceof SixelRenderer) {
            return new self(new SixelRenderer($dither), $this->capability, $this->forcedWidth, $this->forcedHeight, $this->scale);
        }
        return $this;
    }

    /**
     * Expose the detected capability snapshot.
     */
    public function capability(): Capability
    {
        return $this->capability;
    }

    /**
     * Stable backend name: 'sixel' | 'kitty' | 'iterm2' | 'halfblock' | 'chafa'.
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
     * The configured scale mode, or null for the default (Fit).
     */
    public function scale(): ?Scale
    {
        return $this->scale;
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

        // Apply scale transformation before rendering.
        if ($this->scale !== null) {
            // For Fit mode: derive cell height from aspect ratio first so
            // applyScale computes the right crop/resize target.
            // For None mode with null height: use source native dimensions
            // (no letterboxing/scaling).
            // Other modes: compute cell height the same way.
            if ($h === null) {
                $h = (int) round($w / $image->aspectRatio());
            }

            // None: use source native size when no explicit height was given.
            if ($this->scale === Scale::None && $height === null) {
                $w = $image->width;
                $h = $image->height;
            }

            $image = $this->applyScale($image, $w, $h);
            // After scaling, the image fits exactly — no further aspect-ratio
            // derivation is needed; pass the computed dimensions directly.
            $h = null;
        }

        return $this->renderer->render($image, $w, $h);
    }

    /**
     * Apply the configured scale mode to the image.
     */
    private function applyScale(ImageSource $image, int $cellW, int $cellH): ImageSource
    {
        if ($cellH === null) {
            $cellH = (int) round($cellW / $image->aspectRatio());
        }

        $dims = $this->scale->computeDimensions($image->width, $image->height, $cellW, $cellH);

        // No transformation needed — return original.
        if ($dims['srcX'] === 0 && $dims['srcY'] === 0
            && $dims['srcW'] === $image->width && $dims['srcH'] === $image->height
            && $dims['dstW'] === $image->width && $dims['dstH'] === $image->height
        ) {
            return $image;
        }

        // Apply crop first (if any), then resize to destination dimensions.
        $img = $image;
        if ($dims['srcW'] < $image->width || $dims['srcH'] < $image->height) {
            $img = $img->crop($dims['srcX'], $dims['srcY'], $dims['srcW'], $dims['srcH']);
        }

        if ($dims['dstW'] !== $img->width || $dims['dstH'] !== $img->height) {
            $img = $img->resize($dims['dstW'], $dims['dstH']);
        }

        return $img;
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
     * Precedence: Kitty > iTerm2 > Sixel > Chafa > HalfBlock.
     *
     * PR4 swaps Sixel renderer in.
     */
    private static function bestBackend(Capability $cap): Renderer
    {
        if ($cap->kitty) {
            return new KittyRenderer();
        }
        if ($cap->iterm2) {
            return new Iterm2Renderer();
        }
        if ($cap->sixel) {
            return new SixelRenderer();
        }
        if ($cap->chafa) {
            return new ChafaRenderer();
        }

        return new HalfBlockRenderer();
    }

    /**
     * Set the scale mode for rendering.
     */
    public function withScale(Scale $scale): self
    {
        $clone = clone $this;
        $clone->scale = $scale;
        return $clone;
    }

    /**
     * Create a memoizing AdaptiveImage for the given source.
     *
     * The returned AdaptiveImage re-encodes on demand using this Mosaic
     * instance (so scale, dither, and tmux wrapping are all applied).
     */
    public function adaptive(ImageSource $image): AdaptiveImage
    {
        return new AdaptiveImage($image, $this);
    }

    /**
     * Render and cache one specific size as a PrecomputedImage.
     */
    public function precompute(ImageSource $image, int $width, ?int $height = null): PrecomputedImage
    {
        return $this->adaptive($image)->precompute(
            $width,
            $height ?? (int) round($width / $image->aspectRatio()),
        );
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
    private ?Dither $dither = null;
    private ?Scale $scale = null;

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

    /**
     * Set the dither algorithm for the Sixel renderer.
     * Only takes effect when the built renderer is a SixelRenderer.
     */
    public function withDither(Dither $dither): self
    {
        $clone = clone $this;
        $clone->dither = $dither;
        return $clone;
    }

    /**
     * Set the scale mode for rendering.
     */
    public function withScale(Scale $scale): self
    {
        $clone = clone $this;
        $clone->scale = $scale;
        return $clone;
    }

    /**
     * Create a memoizing AdaptiveImage for the given source.
     *
     * The returned AdaptiveImage re-encodes on demand using this Mosaic
     * instance (so scale, dither, and tmux wrapping are all applied).
     */
    public function build(): Mosaic
    {
        $renderer = $this->renderer;

        // When no renderer is specified, default to sixel with the configured
        // dither (if any); when a SixelRenderer is passed we honour its dither.
        if ($renderer === null) {
            $renderer = new SixelRenderer($this->dither ?? Dither::FloydSteinberg);
            $cap = Capability::unknown();
        } elseif ($renderer instanceof SixelRenderer && $this->dither !== null) {
            // Builder dither overrides an explicit SixelRenderer dither.
            $renderer = new SixelRenderer($this->dither);
            $cap = Capability::universal();
        } else {
            $cap = Capability::universal();
        }

        return new Mosaic($renderer, $cap, $this->width, $this->height, $this->scale);
    }
}
