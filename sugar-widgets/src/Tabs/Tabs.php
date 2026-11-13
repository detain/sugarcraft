<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

use SugarCraft\Widgets\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Mouse\Zones\Manager;
use SugarCraft\Mouse\Zones\MsgZoneInBounds;

/**
 * A tabbed panel component — switch between labelled tabs with keyboard
 * or mouse input.
 *
 * ## Keyboard navigation
 *
 * - `Tab` — advance to the next tab (wraps by default)
 * - `Shift+Tab` — retreat to the previous tab (wraps by default)
 * - `1`–`9` — jump directly to tab N (1-indexed)
 *
 * Wrap-around can be disabled via {@see noWrap()}.
 *
 * ## Mouse navigation
 *
 * When a {@see Manager} is supplied (via {@see withZoneManager()}),
 * each tab label is wrapped in a named zone. The parent component
 * should route `MouseMsg` through `Manager::anyInBoundsAndUpdate()`;
 * clicking a visible tab activates it.
 *
 * ## Rendering
 *
 * Tabs render as a single line: ` Home  │  Profile  │  Settings `.
 * The active tab uses the configured {@see $activeStyle}; inactive tabs
 * use {@see $inactiveStyle}. The divider is rendered in the inactive style.
 *
 * When the rendered tab bar exceeds {@see $width}, it is clipped to the
 * available space and ellipsis (`…`) are shown on the overflow side(s)
 * to indicate hidden tabs.
 *
 * Mirrors charmbracelet/bubbles `Tabs`.
 */
final class Tabs implements Model
{
    /** @param list<string> */
    public readonly array $labels;

    /** First tab index visible in the current scroll window (0-based). */
    public readonly int $scrollOffset;

    /** Last tab index visible in the current scroll window (0-based). */
    public readonly int $scrollEnd;

    /**
     * @deprecated Prefer {@see self::new()} (named args). The public ctor
     *     sits beside the factory only for BC; privatization is scheduled
     *     for the next major.
     *
     * @param list<string> $labels
     */
    public function __construct(
        public readonly int $active,
        public readonly Style $activeStyle,
        public readonly Style $inactiveStyle,
        public readonly string $divider,
        public readonly TabsKeyMap $keyMap,
        public readonly bool $focused,
        public readonly bool $wrap,
        public readonly int $width,
        public readonly ?Manager $zoneManager,
        array $labels = [],
        int $scrollOffset = 0,
    ) {
        $labelCount = count($labels);
        if ($active < 0 || ($labelCount > 0 && $active >= $labelCount)) {
            throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
        }
        $this->labels = array_values($labels);
        $this->scrollOffset = max(0, $scrollOffset);
        $this->scrollEnd = $this->computeScrollEnd();
    }

    /** Bubble-Tea Init — Tabs has no background commands. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @param list<string> $labels Tab labels in display order.
     */
    public static function new(array $labels = [], int $width = 80): self
    {
        return new self(
            active: 0,
            activeStyle: Style::new()->bold(),
            inactiveStyle: Style::new(),
            divider: ' │ ',
            keyMap: TabsKeyMap::new(),
            focused: false,
            wrap: true,
            width: $width,
            zoneManager: null,
            labels: $labels,
            scrollOffset: 0,
        );
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        // Mouse click on a tab zone — activate that tab.
        if ($msg instanceof MsgZoneInBounds && $this->focused) {
            $tabIndex = $this->tabIndexFromZoneId($msg->zone->id);
            if ($tabIndex !== null && $tabIndex >= $this->scrollOffset && $tabIndex <= $this->scrollEnd) {
                return [$this->withActive($tabIndex), null];
            }
            return [$this, null];
        }

        if (!($msg instanceof KeyMsg) || !$this->focused) {
            return [$this, null];
        }

        $count = count($this->labels);
        if ($count === 0) {
            return [$this, null];
        }

        // Tab / Shift+Tab navigation
        if ($this->keyMap->nextTab->matches($msg)) {
            $next = $this->wrap
                ? ($this->active + 1) % $count
                : min($this->active + 1, $count - 1);
            $updated = $this->withActive($next)->adjustScroll($next);
            return [$updated, null];
        }

        if ($this->keyMap->prevTab->matches($msg)) {
            $prev = $this->wrap
                ? (($this->active - 1) + $count) % $count
                : max($this->active - 1, 0);
            $updated = $this->withActive($prev)->adjustScroll($prev);
            return [$updated, null];
        }

        // Direct jump: 1-9
        foreach ($this->keyMap->jumpBindings as $i => $binding) {
            if ($binding->matches($msg)) {
                $target = $i; // 0-indexed (jumpBindings[0] = key "1" → tab 0)
                if ($target < $count) {
                    $updated = $this->withActive($target)->adjustScroll($target);
                    return [$updated, null];
                }
            }
        }

        return [$this, null];
    }

