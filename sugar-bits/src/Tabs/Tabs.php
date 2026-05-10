<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tabs;

use SugarCraft\Bits\Lang;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Zone\Manager;
use SugarCraft\Zone\MsgZoneInBounds;

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
            keyMap: TabsKeyMap::default(),
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

        if (!$msg instanceof KeyMsg || !$this->focused) {
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
        $tabWidths = [];
        foreach ($this->labels as $i => $label) {
            $style = $i === $this->active ? $this->activeStyle : $this->inactiveStyle;
            $padded = ' ' . $label . ' ';
            $tabWidths[$i] = Width::string($padded);

            if ($this->zoneManager !== null) {
                $padded = $this->zoneManager->mark("tab-{$i}", $padded);
            }
            $segments[$i] = [$padded, $style];
        }

        // Compute visible range respecting available width.
        $available = $this->width > 0 ? $this->width : PHP_INT_MAX;
        $dividerWidth = Width::string($this->divider);
        $ellipsisWidth = 1; // Width::string('…')

        $visibleStart = 0;
        $visibleEnd = $count - 1;
        $leftEllipsis = false;
        $rightEllipsis = false;

        if ($this->width > 0) {
            // Find which tabs fit, starting from scrollOffset.
            $cursor = 0;
            $visibleStart = min($this->scrollOffset, $count - 1);
            $visibleEnd = $visibleStart;

            // Always show ellipsis on left if we've scrolled right.
            $leftEllipsis = $this->scrollOffset > 0;

            // Walk forward from visibleStart, adding tabs until we run out of space.
            for ($i = $visibleStart; $i < $count; $i++) {
                $tabWidth = $tabWidths[$i];
                $nextCursor = $cursor + $tabWidth;
                if ($i > $visibleStart) {
                    $nextCursor += $dividerWidth;
                }

                if ($nextCursor > $available && $cursor > 0) {
                    break;
                }
                $cursor = $nextCursor;
                $visibleEnd = $i;
            }

            // Show right ellipsis if there are tabs after visibleEnd.
            $rightEllipsis = $visibleEnd < $count - 1;

            // Adjust left ellipsis if we can't show tabs before visibleStart
            // (i.e., visibleStart is already at the minimum).
            if ($visibleStart === 0) {
                $leftEllipsis = false;
            }
        }

        // Build output: optional left ellipsis + visible tabs + optional right ellipsis.
        $parts = [];
        if ($leftEllipsis) {
            $parts[] = $this->inactiveStyle->render('…');
        }

        for ($i = $visibleStart; $i <= $visibleEnd; $i++) {
            [$padded, $style] = $segments[$i];
            $parts[] = $style->render($padded);
        }

        if ($rightEllipsis) {
            $parts[] = $this->inactiveStyle->render('…');
        }

        $inactiveDivider = $this->inactiveStyle->render($this->divider);
        $line = implode($inactiveDivider, $parts);

        if ($this->width > 0 && mb_strlen($line, 'UTF-8') > $this->width) {
            $line = mb_strcut($line, 0, $this->width - 1, 'UTF-8') . '…';
        }

        return $line;
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
        return new self(
            active: $newActive,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $newLabels,
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
        $new = new self(
            active: $index,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
        return $new->adjustScroll($index);
    }

    public function withActiveStyle(Style $style): self
    {
        return new self(
            active: $this->active,
            activeStyle: $style,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    public function withInactiveStyle(Style $style): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $style,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    public function withDivider(string $divider): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    public function withKeyMap(TabsKeyMap $keyMap): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    public function withWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.neg_width'));
        }
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
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
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $manager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    /**
     * Manually set the scroll offset (first visible tab index).
     * Use `null` to auto-scroll to keep the active tab visible.
     */
    public function withScrollOffset(int $offset): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: max(0, min($offset, count($this->labels) - 1)),
        );
    }

    /**
     * Disable wrap-around at the ends of the tab list.
     * With wrap disabled, `Tab` clamps at the last tab and
     * `Shift+Tab` clamps at the first tab.
     */
    public function noWrap(): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: $this->focused,
            wrap: false,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    /**
     * @return array{0:self, 1:?\Closure}
     */
    public function focus(): array
    {
        return [
            new self(
                active: $this->active,
                activeStyle: $this->activeStyle,
                inactiveStyle: $this->inactiveStyle,
                divider: $this->divider,
                keyMap: $this->keyMap,
                focused: true,
                wrap: $this->wrap,
                width: $this->width,
                zoneManager: $this->zoneManager,
                labels: $this->labels,
                scrollOffset: $this->scrollOffset,
            ),
            null,
        ];
    }

    public function blur(): self
    {
        return new self(
            active: $this->active,
            activeStyle: $this->activeStyle,
            inactiveStyle: $this->inactiveStyle,
            divider: $this->divider,
            keyMap: $this->keyMap,
            focused: false,
            wrap: $this->wrap,
            width: $this->width,
            zoneManager: $this->zoneManager,
            labels: $this->labels,
            scrollOffset: $this->scrollOffset,
        );
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Parse a tab zone ID (e.g. "tab-3" or "prefixtab-3") and return the
     * tab index, or null if the ID does not match the expected format.
     */
    private function tabIndexFromZoneId(string $id): ?int
    {
        // Zone ID format: [{prefix}]tab-{index}. Strip any prefix before "tab-".
        $pos = strpos($id, 'tab-');
        if ($pos !== false) {
            $idx = (int) substr($id, $pos + 4);
            return $idx >= 0 && $idx < count($this->labels) ? $idx : null;
        }
        return null;
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
     * Compute the last visible tab index given the current scrollOffset
     * and available width.
     */
    private function computeScrollEnd(): int
    {
        $count = count($this->labels);
        if ($count === 0 || $this->width <= 0) {
            return $count - 1;
        }

        $dividerWidth = Width::string($this->divider);
        $cursor = 0;

        for ($i = $this->scrollOffset; $i < $count; $i++) {
            $label = $this->labels[$i];
            $tabWidth = Width::string(' ' . $label . ' ');
            $nextCursor = $cursor + $tabWidth;
            if ($i > $this->scrollOffset) {
                $nextCursor += $dividerWidth;
            }
            if ($nextCursor > $this->width && $i > $this->scrollOffset) {
                return $i - 1;
            }
            $cursor = $nextCursor;
        }
        return $count - 1;
    }
}
