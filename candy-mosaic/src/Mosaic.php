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
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

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

    /**
     * Height sentinel for {@see poster()}/{@see posterAsync()}/{@see posterFile()}
     * cache keys when the caller lets the aspect ratio derive the cell height —
     * the key must still be a stable integer.
     */
    private const AUTO_HEIGHT = -1;

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
     * Full capability resolution every call: environment variables, DA1
     * sixel query and XTWINOPS font-size probing via {@see Detect::probe()}.
     * Wrap in {@see Detect::cached()} at the application boundary when the
     * TTY round-trips should happen exactly once per process.
     *
     * Mirrors charmbracelet/x/mosaic Detect+best-backend selection.
     */
    public static function probe(): self
    {
        // Use Detect::probe() for full capability resolution including
        // DA1 querying (sixel) and XTWINOPS font-size probing.
        return self::fromCapability(Detect::probe());
    }

    /**
     * Auto-detect the best renderer.
     *
     * NEVER throws — falls back to HalfBlock on every error.
     * This is the safe, user-friendly entry point for new users.
     *
     * Detection strategy: one path, two stages.
     *
     *  1. {@see Detect::probe()} is the authoritative stage — environment
     *     variables plus DA1/XTWINOPS TTY queries. If it identifies any
     *     graphics protocol (Kitty, iTerm2, Sixel, Chafa) that answer wins.
     *  2. Only when Detect finds no graphics protocol at all does the
     *     explicit fallback run: candy-palette's {@see TerminalProbe}
     *     environment-derived {@see ProbeReport}, which can still spot a
     *     Kitty-keyboard- or iTerm2-advertising terminal Detect's env table
     *     missed. Its result feeds the same {@see self::fromCapability()}
     *     selection; if the report yields nothing either, the Detect
     *     snapshot stands and HalfBlock (always available) is chosen.
     *
     * Precedence: kitty → iterm2 → sixel → chafa → halfblock
     *
     * @see Mosaic::diagnose() for structured probe report
     * @see Detect::probe() for the primary TTY-probing implementation
     */
    public static function auto(): self
    {
        try {
            $cap = Detect::probe();
        } catch (\Throwable) {
            // Detect::probe() is documented never to throw; this belt keeps
            // auto()'s own never-throws contract if that guarantee regresses.
            $cap = null;
        }

        if ($cap !== null && self::hasGraphicsProtocol($cap)) {
            return self::fromCapability($cap);
        }

        // No graphics protocol found by the authoritative stage — consult
        // candy-palette's env-derived report as the explicit fallback.
        return self::autoFromPalette($cap ?? Capability::unknown());
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
     * Explicit fallback detection via candy-palette's TerminalProbe.
     *
     * Only consulted when {@see Detect::probe()} identified no graphics
     * protocol. The palette report can still see Kitty/iTerm2 environment
     * advertisements Detect's env table misses; anything else keeps the
     * Detect snapshot (whose bestBackend lands on HalfBlock).
     */
    private static function autoFromPalette(Capability $fallbackCap): self
    {
        try {
            $report = TerminalProbe::run();
        } catch (\Throwable) {
            // TerminalProbe itself threw — keep the Detect snapshot.
            return self::fromCapability($fallbackCap);
        }

        // Kitty keyboard support implies the Kitty graphics protocol.
        if ($report->has(PaletteCapability::KittyKeyboard)) {
            return self::fromCapability(Capability::kitty($fallbackCap->cellSize, $fallbackCap->inTmux));
        }

        // iTerm2 inline image support.
        if ($report->has(PaletteCapability::ITerm2)) {
            return self::fromCapability(Capability::iterm2($fallbackCap->cellSize, $fallbackCap->inTmux));
        }

        return self::fromCapability($fallbackCap);
    }

    /** Force the Kitty graphics-protocol renderer. */
    public static function kitty(): self
    {
        return new self(
            new KittyRenderer(),
            Capability::universal(),
            null,
            null,
            null,
        );
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
     * Only meaningful when the current renderer is a SixelRenderer (also
     * through a tmux passthrough envelope); returns the same instance
     * otherwise.
     */
    public function withDither(Dither $dither): self
    {
        if ($this->renderer instanceof TmuxPassthroughDecorator) {
            $inner = $this->renderer->inner();

            return $inner instanceof SixelRenderer
                ? new self(new TmuxPassthroughDecorator($inner->withDither($dither)), $this->capability, $this->forcedWidth, $this->forcedHeight, $this->scale)
                : $this;
        }

        if ($this->renderer instanceof SixelRenderer) {
            return new self($this->renderer->withDither($dither), $this->capability, $this->forcedWidth, $this->forcedHeight, $this->scale);
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
     * Stable backend name: 'sixel' | 'kitty' | 'iterm2' | 'halfblock' | 'quarterblock' | 'ascii' | 'chafa'.
     */
    public function protocol(): string
    {
        return $this->renderer->name();
    }

    /**
     * The renderer this mosaic encodes through (tmux decorator included
     * when the terminal runs under tmux).
     */
    public function renderer(): Renderer
    {
        return $this->renderer;
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
        return ['kitty', 'sixel', 'iterm2', 'halfblock', 'quarterblock', 'ascii', 'chafa'];
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
     * Whether the snapshot identifies at least one pixel-graphics protocol.
     * HalfBlock alone does not count — it is the universal text fallback.
     */
    private static function hasGraphicsProtocol(Capability $cap): bool
    {
        return $cap->kitty || $cap->iterm2 || $cap->sixel || $cap->chafa;
    }

    /**
     * Mint the mosaic for a capability snapshot: best backend for the
     * detected protocols, tmux passthrough envelope added when the terminal
     * runs under tmux. Shared by {@see probe()}, {@see auto()} and the
     * palette fallback so every detection path ends in one place.
     */
    private static function fromCapability(Capability $cap): self
    {
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

    /**
     * Build a Mosaic from a protocol mode string.
     *
     * Accepts the vocabulary terminals/apps configure image output with —
     * `auto`, `sixel`, `kitty`, `iterm2`, `halfblock` (aliases `half`,
     * `ansi`), `quarterblock` (alias `quarter`), `ascii`, `ansi256`,
     * `truecolor`, `chafa` — case-insensitively, whitespace-trimmed.
     * `ansi256`/`truecolor` select the ASCII ramp renderer tinted with the
     * matching colour mode, mirroring how the client config maps them.
     *
     * Returns null on an unknown mode so callers can phrase the error in
     * their own words rather than unwinding an exception.
     */
    public static function fromModeString(string $mode): ?self
    {
        return match (strtolower(trim($mode))) {
            'auto'         => self::auto(),
            'sixel'        => self::sixel(),
            'kitty'        => self::kitty(),
            'iterm2'       => self::iterm2(),
            'halfblock', 'half', 'ansi'  => self::halfBlock(),
            'quarterblock', 'quarter'    => self::quarterBlock(),
            'ascii'        => self::ascii(),
            'ansi256'      => self::ascii(AsciiColorMode::Ansi256),
            'truecolor'    => self::ascii(AsciiColorMode::TrueColor),
            'chafa'        => self::chafa(),
            default        => null,
        };
    }

    /**
     * Fetch a poster image from $url and render it at cell size in one call,
     * consulting and populating an optional on-disk render cache.
     *
     * The synchronous companion of {@see posterAsync()}: fetches through
     * {@see ImageSource::fromUrl()} — so the scheme allow-list, redirect
     * re-validation and private/reserved-IP SSRF deny-list all apply, with
     * $allowedHosts as the opt-in internal-endpoint seam — then renders via
     * {@see render()}. When no explicit scale was configured the poster
     * renders with {@see Scale::Fill}, cropping to the cell box's true
     * display aspect so portrait posters are never squashed (see CELL_ASPECT).
     *
     * $cache, when given, is keyed with
     * {@see DiskCache::key()} over ($url, $cellW, $cellH, protocol|scale) —
     * a hit returns the stored bytes without touching the network. Pass
     * {@see DiskCache::key()}-compatible explicit dimensions on both sides of
     * a process restart for stable hits; a null $cellH hashes as the sentinel
     * auto-height.
     *
     * @param string           $url          Absolute http(s) URL of the poster.
     * @param int              $cellW        Target width in terminal cells.
     * @param int|null         $cellH        Target height in cells (null = from aspect ratio).
     * @param DiskCache|null   $cache        Optional render cache; consulted first, populated on miss.
     * @param array<string>|null $allowedHosts Hosts allowed to bypass the SSRF deny-list.
     * @throws \InvalidArgumentException  if the fetch or decode fails (SSRF, bad URL, unsupported image).
     * @throws \RuntimeException          if GD cannot decode the fetched bytes (or is absent).
     */
    public function poster(
        string $url,
        int $cellW,
        ?int $cellH = null,
        ?DiskCache $cache = null,
        ?array $allowedHosts = null,
    ): string {
        $key = $this->posterCacheKey($url, $cellW, $cellH);
        if ($cache !== null) {
            $cached = $cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $rendered = $this->renderPoster(ImageSource::fromUrl($url, allowedHosts: $allowedHosts), $cellW, $cellH);
        $cache?->put($key, $rendered);

        return $rendered;
    }

    /**
     * Async variant of {@see poster()} — resolves with the rendered bytes.
     *
     * Fetches through {@see ImageSource::fromUrlAsync()} so the per-hop SSRF
     * guarding applies on the event loop; a cache hit resolves immediately
     * without touching the network. Cache population happens inside the
     * fulfillment chain, so a rejected fetch stores nothing. Error semantics
     * are inherited from the source call: an unsupported URL scheme throws
     * synchronously, SSRF/host rejections come back as a rejected promise.
     * The render itself is synchronous on the event loop (decode + resample
     * + encode) — for large posters route the source through
     * {@see Mosaic::adaptive()} + {@see AdaptiveImage::withAsync()} or
     * offload the whole call so the loop keeps serving other sockets.
     *
     * @return PromiseInterface<string>
     */
    public function posterAsync(
        string $url,
        int $cellW,
        ?int $cellH = null,
        ?DiskCache $cache = null,
        ?array $allowedHosts = null,
    ): PromiseInterface {
        $key = $this->posterCacheKey($url, $cellW, $cellH);
        if ($cache !== null) {
            $cached = $cache->get($key);
            if ($cached !== null) {
                return resolve($cached);
            }
        }

        $poster = $this->scaledForPoster();

        return ImageSource::fromUrlAsync($url, allowedHosts: $allowedHosts)
            ->then(static function (ImageSource $source) use ($poster, $cellW, $cellH, $cache, $key): string {
                $rendered = $poster->render($source, $cellW, $cellH);
                $cache?->put($key, $rendered);

                return $rendered;
            });
    }

    /**
     * Render a poster that already lives on local disk, with the same
     * Fill-by-default scaling and optional {@see DiskCache} windowing as
     * {@see poster()}. The cache key is derived from $path in place of a URL.
     *
     * @throws \InvalidArgumentException  if the file cannot be read or decoded.
     */
    public function posterFile(
        string $path,
        int $cellW,
        ?int $cellH = null,
        ?DiskCache $cache = null,
    ): string {
        $key = $this->posterCacheKey($path, $cellW, $cellH);
        if ($cache !== null) {
            $cached = $cache->get($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $rendered = $this->renderPoster(ImageSource::fromFile($path), $cellW, $cellH);
        $cache?->put($key, $rendered);

        return $rendered;
    }

    /**
     * This mosaic with Fill scaling applied when no scale was chosen —
     * posters crop to the cell box instead of squashing into it.
     */
    private function scaledForPoster(): self
    {
        return $this->scale === null ? $this->withScale(Scale::Fill) : $this;
    }

    private function renderPoster(ImageSource $source, int $cellW, ?int $cellH): string
    {
        return $this->scaledForPoster()->render($source, $cellW, $cellH);
    }

    /**
     * Poster cache key: DiskCache::key() over (url, cellW, cellH-sentinel,
     * protocol|scale). The scale rides in the protocol segment because it
     * changes the encoded bytes (Fill crops, Fit letterboxes) for an
     * otherwise identical (url, size, protocol) tuple — two mosaics at
     * different scales must never cross-serve each other's cache entries.
     */
    private function posterCacheKey(string $urlOrPath, int $cellW, ?int $cellH): string
    {
        $protocol = $this->protocol() . '|' . ($this->scale ?? Scale::Fill)->name;

        return DiskCache::key($urlOrPath, $cellW, $cellH ?? self::AUTO_HEIGHT, $protocol);
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