    /**
     * Render the tab bar as a single line.
     *
     * Example output with labels `['Home', 'Profile', 'Settings']` and
     * active index 1:
     *
     *     Home  │  Profile  │  Settings
     *     ~~        ^^^^^^        ~~~
     *     inactive   active    inactive
     *
     * When a {@see Manager} is set, each tab label is wrapped in an APC
     * zone marker so the parent can {@see Manager::scan()} to record
     * bounding boxes for mouse routing.
     */
    public function view(): string
    {
        if ($this->labels === []) {
            return '';
        }

        $count = count($this->labels);

        // Build per-tab styled segments, optionally wrapped in zone markers.
        $segments = [];
        foreach ($this->labels as $i => $label) {
            $style = $i === $this->active ? $this->activeStyle : $this->inactiveStyle;
            // M9: while focused, the active tab gains an underline. Before
            // this the `focused` flag drew nothing — focused and blurred
            // frames were byte-identical, so keyboard focus was invisible
            // (an a11y affordance the flag promised but did not deliver).
            if ($i === $this->active && $this->focused) {
                $style = $style->underline();
            }
            $padded = ' ' . self::sanitizeLabel($label) . ' ';

            // M14: zone markup is invisible by Mark's zero-width contract,
            // but Width::string scores each PUA sentinel as one cell —
            // marking BEFORE pricing inflates every tab by 4 + 2·strlen(id)
            // phantom cells (spurious ellipses, dropped tabs) and lets
            // truncateAnsi cut inside a marker pair, orphaning sentinels.
            // All measuring and clipping happens on the unmarked content;
            // mark only the segments that actually reach the frame.
            $segments[$i] = [$padded, $style];
        }

        // The stored scrollEnd IS the visible window's last index: it is
        // computed once per state by computeScrollEnd() using this same bar's
        // ellipsis reservation and sanitised widths (E736/bits-2.4), so view()
        // consumes it instead of re-walking. scrollEnd >= visibleStart holds by
        // construction — both derive from the same clamped start.
        $visibleStart = 0;
        $visibleEnd = $count - 1;
        $leftEllipsis = false;
        $rightEllipsis = false;

        if ($this->width > 0) {
            $visibleStart = min($this->scrollOffset, $count - 1);
            $visibleEnd = $this->scrollEnd;

            // Hidden tabs before the window get a left ellipsis — the very
            // predicate computeScrollEnd() prices, shared via the helper so
            // the walk and the draw can never disagree about its cost.
            $leftEllipsis = $this->rendersLeftEllipsis();

            // Show right ellipsis if there are tabs after visibleEnd.
            $rightEllipsis = $visibleEnd < $count - 1;
        }

        // Build output: optional left ellipsis + visible tabs + optional right ellipsis.
        $parts = [];
        if ($leftEllipsis) {
            $parts[] = ['…', null, null];
        }

        for ($i = $visibleStart; $i <= $visibleEnd; $i++) {
            [$padded, $style] = $segments[$i];
            $parts[] = [$padded, $style, $i];
        }

        if ($rightEllipsis) {
            $parts[] = ['…', null, null];
        }

        $inactiveDivider = $this->inactiveStyle->render($this->divider);

        if ($this->zoneManager === null) {
            $line = implode($inactiveDivider, array_map(
                static fn(array $p): string => $p[1]?->render($p[0]) ?? $p[0],
                $parts,
            ));
            if ($this->width > 0 && Width::string($line) > $this->width) {
                $line = Width::truncateAnsi($line, $this->width - 1) . '…';
            }
            return $line;
        }

        // Zone-attached host: price on the unmarked content and mark only
        // the survivors, so sentinel pairs are never born split (M14).
        $plain = implode($this->divider, array_map(static fn(array $p): string => $p[0], $parts));
        if ($this->width > 0 && Width::string($plain) > $this->width) {
            return $this->assembleMarked($this->clipParts($parts, $this->width - 1)) . '…';
        }
        return $this->assembleMarked($parts);
    }

