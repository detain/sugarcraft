<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\Scrollbar\ScrollbarState;
use SugarCraft\Core\Concerns\Mutable;
use SugarCraft\Forms\Viewport\Viewport as FormsViewport;
use SugarCraft\Flexbox\Support\CellPad;

/**
 * Immutable viewport wrapper over the canonical {@see FormsViewport}.
 *
 * Composes the sugar-forms Viewport directly (FBX-20): the lib once
 * imported the deprecated `SugarCraft\Widgets\…` class_alias façade;
 * the require and these imports now name the canonical classes, so no
 * alias hop remains anywhere in this lib's dependency graph. Adds
 * sticky header/footer positioning and a decorative sync-partner
 * reference ({@see self::withLinkedViewport()} stores it; it propagates nothing).
 *
 * @see \SugarCraft\Forms\Viewport\Viewport
 */
final readonly class Viewport implements Model
{
    use Mutable;

    private FormsViewport $inner;
    private int $stickyHeader;
    private int $stickyFooter;
    private ?Viewport $syncedViewport;

    /**
     * @param FormsViewport        $inner        canonical forms viewport (via the widgets alias) holding content + dimensions
     * @param int                 $stickyHeader rows pinned to the top
     * @param int                 $stickyFooter rows pinned to the bottom
     * @param Viewport|null       $syncedViewport scroll-sync partner
     */
    private function __construct(
        FormsViewport $inner,
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
        return new self(FormsViewport::new($width, $height));
    }

    /**
     * Construct a content-carrying viewport. The factory verb is
     * fromContent() (FBX-22): the old name read as the fluent
     * with*Content setter and PHP happily resolved `\$vp->withContent()`
     * to the static — silently rebuilding an 80×24 box and discarding
     * the receiver's geometry. Instance content changes are
     * {@see self::withContent()} (the instance setter, FBX-06 — the retired
     * static BC seat of that name is gone, so a static call now fails with
     * the engine's own "non-static method" Error).
     */
    public static function fromContent(string $content, int $width = 80, int $height = 24): self
    {
        return new self(FormsViewport::new($width, $height)->setContent($content));
    }

    // ---- sticky positioning --------------------------------------------

    /**
     * Designate the first `$rows` lines as a sticky header — always
     * visible at the top of the viewport regardless of scroll offset.
     */
    /**
     * Pin the FIRST $rows lines as a sticky header region. The count is an
     * ARGUMENT POLICY door (FBX-30): negative refuses loudly naming the
     * value — a silent clamp-to-0 here while Viewport::new() refuses a
     * negative dimension was three-way inconsistency in one class. 0
     * is legal and means 'no sticky region'; the render-time cap to
     * what the height can show stays a GEOMETRY clamp (lines 240/252
     * era pins), not an argument judgement.
     */
    public function withStickyHeader(int $rows): self
    {
        if ($rows < 0) {
            throw new \InvalidArgumentException(
                "Viewport::withStickyHeader() needs a row count >= 0; got {$rows}. Use 0 to disable the sticky region."
            );
        }
        return $this->mutate(['stickyHeader' => $rows]);
    }

    /**
     * Designate the last `$rows` lines as a sticky footer — always
     * visible at the bottom of the viewport regardless of scroll offset.
     */
    /**
     * Pin the LAST $rows lines as a sticky footer region. The count is an
     * ARGUMENT POLICY door (FBX-30): negative refuses loudly naming the
     * value — a silent clamp-to-0 here while Viewport::new() refuses a
     * negative dimension was three-way inconsistency in one class. 0
     * is legal and means 'no sticky region'; the render-time cap to
     * what the height can show stays a GEOMETRY clamp (lines 240/252
     * era pins), not an argument judgement.
     */
    public function withStickyFooter(int $rows): self
    {
        if ($rows < 0) {
            throw new \InvalidArgumentException(
                "Viewport::withStickyFooter() needs a row count >= 0; got {$rows}. Use 0 to disable the sticky region."
            );
        }
        return $this->mutate(['stickyFooter' => $rows]);
    }

    /**
     * Record `$other` as this viewport's LINKED viewport.
     *
     * ONE-WAY and DECORATIVE (FBX-48 named the state for what it is):
     * the reference is stored so {@see self::hasLinkedViewport()} can
     * report it — no offset ever flows in either direction on its own
     * (and `$other` is unchanged). Callers that want synced panels must
     * apply the same yOffset to both viewports themselves (e.g. a
     * side-by-side diff viewer sharing one offset value). The old
     * syncWith() name promised exactly the coupling it does not do.
     */
    public function withLinkedViewport(Viewport $other): self
    {
        return $this->mutate(['syncedViewport' => $other]);
    }

    /** True when a {@see self::withLinkedViewport()} partner is attached. */
    public function hasLinkedViewport(): bool
    {
        return $this->syncedViewport !== null;
    }

    /**
     * @deprecated Renamed to {@see self::withLinkedViewport()} — the old
     *     name promised offset coupling it never had (finding-067).
     *     REMOVED-IN: 2.0.0.
     */
    public function syncWith(Viewport $other): self { return $this->withLinkedViewport($other); }

    /**
     * @deprecated Renamed to {@see self::hasLinkedViewport()}; same one-way
     *     decorative state, honest verb. REMOVED-IN: 2.0.0.
     */
    public function isSynced(): bool { return $this->hasLinkedViewport(); }

    // ---- init/update/view forwarding (the Core\Model trio, FBX-21) -----

    /**
     * Forward to the canonical forms viewport.
     *
     * This class IS a {@see Model} (FBX-21): init/update/view are the
     * Model trio, `update()` hands back the wrapper (sticky regions and
     * link survive) rather than the bare delegate, and subscriptions()
     * forwards to the wrapped viewport so a program loop can run it
     * directly.
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
        return [$this->mutate(['inner' => $inner]), $cmd];
    }

    public function subscriptions(): ?Subscriptions
    {
        return $this->inner->subscriptions();
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
                : $this->renderPlain();
        }

        return $this->renderWithSticky();
    }

    /**
     * Plain window, no scrollbar, no sticky regions — width-invariant (FBX-43).
     *
     * The delegate's view() returns RAGGED rows (each line at its own
     * length, and unclamped once xOffset > 0), while the sticky and
     * scrollbar frames normalise every row to width() cells. Same slice and
     * horizontal law as the delegate, then the shared clamp-and-pad so all
     * three faces honour one row-width contract. Row COUNT stays the
     * streaming face's: min(height, lines left), not padded to height.
     */
    private function renderPlain(): string
    {
        $window = array_slice($this->inner->lines, max(0, $this->inner->yOffset), $this->inner->height);
        $xOffset = $this->inner->xOffset;
        $width = $this->inner->width;
        $out = [];
        foreach ($window as $line) {
            if ($xOffset > 0) {
                $line = Width::dropAnsi($line, $xOffset);
            }
            $out[] = $this->padRight($line, $width);
        }
        return implode("\n", $out);
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
     *
     * The contract binds WHERE STICKY MEANING EXISTS: a document longer
     * than the frame (finding-052). A fitting document has nothing
     * scrolled out, and honouring 'always visible at the bottom'
     * literally there painted the tail line across a reserved blank
     * band — the plain streaming frame answers that shape instead; the
     * regions stay armed for growth.
     */
    private function renderWithSticky(): string
    {
        $lines = $this->inner->lines;
        $total = count($lines);
        $height = $this->inner->height;
        $yOffset = $this->inner->yOffset;

        // A document that fits the box has nothing scrolled out (every
        // cursor is clamped to zero), so honouring the sticky contract
        // LITERALLY here painted lines 1-4, a blank band, then the
        // footer line — reads as corruption. When nothing can scroll
        // out, fall back to the plain streaming layout: the sticky
        // regions stay armed for growth, the rows simply render in
        // order with trailing padding (finding-052). total <= height
        // covers the degenerate region-overflow shapes too.
        if ($total <= $height) {
            return $this->inner->showScrollbar
                ? $this->renderWithScrollbarPlain()
                : $this->renderPlain();
        }

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
        // CellPad: one memoized measure, truncate walk only on overflow;
        // byte-identical to the old truncateAnsi+str_repeat pairing
        // (differential-pinned in tests/Support/CellPadTest.php, FBX-36).
        [$fitted, $visual] = CellPad::fit($line, $width);
        return CellPad::pad($fitted, $visual, $width, 'right');
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
            // Single pass (FBX-45): padRight already made the body
            // EXACTLY $bodyWidth cells, and the column was reserved as
            // the widest configured rune, so the row's final width is
            // arithmetic — the old second padRight re-truncated and
            // re-measured a string whose width was already known.
            // Only the degenerate bodyWidth==0 seat (line width not
            // pinned by arithmetic) still asks the old path.
            $exact = $bodyWidth > 0;
            $padded = $exact
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
            if (!$exact) {
                $out[] = $this->padRight($padded . $sb, $this->inner->width);
                continue;
            }
            $tail = $this->inner->width - $bodyWidth - Width::string($sb);
            $out[] = $tail > 0 ? $padded . $sb . str_repeat(' ', $tail) : $padded . $sb;
        }
        return implode("\n", $out);
    }

    // ---- content + dimensions -----------------------------------------

    /** Returns a new Viewport; the receiver is unchanged (fluent house law). */
    public function withContent(string $content): self
    {
        return $this->mutate(['inner' => $this->inner->setContent($content)]);
    }

    /**
     * BC forwarder onto the single content spelling.
     *
     * @deprecated Use {@see self::withContent()} (FBX-06: the `with*` verb
     *     is the house spelling for returns-a-new-instance). REMOVED-IN: 2.0.0.
     */
    public function setContent(string $content): self { return $this->withContent($content); }

    /**
     * Resize — THE single sizing spelling of this lib (FBX-06). Returns a
     * new Viewport; the receiver is unchanged (fluent house law). Unlike
     * {@see FlexBox::render()}'s clamp-tolerant geometry, the forms
     * delegate THROWS InvalidArgumentException on a negative dimension —
     * see the README argument policy.
     */
    public function withSize(int $width, int $height): self
    {
        return $this->mutate(['inner' => $this->inner->withSize($width, $height)]);
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
    public function withYOffset(int $offset): self
    {
        return $this->mutate(['inner' => $this->inner->setYOffset($offset)]);
    }

    /**
     * BC forwarder onto {@see self::withYOffset()}.
     *
     * @deprecated Use withYOffset() (FBX-06). REMOVED-IN: 2.0.0.
     */
    public function setYOffset(int $offset): self { return $this->withYOffset($offset); }

    public function yOffset(): int { return $this->inner->yOffset(); }

    /** Returns a new Viewport; the receiver is unchanged (fluent house law). */
    public function withXOffset(int $offset): self
    {
        return $this->mutate(['inner' => $this->inner->setXOffset($offset)]);
    }

    /**
     * BC forwarder onto {@see self::withXOffset()}.
     *
     * @deprecated Use withXOffset() (FBX-06). REMOVED-IN: 2.0.0.
     */
    public function setXOffset(int $offset): self { return $this->withXOffset($offset); }

    public function xOffset(): int { return $this->inner->xOffset(); }

    public function withHorizontalStep(int $step): self
    {
        return $this->mutate(['inner' => $this->inner->withHorizontalStep($step)]);
    }

    public function withMouseWheelEnabled(bool $on): self
    {
        return $this->mutate(['inner' => $this->inner->withMouseWheelEnabled($on)]);
    }

    public function withMouseWheelDelta(int $delta): self
    {
        return $this->mutate(['inner' => $this->inner->withMouseWheelDelta($delta)]);
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
        return $this->mutate(['inner' => $this->inner->withScrollbar($on)]);
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
        return $this->mutate(['inner' => $this->inner->withScrollbarRunes($thumb, $track)]);
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
        return $this->mutate(['inner' => $inner]);
    }

    public function withSmoothScroll(bool $enable = true): self
    {
        return $this->mutate(['inner' => $this->inner->withSmoothScroll($enable)]);
    }

    // ---- navigation ---------------------------------------------------

    public function lineUp(int $n = 1): self
    {
        return $this->mutate(['inner' => $this->inner->lineUp($n)]);
    }

    public function lineDown(int $n = 1): self
    {
        return $this->mutate(['inner' => $this->inner->lineDown($n)]);
    }

    public function halfPageUp(): self   { return $this->mutate(['inner' => $this->inner->halfPageUp()]); }
    public function halfPageDown(): self { return $this->mutate(['inner' => $this->inner->halfPageDown()]); }

    public function pageUp(): self   { return $this->mutate(['inner' => $this->inner->pageUp()]); }
    public function pageDown(): self { return $this->mutate(['inner' => $this->inner->pageDown()]); }

    public function scrollLeft(int $n = 1): self
    {
        return $this->mutate(['inner' => $this->inner->scrollLeft($n)]);
    }

    public function scrollRight(int $n = 1): self
    {
        return $this->mutate(['inner' => $this->inner->scrollRight($n)]);
    }

    public function gotoTop(): self    { return $this->mutate(['inner' => $this->inner->gotoTop()]); }
    public function gotoBottom(): self { return $this->mutate(['inner' => $this->inner->gotoBottom()]); }

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
