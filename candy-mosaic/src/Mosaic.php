<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

use SugarCraft\Mosaic\Renderer\AsciiColorMode;
use SugarCraft\Mosaic\Renderer\AsciiRenderer;
use SugarCraft\Mosaic\Renderer\ChafaRenderer;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\KittyRenderer;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer;
use SugarCraft\Mosaic\Renderer\Renderer;
use SugarCraft\Mosaic\Renderer\SixelRenderer;
use SugarCraft\Mosaic\Dither;
use SugarCraft\Mosaic\Scale;
use SugarCraft\Mosaic\TmuxPassthroughDecorator;
use SugarCraft\Palette\Probe\TerminalProbe;
use SugarCraft\Palette\Probe\ProbeReport;
use SugarCraft\Palette\Probe\Capability as PaletteCapability;

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
    /**
     * Approximate height:width ratio of a terminal cell (cells are ~twice as
     * tall as they are wide in a typical monospace font). Used by cover scaling
     * to crop to the cell box's true display aspect rather than treating cells
     * as square.
     */
    private const CELL_ASPECT = 2.0;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly Capability $capability,
        private readonly ?int $forcedWidth,
        private readonly ?int $forcedHeight,
        private readonly ?Scale $scale,
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

    /**
     * Auto-detect the best renderer using TerminalProbe.
     *
     * NEVER throws — falls back to BasicAscii (HalfBlock) on every error.
     * This is the safe, user-friendly entry point for new users.
     *
     * Precedence: Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii
     *
     * @see Mosaic::diagnose() for structured probe report
     */
    public static function auto(): self
    {
        try {
            $report = TerminalProbe::run();

            // Try to use Detect::probe() for full protocol detection first
            // since it has DA1 sixel probing and better terminal detection
            try {
                $cap = Detect::probe();
                $renderer = self::bestBackend($cap);

                if ($cap->inTmux) {
                    $renderer = new TmuxPassthroughDecorator($renderer);
                }

                return new self($renderer, $cap, null, null, null);
            } catch (\Throwable) {
                // @codeCoverageIgnoreStart — Detect::probe() never throws; this block is
            // here for future-proofing but is unreachable with the current implementation.
            // If Detect::probe() is ever refactored to throw, this provides a clean fallback.
            // @codeCoverageIgnoreEnd
            }

            // Fallback: use TerminalProbe capabilities from candy-palette
            // Pick best renderer based on palette capabilities
            return self::autoFromPalette($report);

        } catch (\Throwable) {
            // TerminalProbe::run() itself threw — never let that bubble up
            return self::halfBlock();
        }
    }

    /**
     * Run the terminal capability probe and return a structured report.
     *
     * Useful for debugging: "why is my terminal not rendering Sixel?"
     *
     * @see TerminalProbe::run()
     */
    public static function diagnose(): ProbeReport
    {
        return TerminalProbe::run();
    }

    /**
     * Auto-detect using candy-palette's TerminalProbe when Detect::probe() is unavailable.
     *
     * @param ProbeReport $report  The capability report from TerminalProbe
     */
    private static function autoFromPalette(ProbeReport $report): self
    {
        // Map palette capabilities to mosaic renderers
        // Prefer: Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii

        // Check for Kitty keyboard support (implies Kitty protocol)
        if ($report->has(PaletteCapability::KittyKeyboard)) {
            // If we also have truecolor, Kitty is ideal
            $renderer = new KittyRenderer();
            $cap = Capability::kitty(null, $report->has(PaletteCapability::Color256));
            return new self($renderer, $cap, null, null, null);
        }

        // Check for Sixel via terminfo or explicit detection
        // Note: Sixel needs actual DA1 probing which Detect::probe() handles
        // If we're in this path, Detect::probe() already failed, so use HalfBlock
        if ($report->has(PaletteCapability::NoColor)) {
            return self::halfBlock();
        }

        // For everything else, HalfBlock is the safe fallback
        return self::halfBlock();
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

    /** Force the quarter-block Unicode renderer (higher density than half-block). */
    public static function quarterBlock(): self
    {
        return new self(
            new QuarterBlockRenderer(),
            Capability::universal(),
            null,
            null,
            null,
        );
    }

    /**
     * Force the ASCII / ANSI character-ramp renderer (one pixel per cell).
     *
     * ```php
     * $mosaic = Mosaic::ascii();                            // monochrome chars
     * $mosaic = Mosaic::ascii(AsciiColorMode::Ansi256);     // 256-colour chars
     * $mosaic = Mosaic::ascii(AsciiColorMode::TrueColor);   // 24-bit-colour chars
     * ```
     */
    public static function ascii(AsciiColorMode $color = AsciiColorMode::Mono): self
    {
        return new self(
            new AsciiRenderer($color),
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
     * Stable backend name: 'sixel' | 'kitty' | 'iterm2' | 'halfblock' | 'quarterblock' | 'chafa'.
     */
    public function protocol(): string
    {
        return $this->renderer->name();
    }

    /**
     * True if {@see render()} produces inline cell text that can be placed
     * directly in a text frame (half/quarter-block, ASCII); false for a
     * pixel-graphics blob (Sixel/Kitty/iTerm2) that must be painted as an
     * out-of-band overlay. See {@see Renderer::isInline()}.
     */
    public function isInline(): bool
    {
        return $this->renderer->isInline();
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
            // Cover modes (Fill/Crop) keep their cropped full-resolution image and
            // render exactly $w×$h cells, so the explicit height is preserved. The
            // other modes resized the image to fit, so re-derive height from its
            // (now-final) aspect ratio.
            if ($this->scale !== Scale::Fill && $this->scale !== Scale::Crop) {
                $h = null;
            }
        }

        return $this->renderer->render($image, $w, $h);
    }

    /**
     * Apply the configured scale mode to the image.
     */
    private function applyScale(ImageSource $image, int $cellW, int $cellH): ImageSource
    {
        $cover = $this->scale === Scale::Fill || $this->scale === Scale::Crop;

        // A terminal cell is about CELL_ASPECT× taller than it is wide. Cover
        // modes therefore crop to the cell box's *display* aspect (cellW : cellH·
        // CELL_ASPECT), not to cellW:cellH — otherwise a portrait poster is
        // squashed into a near-square crop. The renderer's own sub-pixel grid
        // (half-block cellH·2, quarter-block cellH·2, sixel cellH·fontH) lines up
        // with this, so the result is undistorted.
        $cropCellH = $cover ? (int) round($cellH * self::CELL_ASPECT) : $cellH;

        $dims = $this->scale->computeDimensions($image->width, $image->height, $cellW, $cropCellH);

        // No transformation needed — return original.
        if ($dims['srcX'] === 0 && $dims['srcY'] === 0
            && $dims['srcW'] === $image->width && $dims['srcH'] === $image->height
            && $dims['dstW'] === $image->width && $dims['dstH'] === $image->height
        ) {
            return $image;
        }

        // Apply crop first (if any).
        $img = $image;
        if ($dims['srcW'] < $image->width || $dims['srcH'] < $image->height) {
            $img = $img->crop($dims['srcX'], $dims['srcY'], $dims['srcW'], $dims['srcH']);
        }

        // Cover modes keep FULL resolution: every renderer downsamples the image
        // to its own pixel grid, so pre-resizing to the cell count here would
        // throw away all detail and leave each renderer upscaling a tiny image
        // (the reason posters looked like coarse blocks in every mode). The
        // non-cover modes still resize as the scale dictates.
        if (!$cover && ($dims['dstW'] !== $img->width || $dims['dstH'] !== $img->height)) {
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
        return new self($this->renderer, $this->capability, $this->forcedWidth, $this->forcedHeight, $scale);
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
        $clone = new self();
        $clone->renderer = $renderer;
        $clone->width    = $this->width;
        $clone->height   = $this->height;
        $clone->dither   = $this->dither;
        $clone->scale    = $this->scale;
        return $clone;
    }

    public function withResize(int $width, ?int $height = null): self
    {
        $clone = new self();
        $clone->renderer = $this->renderer;
        $clone->width    = $width;
        $clone->height   = $height;
        $clone->dither   = $this->dither;
        $clone->scale    = $this->scale;
        return $clone;
    }

    /**
     * Set the dither algorithm for the Sixel renderer.
     * Only takes effect when the built renderer is a SixelRenderer.
     */
    public function withDither(Dither $dither): self
    {
        $clone = new self();
        $clone->renderer = $this->renderer;
        $clone->width    = $this->width;
        $clone->height   = $this->height;
        $clone->dither   = $dither;
        $clone->scale    = $this->scale;
        return $clone;
    }

    /**
     * Set the scale mode for rendering.
     */
    public function withScale(Scale $scale): self
    {
        $clone = new self();
        $clone->renderer = $this->renderer;
        $clone->width    = $this->width;
        $clone->height   = $this->height;
        $clone->dither   = $this->dither;
        $clone->scale    = $scale;
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