    /**
     * Render each part, wrapping tab segments in their zone marker. The
     * marking that used to happen per-segment before pricing now happens
     * here — after the window and any clipping have been decided on the
     * invisible markup's zero-width contract (M14).
     *
     * @param list<array{0:string, 1:?Style, 2:?int}> $parts
     */
    private function assembleMarked(array $parts): string
    {
        $rendered = [];
        foreach ($parts as [$text, $style, $tabIndex]) {
            if ($tabIndex !== null) {
                $text = $this->zoneManager->mark("tab-{$tabIndex}", $text);
            }
            $rendered[] = $style?->render($text) ?? $text;
        }
        return implode($this->inactiveStyle->render($this->divider), $rendered);
    }

    /**
     * Clip unmarked parts to $budget cells (the trailing ellipsis lives
     * outside the budget, mirroring the old whole-line truncate). A
     * segment straddling the edge is kept truncated — still a whole
     * marker pair once re-marked — and later segments drop.
     *
     * @param list<array{0:string, 1:?Style, 2:?int}> $parts
     * @return list<array{0:string, 1:?Style, 2:?int}>
     */
    private function clipParts(array $parts, int $budget): array
    {
        $dividerCells = Width::string($this->divider);
        $out = [];
        $used = 0;
        foreach ($parts as [$text, $style, $tabIndex]) {
            $cost = ($out === [] ? 0 : $dividerCells) + Width::string($text);
            if ($used + $cost <= $budget) {
                $out[] = [$text, $style, $tabIndex];
                $used += $cost;
                continue;
            }
            $room = $budget - $used - ($out === [] ? 0 : $dividerCells);
            if ($room > 0) {
                $out[] = [Width::truncateAnsi($text, $room), $style, $tabIndex];
            }
            break;
        }
        return $out;
    }

    /** Currently active tab index (0-based). */
    public function active(): int
    {
        return $this->active;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->labels;
    }

    // ── with* mutators ───────────────────────────────────────────────────────

    /** @param list<string> */
    public function withLabels(array $labels): self
    {
        $newLabels = array_values($labels);
        $newActive = $this->active;
        if ($newActive >= count($newLabels)) {
            $newActive = max(0, count($newLabels) - 1);
        }
        $newOffset = min($this->scrollOffset, max(0, count($newLabels) - 1));
        return $this->copy(
            labels: $newLabels,
            active: $newActive,
            scrollOffset: $newOffset,
        );
    }

    public function withActive(int $index): self
    {
        $count = count($this->labels);
        if ($count === 0) {
            return $this;
        }
        $index = max(0, min($index, $count - 1));
        return $this->copy(active: $index)->adjustScroll($index);
    }

    public function withActiveStyle(Style $style): self
    {
        return $this->copy(activeStyle: $style);
    }

    public function withInactiveStyle(Style $style): self
    {
        return $this->copy(inactiveStyle: $style);
    }

    public function withDivider(string $divider): self
    {
        return $this->copy(divider: $divider);
    }

