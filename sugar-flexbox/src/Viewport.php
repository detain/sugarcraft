<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Widgets\Scrollbar\ScrollbarState;
use SugarCraft\Widgets\Viewport\Viewport as WidgetsViewport;

/**
 * Immutable viewport wrapper over the canonical {@see WidgetsViewport}.
 *
 * Composes the sugar-forms Viewport rather than reimplementing it: the
 * `SugarCraft\Widgets\Viewport\Viewport` symbol this file imports is a
 * deprecated class_alias façade onto `SugarCraft\Forms\Viewport\Viewport`.
 * Adds sticky header/footer positioning and a decorative sync-partner
 * reference ({@see self::syncWith()} stores it; it propagates nothing).
 *
 * @see \SugarCraft\Forms\Viewport\Viewport
 */
final readonly class Viewport
{
    private WidgetsViewport $inner;
    private int $stickyHeader;
    private int $stickyFooter;
    private ?Viewport $syncedViewport;

    /**
     * @param WidgetsViewport        $inner        canonical forms viewport (via the widgets alias) holding content + dimensions
     * @param int                 $stickyHeader rows pinned to the top
     * @param int                 $stickyFooter rows pinned to the bottom
     * @param Viewport|null       $syncedViewport scroll-sync partner
     */
    private function __construct(
        WidgetsViewport $inner,
        int $stickyHeader = 0,
        int $stickyFooter = 0,
        ?Viewport $syncedViewport = null,
    ) {
        $this->inner = $inner;
        $this->stickyHeader = $stickyHeader;
        $this->stickyFooter = $stickyFooter;
        $this->syncedViewport = $syncedViewport;
    }

    /** Construct with default dimensions (80×24). */
    public static function new(int $width = 80, int $height = 24): self
    {
        return new self(WidgetsViewport::new($width, $height));
    }

    /**
     * Construct a content-carrying viewport. The factory verb is
     * fromContent() (FBX-22): the old name read as the fluent
     * with*Content setter and PHP happily resolved `\$vp->withContent()`
     * to the static — silently rebuilding an 80×24 box and discarding
     * the receiver's geometry. Instance content changes are
     * {@see self::setContent()}.
     */
    public static function fromContent(string $content, int $width = 80, int $height = 24): self
    {
        return new self(WidgetsViewport::new($width, $height)->setContent($content));
    }

    /**
     * BC factory for the retired name — deliberately PRIVATE (FBX-22):
     * PHP 8 binds no \$this when a public static answers an instance
     * call, so an in-method misuse guard was unstatable. With this seat
     * private, BOTH external call forms route through the hooks below
     * and are answered on their own terms: `Viewport::withContent(...)`
     * still constructs (the hooks delegate here), while
     * \$vp->withContent(...) — the misuse that used to silently rebuild
     * a default 80x24 box and discard the receiver's geometry — throws
     * a named refusal.
     *
     * @deprecated Use {@see self::fromContent()} to construct with
     *     content, or {@see self::setContent()} to change an instance's
     *     content — the old name impersonated that fluent verb (finding-006).
     *     REMOVED-IN: 2.0.0.
     */
    private static function withContent(string $content, int $width = 80, int $height = 24): self { return self::fromContent($content, $width, $height); }

    /**
     * Instance-style calls of names this class does not expose. The
     * only DEPRECATED name routed here is withContent, refused with a
     * pointer to the two honest verbs; anything else reproduces the
     * engine's own undefined-method Error verbatim, so typos keep the
     * exact failure they had before this hook existed.
     */
    public function __call(string $name, array $arguments): never
    {
        if ($name === 'withContent') {
            throw new \BadMethodCallException(
                'Viewport::withContent() called on an instance would silently rebuild a default 80x24 box — use setContent() on the instance, or Viewport::fromContent() to construct'
            );
        }
        throw new \Error(\sprintf('Call to undefined method %s::%s()', \get_class($this), $name));
    }

    /**
     * Static-style calls of names this class does not expose:
     * withContent still constructs through the private BC seat; every
     * other name keeps the engine's undefined-method Error.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if ($name === 'withContent') {
            // Named passthrough keeps the seat's one-line law; a stray
            // 4th argument fails with the engine's own arity Error.
            /** @phpstan-ignore-next-line methodVisibility */
            return self::withContent($arguments[0] ?? '', $arguments[1] ?? 80, $arguments[2] ?? 24);
        }
        throw new \Error(\sprintf('Call to undefined method %s::%s()', self::class, $name));
    }

    // ---- sticky positioning --------------------------------------------

    /**
     * Designate the first `$rows` lines as a sticky header — always
     * visible at the top of the viewport regardless of scroll offset.
     */
    public function withStickyHeader(int $rows): self
    {
        $rows = max(0, $rows);
        return new self(
            $this->inner,
            $rows,
            $this->stickyFooter,
            $this->syncedViewport,
        );
    }

    /**
     * Designate the last `$rows` lines as a sticky footer — always
     * visible at the bottom of the viewport regardless of scroll offset.
     */
    public function withStickyFooter(int $rows): self
    {
        $rows = max(0, $rows);
        return new self(
            $this->inner,
            $this->stickyHeader,
            $rows,
            $this->syncedViewport,
        );
    }

    /**
     * Record `$other` as this viewport's sync partner.
     *
     * ONE-WAY and DECORATIVE: the reference is stored so
     * {@see self::isSynced()} can report it — no offset ever flows in
     * either direction on its own (and `$other` is unchanged). Callers
     * that want synced panels must apply the same yOffset to both
     * viewports themselves (e.g. a side-by-side diff viewer sharing one
     * offset value).
     */
    public function syncWith(Viewport $other): self
    {
        return new self(
            $this->inner,
            $this->stickyHeader,
            $this->stickyFooter,
            $other,
        );
    }

    /** True when this viewport has a sync partner via {@see syncWith}. */
    public function isSynced(): bool
    {
        return $this->syncedViewport !== null;
    }

    // ---- init/update/view forwarding (NOT the Core\Model contract) -----

    /**
     * Forward to the canonical forms viewport.
     *
     * TRUTH: this class does NOT implement
     * {@see \SugarCraft\Core\Model} — it has no `subscriptions()`, so
     * `instanceof Model` is false. It merely forwards the Model-shaped
     * init/update/view trio it delegates to. Compose it from your own
     * Model; do not hand it to a program loop as one.
     */
    public function init(): ?\Closure
    {
        return $this->inner->init();
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        [$inner, $cmd] = $this->inner->update($msg);
        return [new self($inner, $this->stickyHeader, $this->stickyFooter, $this->syncedViewport), $cmd];
    }

    public function view(): string
    {
        if ($this->stickyHeader === 0 && $this->stickyFooter === 0) {
            // Width-invariant defence (finding 003): the forms delegate
            // skips the right clamp whenever the bar is armed and appends
            // one column rune whatever the body measures, so a 40-cell
            // line in a 20-wide box emitted 41-cell rows and a 2-cell CJK
            // thumb a 21-cell row — terminal desync, the exact failure mode
            // the sticky docblock names. Paint the bar frame through this
            // wrapper's own clamp-and-pad machinery (the same path the
            // sticky frame uses, so ONE scrollbar geometry rules both).
            return $this->inner->showScrollbar
                ? $this->renderWithScrollbarPlain()
                : $this->inner->view();
        }

        return $this->renderWithSticky();
    }

    /**
     * Plain (non-sticky) window with the scrollbar column, width-invariant.
     *
     * Mirrors the delegate's window slice and horizontal law, then hands the
     * rows to paintScrollbar(), which clamps each body to the reserved
     * budget, appends the column rune, and normalizes every row to exactly
     * width() cells.
     */
    private function renderWithScrollbarPlain(): string
    {
        $lines = $this->inner->lines;
        $total = count($lines);
        if ($total === 0) {
            return '';
        }
        $window = array_slice($lines, max(0, $this->inner->yOffset), $this->inner->height);
        if ($this->inner->xOffset > 0) {
            $xOffset = $this->inner->xOffset;
            $window = array_map(
                static fn (string $line): string => Width::dropAnsi($line, $xOffset),
                $window,
            );
        }
        return $this->paintScrollbar($window, $total);
    }

    /**
     * Render the viewport with sticky header/footer regions.
     */
    private function renderWithSticky(): string
    {
        $lines = $this->inner->lines;
        $total = count($lines);
        $height = $this->inner->height;
        $yOffset = $this->inner->yOffset;

        $headerCount = min($this->stickyHeader, max(0, $height - $this->stickyFooter));
        // A footer row must not re-pin a row the header already shows: when
        // the document is shorter than the sticky regions, cap the footer to
        // the rows below the header and let blank padding fill the rest.
        // Duplicating one line (header+footer on a 1-line doc) made screen
        // readers announce it twice and looked like a stuck frame.
        $footerCount = min($this->stickyFooter, max(0, $height - $this->stickyHeader), max(0, $total - $headerCount));

        $scrollableHeight = max(0, $height - $headerCount - $footerCount);

        // Sticky header: first N lines, always visible.
        $headerLines = $headerCount > 0 && $total > 0
            ? array_slice($lines, 0, $headerCount)
            : [];

        // Sticky footer: last M lines, always visible.
        $footerLines = $footerCount > 0 && $total > 0
            ? array_slice($lines, max(0, $total - $footerCount))
            : [];

        // Scrollable middle: window into the remaining content.
        // Clamp against the sticky-reduced scrollable range to prevent over-scroll
        // from pulling footer lines into the middle window. The window must ALSO
        // stop before the footer region's first index: bounding only by the
        // scrollable height let a short document slice rows the footer
        // re-rendered, so one line appeared twice — blank padding (padLines
        // below) fills the middle whenever content runs out before it.
        $maxMiddleStart = max($headerCount, $total - $footerCount - $scrollableHeight);
        $middleStart = min($headerCount + $yOffset, $maxMiddleStart);
        $middleCapacity = max(0, $total - $footerCount - $middleStart);
        $middleLines = $scrollableHeight > 0
            ? array_slice($lines, $middleStart, min($scrollableHeight, $middleCapacity))
            : [];

        // Horizontal window law (mirrors the delegate's xOffset>0 branch,
        // sugar-forms Viewport::view()): a view scrolled right must drop the
        // same number of left cells from EVERY region — header, middle and
        // footer — before padding, otherwise the sticky frame keeps painting
        // column zero and xOffset()/atLeftmost()/atRightmost()/
        // horizontalScrollPercent() disagree with the pixels on screen.
        $xOffset = $this->inner->xOffset;
        if ($xOffset > 0) {
            $drop = static fn(string $line): string => Width::dropAnsi($line, $xOffset);
            $headerLines = array_map($drop, $headerLines);
            $middleLines = array_map($drop, $middleLines);
            $footerLines = array_map($drop, $footerLines);
        }

        // Fill each region to full width with padding. When the scrollbar is
        // shown its column is reserved (paintScrollbar appends it) — padding
        // to the FULL width first made every sticky row one cell too wide.
        $width = $this->inner->width;
        $contentWidth = $this->inner->showScrollbar ? max(0, $width - $this->scrollbarColumnCells()) : $width;
        $pad = fn(string $l): string => $this->padRight($l, $contentWidth);

        $headerLines = array_map($pad, $headerLines);
        $middleLines = array_map($pad, $middleLines);
        $footerLines = array_map($pad, $footerLines);

        // Pad each region to its full height.
        $headerLines = $this->padLines($headerLines, $headerCount, $contentWidth);
        $middleLines = $this->padLines($middleLines, $scrollableHeight, $contentWidth);
        $footerLines = $this->padLines($footerLines, $footerCount, $contentWidth);

        $combined = array_merge($headerLines, $middleLines, $footerLines);

        if ($this->inner->showScrollbar) {
            return $this->paintScrollbar($combined, $total);
        }

        return implode("\n", $combined);
    }

    /**
     * Clamp a line to `$width` CELLS and right-pad it out.
     *
     * Truncation happens before padding: sticky lines came from the raw
     * document, and an unclamped 20-cell line escaped a width-10 viewport
     * (the base view() clamps only when neither sticky regions nor the
     * scrollbar take the wheel), wrapping the terminal and desynchronising
     * every later frame.
     */
    private function padRight(string $line, int $width): string
    {
        $line = \SugarCraft\Core\Util\Width::truncateAnsi($line, $width);
        $visual = \SugarCraft\Core\Util\Width::string($line);
        if ($visual >= $width) {
            return $line;
        }
        return $line . str_repeat(' ', $width - $visual);
    }

    /**
     * Extend or truncate a line array to exactly `$count` entries.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function padLines(array $lines, int $count, int $width): array
    {
        $pad = str_repeat(' ', max(0, $width));
        $lineCount = count($lines);
        while ($lineCount < $count) {
            $lines[] = $pad;
            $lineCount++;
        }
        return array_slice($lines, 0, $count);
    }

    /** @return int cells a scrollbar column needs: the widest configured rune, at least 1 */
    private function scrollbarColumnCells(): int
    {
        return max(1, \SugarCraft\Core\Util\Width::string($this->inner->scrollbarChar), \SugarCraft\Core\Util\Width::string($this->inner->scrollbarTrack));
    }

    /**
     * Append a scrollbar column to each line in the window.
     *
     * @param list<string> $window
     */
    private function paintScrollbar(array $window, int $total): string
    {
        $rendered = count($window);
        $scrollbarChar = $this->inner->scrollbarChar;
        $scrollbarTrack = $this->inner->scrollbarTrack;
        // One reserved column assumed a 1-cell rune; a wide thumb or
        // track (a single CJK rune is 2 cells) pushed every row a cell past
        // the viewport. Reserve the WIDEST configured rune and normalize
        // rows to the full width.
        $bodyWidth = max(0, $this->inner->width - $this->scrollbarColumnCells());

        // Correct thumb proportions relative to window size (not document size).
        // The thumb is a fraction of the window that represents the viewport.
        $thumbHeight = $total > 0 && $rendered > 0
            ? max(1, (int) round($rendered * ($rendered / $total)))
            : max(1, $rendered);
        $maxThumbStart = max(0, $rendered - $thumbHeight);

        $maxOffset = max(0, $total - $this->inner->height);
        // thumbStart maps the current yOffset onto the available thumb travel range.
        $thumbStart = $maxOffset > 0 && $total > $rendered
            ? (int) round($maxThumbStart * ($this->inner->yOffset / $maxOffset))
            : 0;
        $thumbStart = max(0, min($thumbStart, $maxThumbStart));

        // When a Scrollbar component is attached, answer the column from
        // it — the delegate's plain path does exactly this, and the old
        // sticky substitution painted raw runes the component's thumb
        // geometry never saw, so the two paths produced two different
        // scrollbars (or none) from one call.
        $column = $this->inner->verticalScrollbar !== null
            ? $this->inner->verticalScrollbar->view(new ScrollbarState(
                total: $total,
                position: $this->inner->yOffset,
                viewport: $this->inner->height,
            ), $rendered)
            : null;

        $out = [];
        foreach ($window as $i => $line) {
            $padded = $bodyWidth > 0
                ? $this->padRight($line, $bodyWidth)
                : $line;
            if ($column !== null) {
                $sb = mb_substr($column, $i, 1, 'UTF-8');
                if ($sb === '') {
                    $sb = $scrollbarTrack;
                }
            } else {
                $sb = ($i >= $thumbStart && $i < $thumbStart + $thumbHeight)
                    ? $scrollbarChar
                    : $scrollbarTrack;
            }
            $out[] = $this->padRight($padded . $sb, $this->inner->width);
        }
        return implode("\n", $out);
    }

    // ---- content + dimensions -----------------------------------------

    /** Returns a new Viewport; the receiver is unchanged (fluent house law). */
    public function setContent(string $content): self
    {
        return new self($this->inner->setContent($content), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    /**
     * Resize — THE single sizing spelling of this lib (FBX-06). Returns a
     * new Viewport; the receiver is unchanged (fluent house law). Unlike
     * {@see FlexBox::render()}'s clamp-tolerant geometry, the forms
     * delegate THROWS InvalidArgumentException on a negative dimension —
     * see the README argument policy.
     */
    public function withSize(int $width, int $height): self
    {
        return new self($this->inner->withSize($width, $height), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    /**
     * BC forwarder onto the single sizing spelling.
     *
     * @deprecated Resize through {@see self::withSize()} alone; this
     *     one-dimension twin was the second vocabulary the lib collapsed
     *     away (finding-005) — it could also drift from withSize's
     *     throw-at-door posture. Use withSize(). REMOVED-IN: 2.0.0.
     */
    public function setWidth(int $width): self { return $this->withSize($width, $this->height()); }

    /**
     * BC forwarder onto the single sizing spelling.
     *
     * @deprecated Resize through {@see self::withSize()} alone (finding-005
     *     collapse). Use withSize(). REMOVED-IN: 2.0.0.
     */
    public function setHeight(int $height): self { return $this->withSize($this->width(), $height); }

    public function width(): int  { return $this->inner->getWidth(); }
    public function height(): int { return $this->inner->getHeight(); }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::width()} instead. REMOVED-IN: 2.0.0.
     */
    public function getWidth(): int { return $this->width(); }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::height()} instead. REMOVED-IN: 2.0.0.
     */
    public function getHeight(): int { return $this->height(); }

    // ---- offset --------------------------------------------------------

    /** Returns a new Viewport; the receiver is unchanged (fluent house law). */
    public function setYOffset(int $offset): self
    {
        return new self($this->inner->setYOffset($offset), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function yOffset(): int { return $this->inner->yOffset(); }

    /** Returns a new Viewport; the receiver is unchanged (fluent house law). */
    public function setXOffset(int $offset): self
    {
        return new self($this->inner->setXOffset($offset), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function xOffset(): int { return $this->inner->xOffset(); }

    public function withHorizontalStep(int $step): self
    {
        return new self($this->inner->withHorizontalStep($step), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withMouseWheelEnabled(bool $on): self
    {
        return new self($this->inner->withMouseWheelEnabled($on), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withMouseWheelDelta(int $delta): self
    {
        return new self($this->inner->withMouseWheelDelta($delta), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    /**
     * Toggle the scrollbar column on/off.
     *
     * The name reads as what it flips — the delegate's `showScrollbar`
     * flag — instead of impersonating the fluent `with*` idiom with a
     * boolean (which sat one letter away from {@see self::withVerticalScrollbar()}
     * and confused the two). {@see self::withScrollbar()} remains as a
     * deprecated forwarder until 2.0.0.
     */
    public function showScrollbar(bool $on = true): self
    {
        return new self($this->inner->withScrollbar($on), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    /**
     * BC forwarder for the pre-rename toggle name.
     *
     * @deprecated The boolean `withScrollbar` name collided with the
     *     component-attach verb {@see self::withVerticalScrollbar()} and
     *     dressed a toggle as a setter. Use {@see self::showScrollbar()}.
     *     REMOVED-IN: 2.0.0.
     */
    public function withScrollbar(bool $on = true): self { return $this->showScrollbar($on); }

    public function withScrollbarRunes(string $thumb, string $track): self
    {
        return new self($this->inner->withScrollbarRunes($thumb, $track), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    /**
     * Attach the lib's own {@see Scrollbar} component and render it.
     *
     * The parameter previously type-hinted the shadowing `use` import of the
     * widgets alias, so passing THIS lib's Scrollbar — the documented
     * companion — threw a TypeError (historically). It now accepts the
     * library's own value object and unwraps the canonical forms widget for
     * the delegate.
     *
     * Attaching also ARMS the bar: the delegate's
     * `copy(verticalScrollbar:)` omits the `showScrollbar` flag (that root
     * fix is a tracked sugar-forms row), so without the wrapper-side
     * `withScrollbar(true)` hop the injected component would never paint.
     * The component's thumb/track runes are carried through the rune door
     * so the sticky path's column reservation measures the runes that
     * actually render (a wide thumb must still reserve two cells).
     */
    public function withVerticalScrollbar(Scrollbar $scrollbar): self
    {
        $widget = $scrollbar->widgetScrollbar();
        $inner = $this->inner
            ->withVerticalScrollbar($widget)
            ->withScrollbar(true)
            ->withScrollbarRunes($widget->thumbChar, $widget->trackChar);
        return new self($inner, $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function withSmoothScroll(bool $enable = true): self
    {
        return new self($this->inner->withSmoothScroll($enable), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    // ---- navigation ---------------------------------------------------

    public function lineUp(int $n = 1): self
    {
        return new self($this->inner->lineUp($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function lineDown(int $n = 1): self
    {
        return new self($this->inner->lineDown($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function halfPageUp(): self   { return new self($this->inner->halfPageUp(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function halfPageDown(): self { return new self($this->inner->halfPageDown(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    public function pageUp(): self   { return new self($this->inner->pageUp(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function pageDown(): self { return new self($this->inner->pageDown(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    public function scrollLeft(int $n = 1): self
    {
        return new self($this->inner->scrollLeft($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function scrollRight(int $n = 1): self
    {
        return new self($this->inner->scrollRight($n), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport);
    }

    public function gotoTop(): self    { return new self($this->inner->gotoTop(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }
    public function gotoBottom(): self { return new self($this->inner->gotoBottom(), $this->stickyHeader, $this->stickyFooter, $this->syncedViewport); }

    // ---- queries ------------------------------------------------------

    public function totalLineCount(): int   { return $this->inner->totalLineCount(); }
    public function visibleLineCount(): int { return $this->inner->visibleLineCount(); }
    public function atTop(): bool          { return $this->inner->atTop(); }
    public function atBottom(): bool       { return $this->inner->atBottom(); }
    public function atLeftmost(): bool     { return $this->inner->atLeftmost(); }
    public function atRightmost(): bool     { return $this->inner->atRightmost(); }
    public function scrollPercent(): float          { return $this->inner->scrollPercent(); }
    public function horizontalScrollPercent(): float { return $this->inner->horizontalScrollPercent(); }
}
