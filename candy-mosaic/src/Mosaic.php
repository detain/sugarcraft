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
     * Detection strategy: Primary detection via Detect::probe() which sends
     * DA1 queries to the terminal for sixel capability detection and probes
     * font size via XTWINOPS. This is the authoritative detection path.
     * If Detect::probe() throws (e.g., not a TTY, probing times out),
     * falls back to TerminalProbe capabilities from candy-palette via
     * autoFromPalette(). If that also fails, falls back to HalfBlock.
     *
     * Precedence: Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii
     *
     * @see Mosaic::diagnose() for structured probe report
     * @see Detect::probe() for the primary TTY-probing implementation
     */
    public static function auto(): self
    {
        try {
            // Detect::probe() is the primary detection path: sends DA1 queries
            // for sixel capability and XTWINOPS for font-size probing. Results
            // are cached per-process. If this succeeds we have a definitive answer.
            $cap = Detect::probe();
            $renderer = self::bestBackend($cap);

            if ($cap->inTmux) {
                $renderer = new TmuxPassthroughDecorator($renderer);
            }

            return new self($renderer, $cap, null, null, null);

        } catch (\Throwable) {
            // Detect::probe() threw (not a TTY, probing timeout, etc.).
            // Fall back to TerminalProbe from candy-palette which uses
            // environment-variable detection only (no TTY I/O). If that
            // also fails, fall back to HalfBlock (always available).
            return self::autoFromPalette();
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
     * Uses TerminalProbe to check TrueColor, Color256, and BasicAscii capabilities
     * before falling back to HalfBlock. This provides graceful degradation for
     * environments where TTY probing is not possible (daemons, CI, etc.).
     */
    private static function autoFromPalette(): self
    {
        try {
            $report = TerminalProbe::run();
        } catch (\Throwable) {
            // TerminalProbe itself threw — fall back to HalfBlock
            return self::halfBlock();
        }

        // Map palette capabilities to mosaic renderers
        // Prefer: Kitty > Sixel > ITerm2 > HalfBlock > QuarterBlock > BasicAscii

        // Check for Kitty keyboard support (implies Kitty protocol)
        if ($report->has(PaletteCapability::KittyKeyboard)) {
            // If we also have truecolor, Kitty is ideal; otherwise fall back
            $renderer = new KittyRenderer();
            $cap = Capability::kitty(null, $report->has(PaletteCapability::Color256));
            return new self($renderer, $cap, null, null, null);
        }

        // Check for iTerm2 inline image support
        if ($report->has(PaletteCapability::Iterm2Image)) {
            $renderer = new Iterm2Renderer();
            $cap = Capability::universal();
            return new self($renderer, $cap, null, null, null);
        }

        // Check for TrueColor (24-bit) support — HalfBlock with color
        if ($report->has(PaletteCapability::TrueColor)) {
            // TrueColor terminals support HalfBlock with 24-bit color
            return self::halfBlock();
        }

        // Check for 256-color support
        if ($report->has(PaletteCapability::Color256)) {
            // At least 256 colors available
            return self::halfBlock();
        }

        // NoColor terminal — still usable with HalfBlock (monochrome fallback)
        if ($report->has(PaletteCapability::NoColor)) {
            return self::halfBlock();
        }

        // For everything else (BasicAscii or unknown), HalfBlock is the safe fallback
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
     * All protocols supported by this library, in preferred order.
     *
     * Use {@see Mosaic::auto()} to probe the terminal and pick the best one,
     * or pick explicitly with {@see Mosaic::kitty()}, {@see Mosaic::sixel()}, etc.
     *
     * @return list<string>
     */
    public static function supportedProtocols(): array
    {
        return ['kitty', 'sixel', 'iterm2', 'halfblock', 'quarterblock', 'chafa'];
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
     * This is the explicit alternative to relying on build() defaulting to
     * Sixel when no renderer is set — using this factory makes the intent
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