    public function withKeyMap(TabsKeyMap $keyMap): self
    {
        return $this->copy(keyMap: $keyMap);
    }

    public function withWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.neg_width'));
        }
        return $this->copy(width: $width);
    }

    /**
     * Attach a {@see Manager} for mouse-click zone tracking.
     *
     * When a manager is attached, each tab label is wrapped in a named
     * APC zone (`tab-0`, `tab-1`, …). The parent should call
     * `Manager::scan()` on the {@see view()} output to record zone
     * bounds, then route {@see MouseMsg} through
     * `Manager::anyInBoundsAndUpdate($tabs, $mouseMsg)`.
     */
    public function withZoneManager(?Manager $manager): self
    {
        return $this->copy(zoneManager: $manager, zoneManagerSet: true);
    }

    /**
     * Manually set the scroll offset (first visible tab index), clamped to
     * the tab list. Auto-scroll that keeps the active tab visible is driven
     * internally by {@see update()} — this setter pins an explicit offset.
     */
    public function withScrollOffset(int $offset): self
    {
        return $this->copy(scrollOffset: max(0, min($offset, count($this->labels) - 1)));
    }

    /**
     * Disable wrap-around at the ends of the tab list.
     * With wrap disabled, `Tab` clamps at the last tab and
     * `Shift+Tab` clamps at the first tab.
     */
    public function noWrap(): self
    {
        return $this->copy(wrap: false);
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [$this->copy(focused: true), null];
    }

    public function blur(): self
    {
        return $this->copy(focused: false);
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Parse a tab zone ID (e.g. "tab-3" or "prefixtab-3") and return the
     * tab index, or null if the ID does not match the expected format.
     *
     * The minted segment is always the LAST "tab-" in the id (zone ids are
     * minted as "[prefix]tab-N"); scanning for the first occurrence let a
     * prefix containing "tab-<digits>" mis-route clicks to the wrong tab (N13).
     */
    private function tabIndexFromZoneId(string $id): ?int
    {
        $pos = strrpos($id, 'tab-');
        if ($pos === false) {
            return null;
        }
        $suffix = substr($id, $pos + 4);
        if ($suffix === '' || !ctype_digit($suffix)) {
            return null;
        }
        $idx = (int) $suffix;
        return $idx >= 0 && $idx < count($this->labels) ? $idx : null;
    }

    /**
     * Auto-scroll the scroll offset to keep the given tab index visible.
     */
    private function adjustScroll(int $tabIndex): self
    {
        if ($this->width <= 0) {
            return $this;
        }
        $count = count($this->labels);
        if ($count === 0) {
            return $this;
        }

        // If tab is before visible window, scroll back.
        if ($tabIndex < $this->scrollOffset) {
            return $this->withScrollOffset($tabIndex);
        }
        // If tab is after visible window, scroll forward.
        if ($tabIndex > $this->scrollEnd) {
            return $this->withScrollOffset($tabIndex);
        }
        return $this;
    }

    /**
     * Compute the last tab index the bar can render FULLY for the current
     * scrollOffset and width — the single window truth shared by
     * {@see view()} and {@see adjustScroll()} (E736/bits-2.4).
     *
     * The cursor walks to the cell where each candidate label ENDS, and a
     * tab only enters the window if it survives rendering: the window's
     * last tab needs to fit within the bar (`labelEnd <= width`), while
     * any other candidate also owes a right `…` for the tabs it hides —
     * when the line runs wide view()'s guard keeps `width - 1` cells
     * before appending its own `…`, so a mid-list tail tab renders whole
     * only while `labelEnd <= width - 1`. Pricing the left `…` is part of
     * the same honesty: view() joins it with a divider, so it already
     * occupies cells before the first label starts. Under-counting any
     * of this (one bare cell for the right `…`, nothing for the left)
     * let the stored window claim a tab view() then clipped away — the
     * bold active tab could silently vanish (r87 review MAJOR). The
     * first tab after the offset is included even if it alone overflows
     * — an empty bar is worse than a clipped one. Widths ride the same
     * control-char sanitisation view() renders, so the stored window and
     * the drawn window cannot drift.
     */
    private function computeScrollEnd(): int
    {
        $count = count($this->labels);
        if ($count === 0 || $this->width <= 0) {
            return $count - 1;
        }

        $start = min($this->scrollOffset, $count - 1);
        $dividerWidth = Width::string($this->divider);
        // The left `…` is never standalone: view() joins it to the first
        // tab with a divider, so cells before the first label starts.
        $cursor = $this->rendersLeftEllipsis() ? 1 + $dividerWidth : 0;

        for ($i = $start; $i < $count; $i++) {
            $labelEnd = $cursor + $this->tabWidth($i);
            // A right `…` follows every tab except the last; the clip
            // guard preserves only width-1 cells once that line overflows.
            $bound = $i < $count - 1 ? $this->width - 1 : $this->width;
            if ($labelEnd > $bound && $i > $start) {
                return $i - 1;
            }
            // The next tab joins after this label with a divider.
            $cursor = $labelEnd + $dividerWidth;
        }
        return $count - 1;
    }

    /** Whether view() will prepend a left `…` for the current offset. */
    private function rendersLeftEllipsis(): bool
    {
        return $this->width > 0
            && $this->scrollOffset > 0
            && min($this->scrollOffset, count($this->labels) - 1) > 0;
    }

    /**
     * Sanitise one label for the marked-frame world.
     *
     * `controlChars` folds C0 (newlines/tabs) and drops ESC, but by design
     * passes the U+E000/U+E001 zone sentinels through — and view() hands the
     * label to `Manager::mark()` right after, which wraps it in exactly those
     * sentinels. A label carrying a forged sentinel pair could therefore open
     * or steal a zone (sugar-widgets review C3, the same fleet cure as the
     * other marked-frame components). `untrustedForMarkedFrames()` adds the
     * ANSI sweep and the sentinel strip on top. tabWidth() runs
     * the SAME pipeline so priced width and drawn width never drift.
     */
    private static function sanitizeLabel(string $label): string
    {
        return Sanitize::untrustedForMarkedFrames(Sanitize::controlChars($label));
    }

    /** Rendered cell width of one tab: padded, sanitised — zone markup is invisible. */
    private function tabWidth(int $i): int
    {
        return Width::string(' ' . self::sanitizeLabel($this->labels[$i]) . ' ');
    }

    /**
     * Internal copy-with-overrides helper — rebuilds the Tabs via the
     * constructor with the same pattern as Tree::copy() / Table::mutate().
     */
    private function copy(
        ?int $active = null,
        ?Style $activeStyle = null,
        ?Style $inactiveStyle = null,
        ?string $divider = null,
        ?TabsKeyMap $keyMap = null,
        ?bool $focused = null,
        ?bool $wrap = null,
        ?int $width = null,
        ?Manager $zoneManager = null,
        bool $zoneManagerSet = false,
        ?array $labels = null,
        ?int $scrollOffset = null,
    ): self {
        return new self(
            active: $active ?? $this->active,
            activeStyle: $activeStyle ?? $this->activeStyle,
            inactiveStyle: $inactiveStyle ?? $this->inactiveStyle,
            divider: $divider ?? $this->divider,
            keyMap: $keyMap ?? $this->keyMap,
            focused: $focused ?? $this->focused,
            wrap: $wrap ?? $this->wrap,
            width: $width ?? $this->width,
            // The Manager is nullable, so `?? carry` could never express
            // "detach" — withZoneManager(null) was a silent no-op (M10).
            // The paired sentinel makes an explicit null sticky.
            zoneManager: $zoneManagerSet ? $zoneManager : $this->zoneManager,
            labels: $labels ?? $this->labels,
            scrollOffset: $scrollOffset ?? $this->scrollOffset,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
