<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tabs;

use SugarCraft\Widgets\Lang;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Style;
use SugarCraft\Mouse\Mark;
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
 * - `Ctrl+W` — close the active tab (closable bars only)
 *
 * Wrap-around can be disabled via {@see noWrap()}. Keyboard input is
 * routed to the focused bar only (the family law shared with Table and
 * Tree); pointer input is never focus-gated.
 *
 * ## Mouse navigation
 *
 * Three routes, all accepted whether or not the bar is focused:
 *
 * - **Manager zones** ({@see withZoneManager()}): each visible tab is
 *   wrapped in a named zone; the parent routes `MouseMsg` through
 *   `Manager::anyInBoundsAndUpdate()` and the resulting
 *   {@see MsgZoneInBounds} reaches {@see update()}.
 * - **Mark zones** ({@see withMark()}): the same zone markup through a
 *   manager-free {@see Mark}, the way Breadcrumb and the candy-code
 *   renderer publish zones; a host that scans the frame with a
 *   `Scanner` hands the hit zone id to {@see clickZone()}.
 * - **Spans** ({@see spans()}, {@see clickAt()}): no markup at all; the
 *   host subtracts the bar's screen column and passes the bar column.
 *
 * A click on a tab activates it. On a closable bar
 * ({@see withClosable()}) a click on the close affordance closes that
 * tab and returns a `Cmd` carrying {@see TabClosedMsg}.
 *
 * ## Interactive bars
 *
 * {@see interactive()} builds a bar with zones attached in one call:
 * `Tabs::interactive($labels, $zones)->withBadge(1, '3')->withClosable()`.
 * Tabs reorder with {@see moveTab()} and close with {@see closeTab()};
 * badges and the active tab follow the tab they belong to.
 *
 * ## Rendering
 *
 * Tabs render as a single line: ` Home  │  Profile  │  Settings `.
 * The active tab uses the configured {@see $activeStyle}; inactive tabs
 * use {@see $inactiveStyle}. The divider is rendered in the
 * {@see withDividerStyle()} style, or the inactive style when none is set.
 *
 * When the rendered tab bar exceeds {@see $width}, it is clipped to the
 * available space and scroll marks (`…` by default, see
 * {@see withScrollMarks()}) are shown on the overflow side(s) to indicate
 * hidden tabs. `width 0` means NO clip — the whole bar renders (W-35
 * dimension law; `::new()` defaults to 0 like {@see Table}, whose default
 * is 0 = auto). {@see withScrollWindow()} picks how the window follows the
 * active tab.
 *
 * Mirrors charmbracelet/bubbles `Tabs`.
 */
final class Tabs implements Model
{
    /** Default close-affordance glyph for {@see withClosable()}. */
    public const CLOSE_GLYPH = '×';

    /** Default glyph of both scroll marks for {@see withScrollMarks()}. */
    public const SCROLL_MARK = '…';

    /** @var list<string> */
    public readonly array $labels;

    /**
     * Per-tab badge text keyed by tab index (sanitized at render time).
     *
     * @var array<int, string>
     */
    public readonly array $badges;

    /** First tab index visible in the current scroll window (0-based). */
    public readonly int $scrollOffset;

    /** Last tab index visible in the current scroll window (0-based). */
    public readonly int $scrollEnd;

    /** Process-wide counter that mints a distinct {@see $zoneKey} per bar. */
    private static int $zoneKeySeq = 0;

    /**
     * Private on purpose (W-09): a direct `new Tabs(...)` fails with PHP's
     * "Call to private Tabs::__construct()" Error; use {@see self::new()}
     * plus `with*()`.
     *
     * `$zoneKey` scopes this bar's zone ids. Two bars that share one
     * {@see Manager} would otherwise mint identical `tab-N` ids, and a click
     * on one bar would activate a tab on every bar that received it.
     *
     * @param list<string> $labels
     */
    private function __construct(
        public readonly int $active,
        public readonly Style $activeStyle,
        public readonly Style $inactiveStyle,
        public readonly string $divider,
        public readonly TabsKeyMap $keyMap,
        public readonly bool $focused,
        public readonly bool $wrap,
        public readonly int $width,
        public readonly ?Manager $zoneManager,
        public readonly string $zoneKey,
        array $labels = [],
        int $scrollOffset = 0,
        array $badges = [],
        public readonly bool $closable = false,
        public readonly string $closeGlyph = self::CLOSE_GLYPH,
        public readonly ?Style $badgeStyle = null,
        public readonly ?Mark $mark = null,
        public readonly string $placeholder = '',
        public readonly int $paddingLeft = 1,
        public readonly int $paddingRight = 1,
        public readonly ?Style $dividerStyle = null,
        public readonly string $leftMark = self::SCROLL_MARK,
        public readonly string $rightMark = self::SCROLL_MARK,
        public readonly ?Style $markStyle = null,
        public readonly bool $separatorAfterLeft = true,
        public readonly ScrollWindow $scrollWindow = ScrollWindow::Offset,
    ) {
        $labelCount = count($labels);
        if ($active < 0 || ($labelCount > 0 && $active >= $labelCount)) {
            throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
        }
        $this->labels = array_values($labels);
        foreach ($badges as $index => $badge) {
            if (!is_int($index) || $index < 0 || $index >= $labelCount) {
                throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
            }
        }
        ksort($badges);
        $this->badges = $badges;
        $this->scrollOffset = $scrollWindow === ScrollWindow::FirstKeepingActive
            ? $this->firstOffsetKeepingActive()
            : max(0, $scrollOffset);
        $this->scrollEnd = $this->computeScrollEnd($this->scrollOffset);
    }

    /** Bubble-Tea Init — Tabs has no background commands. */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @param list<string> $labels Tab labels in display order.
     */
    public static function new(array $labels = [], int $width = 0): self
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
            zoneKey: 'tabs' . (++self::$zoneKeySeq) . '-',
            labels: $labels,
            scrollOffset: 0,
        );
    }

    /**
     * A bar ready for pointer input: {@see new()} with its zones attached.
     *
     * `$zones` is either a {@see Manager} (clicks arrive as
     * {@see MsgZoneInBounds} through {@see update()}) or a manager-free
     * {@see Mark} (the host scans the frame and calls {@see clickZone()}).
     * The bar starts blurred like every bar: clicks work at once, keys
     * once the host calls {@see focus()}.
     *
     * @param list<string> $labels Tab labels in display order.
     */
    public static function interactive(array $labels, Manager|Mark $zones, int $width = 0): self
    {
        $bar = self::new($labels, $width);
        return $zones instanceof Manager ? $bar->withZoneManager($zones) : $bar->withMark($zones);
    }

    /**
     * Keyboard input is handled only while focused. Zone clicks
     * ({@see MsgZoneInBounds}) are handled whether or not the bar is
     * focused, and never grant focus. The `Cmd` is non-null only when a
     * tab was closed; it yields a {@see TabClosedMsg}.
     *
     * @return array{0:Tabs, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof MsgZoneInBounds) {
            return $this->clickZone($msg->zone->id);
        }

        if (!($msg instanceof KeyMsg) || !$this->focused) {
            return [$this, null];
        }

        $count = count($this->labels);
        if ($count === 0) {
            return [$this, null];
        }

        if ($this->closable && $this->keyMap->closeTab->matches($msg)) {
            return $this->closeWithMsg($this->active);
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
     * A badge renders after its label (` Inbox 3 `), and a closable bar
     * appends the close affordance to every tab (` Home × `).
     *
     * When a {@see Manager} or a {@see Mark} is attached, each visible tab
     * is wrapped in a zone marker (`<zoneKey>tab-N`, and `<zoneKey>close-N`
     * around the close affordance) so the parent can scan the frame for
     * mouse routing. A Manager prefixes the ids with its own prefix; a Mark
     * emits them as they are.
     *
     * An empty label list renders the placeholder set by
     * {@see withPlaceholder()} in the inactive style, or the empty string
     * when none is set (the default).
     */
    public function view(): string
    {
        if ($this->labels === []) {
            return $this->viewPlaceholder();
        }

        $parts = $this->windowParts();

        if (!$this->marksZones()) {
            $line = $this->joinParts(
                $parts,
                array_map(fn(array $part): string => $this->renderPieces($part[0]), $parts),
            );
            if ($this->width > 0 && Width::string($line) > $this->width) {
                $line = Width::truncateAnsi($line, $this->clipBudget()) . $this->clipMark();
            }
            return $line;
        }

        // M14: zone markup is invisible by Mark's zero-width contract, but
        // Width::string scores each PUA sentinel as one cell — marking BEFORE
        // pricing inflates every tab by phantom cells and lets truncateAnsi
        // cut inside a marker pair, orphaning sentinels. Price and clip the
        // unmarked content, then mark only the survivors.
        if ($this->width > 0 && $this->plainWidth($parts) > $this->width) {
            [$kept, $tail] = $this->clipParts($parts, $this->clipBudget());
            return $this->assembleMarked($kept) . $tail . $this->clipMark();
        }
        return $this->assembleMarked($parts);
    }

    /**
     * The cells each visible tab occupies on the frame {@see view()} draws,
     * in display order — the hit-testing surface for hosts that route
     * clicks by column instead of by zone markup.
     *
     * Columns are relative to the bar's first cell. Tabs scrolled out of
     * the window are absent; a tab the width clips reports only its
     * visible cells. An empty bar has no spans.
     *
     * @return list<TabSpan>
     */
    public function spans(): array
    {
        if ($this->labels === []) {
            return [];
        }

        $parts = $this->windowParts();
        $dividerWidth = Width::string($this->divider);
        $bound = $this->width > 0 && $this->plainWidth($parts) > $this->width
            ? $this->clipBudget()
            : PHP_INT_MAX;

        $spans = [];
        $cursor = 0;
        foreach ($parts as $k => [$pieces, $tabIndex]) {
            if ($this->joinsWithDivider($parts, $k)) {
                $cursor += $dividerWidth;
            }
            $start = $cursor;
            $closeStart = null;
            foreach ($pieces as [$text, , $isClose]) {
                if ($isClose) {
                    $closeStart = $cursor;
                }
                $cursor += Width::string($text);
            }
            if ($tabIndex === null || $start >= $bound) {
                continue;
            }
            $end = min($cursor, $bound);
            $spans[] = new TabSpan(
                index: $tabIndex,
                start: $start,
                end: $end,
                closeStart: $closeStart !== null && $closeStart < $end ? $closeStart : null,
            );
        }
        return $spans;
    }

    /**
     * The visible tab under bar column `$col`, or null (a divider, a
     * scroll mark, or past the end).
     */
    public function tabAt(int $col): ?int
    {
        foreach ($this->spans() as $span) {
            if ($span->contains($col)) {
                return $span->index;
            }
        }
        return null;
    }

    /**
     * Apply a click at bar column `$col` (0-based, relative to the bar's
     * first cell): a click on a tab activates it, a click on a closable
     * tab's close affordance closes it. Not focus-gated.
     *
     * @return array{0:Tabs, 1:?\Closure}
     */
    public function clickAt(int $col): array
    {
        foreach ($this->spans() as $span) {
            if (!$span->contains($col)) {
                continue;
            }
            if ($this->closable && $span->onClose($col)) {
                return $this->closeWithMsg($span->index);
            }
            return [$this->withActive($span->index), null];
        }
        return [$this, null];
    }

    /**
     * Apply a click on the zone named `$zoneId` — the id exactly as it
     * appears in the frame (with the Manager prefix on a Manager bar, bare
     * on a Mark bar). Ids minted by another bar, or naming a tab outside
     * the visible window, leave the bar unchanged. Not focus-gated.
     *
     * @return array{0:Tabs, 1:?\Closure}
     */
    public function clickZone(string $zoneId): array
    {
        $tabIndex = $this->tabIndexFromZoneId($zoneId, 'tab-');
        if ($tabIndex !== null && $this->isVisible($tabIndex)) {
            return [$this->withActive($tabIndex), null];
        }
        if ($this->closable) {
            $closeIndex = $this->tabIndexFromZoneId($zoneId, 'close-');
            if ($closeIndex !== null && $this->isVisible($closeIndex)) {
                return $this->closeWithMsg($closeIndex);
            }
        }
        return [$this, null];
    }

    /** The empty-bar frame: the placeholder, styled and clipped, or nothing. */
    private function viewPlaceholder(): string
    {
        if ($this->placeholder === '') {
            return '';
        }
        $text = self::sanitizeLabel($this->placeholder);
        if ($this->width > 0 && Width::string($text) > $this->width) {
            $text = Width::truncateAnsi($text, $this->width - 1) . '…';
        }
        return $this->inactiveStyle->render($text);
    }

    /** Whether view() wraps tabs in zone markup (a Manager or a Mark is attached). */
    private function marksZones(): bool
    {
        return $this->zoneManager !== null || $this->mark !== null;
    }

    /**
     * The parts view() draws, left to right: an optional left scroll mark,
     * the tabs of the scroll window, an optional right scroll mark. Each
     * part is its pieces plus the tab index it belongs to (null for a mark).
     *
     * The stored scrollEnd IS the visible window's last index: it is
     * computed once per state by computeScrollEnd() using the same ellipsis
     * reservation and sanitized widths (E736/bits-2.4), so this consumes it
     * instead of re-walking.
     *
     * @return list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}>
     */
    private function windowParts(): array
    {
        $count = count($this->labels);
        $visibleStart = 0;
        $visibleEnd = $count - 1;
        $leftEllipsis = false;
        $rightEllipsis = false;

        if ($this->width > 0) {
            $visibleStart = min($this->scrollOffset, $count - 1);
            $visibleEnd = $this->scrollEnd;
            // The very predicate computeScrollEnd() prices, shared via the
            // helper so the walk and the draw can never disagree about it.
            $leftEllipsis = $this->rendersLeftEllipsis();
            $rightEllipsis = $visibleEnd < $count - 1;
        }

        $parts = [];
        if ($leftEllipsis) {
            $parts[] = [[[$this->leftMark, $this->markStyle, false]], null];
        }
        for ($i = $visibleStart; $i <= $visibleEnd; $i++) {
            $parts[] = [$this->tabPieces($i), $i];
        }
        if ($rightEllipsis) {
            $parts[] = [[[$this->rightMark, $this->markStyle, false]], null];
        }
        return $parts;
    }

    /**
     * Whether part `$k` is drawn after a divider: every part but the first
     * is, except the first tab after a left scroll mark when
     * {@see withScrollMarks()} turned the separator after the left mark off.
     * The left mark is the only part that can lead with a null tab index:
     * a window always holds at least one tab.
     *
     * @param list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}> $parts
     */
    private function joinsWithDivider(array $parts, int $k): bool
    {
        if ($k === 0) {
            return false;
        }
        return $this->separatorAfterLeft || $k !== 1 || $parts[0][1] !== null;
    }

    /**
     * Join the rendered `$parts` with the styled divider where
     * {@see joinsWithDivider()} puts one.
     *
     * @param list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}> $parts
     * @param list<string> $rendered
     */
    private function joinParts(array $parts, array $rendered): string
    {
        $divider = $this->renderDivider($this->divider);
        $line = '';
        foreach ($rendered as $k => $text) {
            if ($this->joinsWithDivider($parts, $k)) {
                $line .= $divider;
            }
            $line .= $text;
        }
        return $line;
    }

    /** Divider text in the divider style, or the inactive style when none is set. */
    private function renderDivider(string $text): string
    {
        return ($this->dividerStyle ?? $this->inactiveStyle)->render($text);
    }

    /** A scroll mark in the mark style, or unstyled when none is set. */
    private function renderMark(string $mark): string
    {
        return $this->markStyle?->render($mark) ?? $mark;
    }

    /**
     * The right mark an overflowing line ends with, cut to the bar's width
     * so a mark wider than the bar cannot push the frame past it (the clip
     * budget is then 0 and the mark alone fills the bar).
     */
    private function clipMark(): string
    {
        return $this->renderMark(Width::truncateAnsi($this->rightMark, $this->width));
    }

    /**
     * The cells an overflowing line keeps before the right mark the clip
     * appends after them.
     */
    private function clipBudget(): int
    {
        return max(0, $this->width - Width::string($this->rightMark));
    }

    /**
     * The styled pieces of tab `$i`: the padded label, then the badge,
     * then the close affordance (flagged true). While focused the active
     * tab gains an underline: the `focused` flag must DRAW something, or
     * focused and blurred frames are byte-identical and keyboard focus is
     * invisible.
     *
     * @return list<array{0:string, 1:?Style, 2:bool}>
     */
    private function tabPieces(int $i): array
    {
        $style = $i === $this->active ? $this->activeStyle : $this->inactiveStyle;
        if ($i === $this->active && $this->focused) {
            $style = $style->underline();
        }
        $texts = $this->tabTexts($i);
        $pieces = [[$texts['label'], $style, false]];
        if ($texts['badge'] !== null) {
            $pieces[] = [$texts['badge'], $this->badgeStyle ?? $style, false];
        }
        if ($texts['close'] !== null) {
            $pieces[] = [$texts['close'], $style, true];
        }
        return $pieces;
    }

    /**
     * The sanitized, unstyled texts of tab `$i` — the single source both
     * the draw ({@see tabPieces()}) and the pricing ({@see tabWidth()})
     * read, so priced and drawn widths never drift. The label carries the
     * configured {@see withLabelPadding()} cells on each side; spans(),
     * tabAt(), clickAt() and the scroll window all measure these texts, so
     * the hit-test follows any padding without arithmetic of its own.
     *
     * @return array{label:string, badge:?string, close:?string}
     */
    private function tabTexts(int $i): array
    {
        $badge = isset($this->badges[$i]) ? self::sanitizeLabel($this->badges[$i]) : '';
        return [
            'label' => str_repeat(' ', $this->paddingLeft)
                . self::sanitizeLabel($this->labels[$i])
                . str_repeat(' ', $this->paddingRight),
            'badge' => $badge === '' ? null : $badge . ' ',
            'close' => $this->closable ? $this->closeGlyph . ' ' : null,
        ];
    }

    /** @param list<array{0:string, 1:?Style, 2:bool}> $pieces */
    private function renderPieces(array $pieces): string
    {
        $out = '';
        foreach ($pieces as [$text, $style]) {
            $out .= $style?->render($text) ?? $text;
        }
        return $out;
    }

    /** @param list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}> $parts */
    private function plainWidth(array $parts): int
    {
        $cells = 0;
        foreach ($parts as $k => [$pieces]) {
            if ($this->joinsWithDivider($parts, $k)) {
                $cells += Width::string($this->divider);
            }
            foreach ($pieces as [$text]) {
                $cells += Width::string($text);
            }
        }
        return $cells;
    }

    /**
     * Render each part, wrapping a tab's label and badge in its tab zone
     * and its close affordance in its close zone — after the window and
     * any clipping have been decided on unmarked content (M14).
     *
     * A zone over a single piece is marked inside the piece's style (the
     * bytes a plain tab has always rendered); a zone over several pieces
     * wraps their rendered concatenation.
     *
     * @param list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}> $parts
     */
    private function assembleMarked(array $parts): string
    {
        $rendered = [];
        foreach ($parts as [$pieces, $tabIndex]) {
            if ($tabIndex === null) {
                $rendered[] = $this->renderPieces($pieces);
                continue;
            }
            $tabPieces = array_values(array_filter($pieces, static fn(array $p): bool => !$p[2]));
            $closePieces = array_values(array_filter($pieces, static fn(array $p): bool => $p[2]));
            $out = $this->markGroup($this->zoneKey . "tab-{$tabIndex}", $tabPieces);
            if ($closePieces !== []) {
                $out .= $this->markGroup($this->zoneKey . "close-{$tabIndex}", $closePieces);
            }
            $rendered[] = $out;
        }
        return $this->joinParts($parts, $rendered);
    }

    /** @param list<array{0:string, 1:?Style, 2:bool}> $pieces */
    private function markGroup(string $id, array $pieces): string
    {
        if ($pieces === []) {
            return '';
        }
        if (count($pieces) === 1) {
            [$text, $style] = $pieces[0];
            $marked = $this->markZone($id, $text);
            return $style?->render($marked) ?? $marked;
        }
        return $this->markZone($id, $this->renderPieces($pieces));
    }

    /** Wrap `$content` in the zone `$id` through the attached Manager, else the Mark. */
    private function markZone(string $id, string $content): string
    {
        if ($this->zoneManager !== null) {
            return $this->zoneManager->mark($id, $content);
        }
        return $this->mark?->wrap($id, $content) ?? $content;
    }

    /**
     * Clip unmarked parts to $budget cells (the trailing ellipsis lives
     * outside the budget, mirroring the whole-line truncate of the unmarked
     * path). A part straddling the edge keeps its leading pieces, the
     * straddling piece truncated — still whole marker pairs once re-marked
     * — and later parts drop.
     *
     * A divider the edge reaches before the next part's first cell is not
     * dropped: the unmarked path truncates the joined line and keeps the
     * divider's leading cells, so it comes back as the styled tail, drawn
     * after the kept parts. Without it a marked bar is narrower than the
     * same unmarked bar and the row shifts when markup is toggled.
     *
     * @param list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}> $parts
     * @return array{0:list<array{0:list<array{0:string, 1:?Style, 2:bool}>, 1:?int}>, 1:string}
     */
    private function clipParts(array $parts, int $budget): array
    {
        $dividerCells = Width::string($this->divider);
        $out = [];
        $used = 0;
        foreach ($parts as $k => [$pieces, $tabIndex]) {
            $lead = $this->joinsWithDivider($parts, $k) ? $dividerCells : 0;
            $cost = $lead + $this->plainWidth([[$pieces, $tabIndex]]);
            if ($used + $cost <= $budget) {
                $out[] = [$pieces, $tabIndex];
                $used += $cost;
                continue;
            }
            $room = $budget - $used - $lead;
            if ($room <= 0) {
                $left = $budget - $used;
                $tail = $left > 0
                    ? $this->renderDivider(Width::truncateAnsi($this->divider, $left))
                    : '';
                return [$out, $tail];
            }
            $kept = [];
            foreach ($pieces as [$text, $style, $isClose]) {
                if ($room <= 0) {
                    break;
                }
                $cells = Width::string($text);
                $kept[] = [$cells <= $room ? $text : Width::truncateAnsi($text, $room), $style, $isClose];
                $room -= $cells;
            }
            $out[] = [$kept, $tabIndex];
            break;
        }
        return [$out, ''];
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

    /** Badge text of tab `$index`, or null when the tab carries none. */
    public function badge(int $index): ?string
    {
        return $this->badges[$index] ?? null;
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
        // Badges are keyed by index: a slot that still exists keeps its
        // badge, a slot past the new end loses it.
        $newBadges = array_filter(
            $this->badges,
            static fn(int $i): bool => $i < count($newLabels),
            ARRAY_FILTER_USE_KEY,
        );
        return $this->mutate(
            labels: $newLabels,
            active: $newActive,
            scrollOffset: $newOffset,
            badges: $newBadges,
        );
    }

    /**
     * Set the badge of tab `$index` (an unread count, a `!`, a dirty dot),
     * rendered after the label. Null or the empty string removes it.
     * Badge text is sanitized like labels, so it cannot forge zone markup.
     *
     * @throws \InvalidArgumentException when `$index` is not a tab.
     */
    public function withBadge(int $index, ?string $badge): self
    {
        $this->assertTab($index);
        $badges = $this->badges;
        if ($badge === null || $badge === '') {
            unset($badges[$index]);
        } else {
            $badges[$index] = $badge;
        }
        return $this->mutate(badges: $badges);
    }

    /** Style for badges; null (the default) draws a badge in its tab's style. */
    public function withBadgeStyle(?Style $style): self
    {
        return $this->mutate(badgeStyle: $style, badgeStyleSet: true);
    }

    /**
     * Make every tab closable: each tab draws a close affordance after its
     * label (and badge), a click on it closes the tab, and the keymap's
     * `closeTab` binding (`ctrl+w`) closes the active tab while focused.
     * Each close returns a `Cmd` carrying {@see TabClosedMsg}.
     */
    public function withClosable(bool $closable = true): self
    {
        return $this->mutate(closable: $closable);
    }

    /**
     * Replace the close-affordance glyph (default {@see CLOSE_GLYPH}).
     *
     * @throws \InvalidArgumentException when the glyph is empty after sanitizing.
     */
    public function withCloseGlyph(string $glyph): self
    {
        $clean = self::sanitizeLabel($glyph);
        if ($clean === '') {
            throw new \InvalidArgumentException(Lang::t('tabs.close_glyph_empty'));
        }
        return $this->mutate(closeGlyph: $clean);
    }

    /**
     * Text {@see view()} renders when the bar has no tabs (in the inactive
     * style, clipped to the width). The empty string, the default, renders
     * nothing. Sanitized like labels.
     */
    public function withPlaceholder(string $text): self
    {
        return $this->mutate(placeholder: $text);
    }

    /**
     * Move tab `$from` to position `$to`; the tabs between shift by one.
     * The tab keeps its badge, and the active tab stays the same tab (its
     * index follows the move). The scroll window follows the active tab.
     *
     * @throws \InvalidArgumentException when either index is not a tab.
     */
    public function moveTab(int $from, int $to): self
    {
        $this->assertTab($from);
        $this->assertTab($to);
        if ($from === $to) {
            return $this;
        }
        $order = range(0, count($this->labels) - 1);
        $moved = array_splice($order, $from, 1);
        array_splice($order, $to, 0, $moved);
        return $this->reorder($order, $this->active);
    }

    /**
     * Remove tab `$index`. Later tabs shift left with their badges. When
     * the active tab closes, its right-hand neighbor becomes active (the
     * left one when it was the last tab); closing another tab keeps the
     * same tab active. Closing the only tab leaves an empty bar.
     *
     * This is the value verb; a close from a click or the close key also
     * returns a {@see TabClosedMsg} `Cmd`.
     *
     * @throws \InvalidArgumentException when `$index` is not a tab.
     */
    public function closeTab(int $index): self
    {
        $this->assertTab($index);
        $order = range(0, count($this->labels) - 1);
        array_splice($order, $index, 1);
        if ($order === []) {
            return $this->mutate(labels: [], badges: [], active: 0, scrollOffset: 0);
        }
        $active = $this->active === $index
            ? $order[min($index, count($order) - 1)]
            : $this->active;
        return $this->reorder($order, $active);
    }

    /**
     * Activate tab `$index`. Out-of-range indices are a programming
     * error, not a style choice: typed throw naming the grammar-free
     * range (the `tabs.bad_index` the vocabulary promised). An empty
     * tab list has no selection to move — `$this` is returned. The
     * constructor carries the same guard; render-time clamping stays
     * as a defensive belt for hand-built instances, not a substitute
     * for setter enforcement.
     *
     * @throws \InvalidArgumentException when count > 0 and the index
     *         is outside 0..count-1.
     */
    public function withActive(int $index): self
    {
        $count = count($this->labels);
        if ($count === 0) {
            return $this;
        }
        if ($index < 0 || $index >= $count) {
            throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
        }
        return $this->mutate(active: $index)->adjustScroll($index);
    }

    public function withActiveStyle(Style $style): self
    {
        return $this->mutate(activeStyle: $style);
    }

    public function withInactiveStyle(Style $style): self
    {
        return $this->mutate(inactiveStyle: $style);
    }

    public function withDivider(string $divider): self
    {
        return $this->mutate(divider: $divider);
    }

    public function withKeyMap(TabsKeyMap $keyMap): self
    {
        return $this->mutate(keyMap: $keyMap);
    }

    public function withWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.neg_width'));
        }
        return $this->mutate(width: $width);
    }

    /**
     * Attach a {@see Manager} for mouse-click zone tracking.
     *
     * When a manager is attached, each tab label is wrapped in a named
     * APC zone (`<zoneKey>tab-0`, `<zoneKey>tab-1`, …). The parent should call
     * `Manager::scan()` on the {@see view()} output to record zone
     * bounds, then route {@see MouseMsg} through
     * `Manager::anyInBoundsAndUpdate($tabs, $mouseMsg)`.
     */
    public function withZoneManager(?Manager $manager): self
    {
        return $this->mutate(zoneManager: $manager, zoneManagerSet: true);
    }

    /**
     * Attach a manager-free {@see Mark} so {@see view()} publishes zone
     * markers without a {@see Manager}: each visible tab is wrapped in
     * `<zoneKey>tab-N` (and `<zoneKey>close-N` around a close affordance),
     * unprefixed. The host scans the frame (a sugar-mouse `Scanner`) and
     * passes the hit zone's id to {@see clickZone()}. A {@see Manager},
     * when also attached, takes precedence. Null detaches.
     */
    public function withMark(?Mark $mark): self
    {
        return $this->mutate(mark: $mark, markSet: true);
    }

    /**
     * Pin this bar's zone key (the per-bar prefix of its tab zone ids).
     *
     * {@see new()} mints a fresh key, so a bar rebuilt from scratch on every
     * frame would emit new ids and lose the clicks scanned from the previous
     * frame. Pass the same key to every rebuild to keep the ids stable.
     *
     * Bars that share a template share a key: `$tpl->withLabels(A)` and
     * `$tpl->withLabels(B)` both carry the template's key, so a click aimed at
     * one bar's tab can activate the same-numbered tab on the other. When bars
     * share a {@see Manager}, give each bar its own `withZoneKey()`. Keys must
     * be non-empty and unique per manager.
     *
     * The key must match the sugar-mouse id charset `[A-Za-z0-9._:-]`; an
     * illegal key is rejected by {@see Manager::mark()} when the bar is rendered.
     *
     * @throws \InvalidArgumentException when $key is empty
     */
    public function withZoneKey(string $key): self
    {
        if ($key === '') {
            throw new \InvalidArgumentException(Lang::t('tabs.zone_key_empty'));
        }
        return $this->mutate(zoneKey: $key);
    }

    /**
     * Set the blank cells drawn on each side of every label (default 1 and
     * 1: ` Home `). `withLabelPadding(0, 0)` draws the bare label, for a
     * host that frames its tabs itself. Only the label is padded: a badge
     * and the close affordance keep their own trailing cell. Spans, tabAt(),
     * clickAt() and the scroll window measure the padded text, so hit-tests
     * stay on the drawn cells for any padding.
     *
     * @throws \InvalidArgumentException when either value is negative.
     */
    public function withLabelPadding(int $left, int $right): self
    {
        if ($left < 0 || $right < 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.neg_padding'));
        }
        return $this->mutate(paddingLeft: $left, paddingRight: $right);
    }

    /**
     * Style the divider text between tabs (and a divider the width clips).
     * Null, the default, draws it in the inactive tab style.
     */
    public function withDividerStyle(?Style $style): self
    {
        return $this->mutate(dividerStyle: $style, dividerStyleSet: true);
    }

    /**
     * Set the marks drawn when tabs are scrolled off each side of a
     * width-bounded bar, and the style they are drawn in. The right mark is
     * also the one an overflowing line ends with once it is clipped. The
     * defaults ({@see SCROLL_MARK} on both sides, no style, a divider after
     * the left mark) are the bar's frame without this call.
     * `$separatorAfterLeft = false` draws the first visible tab right after
     * the left mark, with no divider between them; the right mark keeps its
     * divider. Marks are sanitized like labels; the scroll window, spans()
     * and the hit-tests price them at their drawn width, and the window
     * scrolls to keep the active tab in view under the new widths.
     *
     * @throws \InvalidArgumentException when either mark has no width after sanitizing.
     */
    public function withScrollMarks(
        string $left,
        string $right,
        ?Style $markStyle = null,
        bool $separatorAfterLeft = true,
    ): self {
        $cleanLeft = self::sanitizeLabel($left);
        $cleanRight = self::sanitizeLabel($right);
        if (Width::string($cleanLeft) === 0 || Width::string($cleanRight) === 0) {
            throw new \InvalidArgumentException(Lang::t('tabs.scroll_mark_empty'));
        }
        return $this->mutate(
            leftMark: $cleanLeft,
            rightMark: $cleanRight,
            markStyle: $markStyle,
            markStyleSet: true,
            separatorAfterLeft: $separatorAfterLeft,
        )->adjustScroll($this->active);
    }

    /**
     * Pick how the scroll window follows the active tab:
     * {@see ScrollWindow::Offset} (the default) keeps the stored offset and
     * slides it by the least that reveals the active tab;
     * {@see ScrollWindow::FirstKeepingActive} scans from the first tab and
     * shows the first run of tabs that keeps the active tab in view, and
     * ignores {@see withScrollOffset()}.
     */
    public function withScrollWindow(ScrollWindow $mode): self
    {
        return $this->mutate(scrollWindow: $mode);
    }

    /**
     * Manually set the scroll offset (first visible tab index), clamped to
     * the tab list. Auto-scroll that keeps the active tab visible is driven
     * internally by {@see update()} — this setter pins an explicit offset.
     * Under {@see ScrollWindow::FirstKeepingActive} the active tab alone
     * decides the window, so the pinned offset is not used.
     */
    public function withScrollOffset(int $offset): self
    {
        return $this->mutate(scrollOffset: max(0, min($offset, count($this->labels) - 1)));
    }

    /**
     * Disable wrap-around at the ends of the tab list.
     * With wrap disabled, `Tab` clamps at the last tab and
     * `Shift+Tab` clamps at the first tab.
     */
    public function noWrap(): self
    {
        return $this->mutate(wrap: false);
    }

    /** Take focus; companion to {@see blur()} — a pure value verb. */
    public function focus(): self
    {
        return $this->mutate(focused: true);
    }

    public function blur(): self
    {
        return $this->mutate(focused: false);
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Parse a zone id minted by THIS bar and return its tab index, or null.
     *
     * Ids are minted as "[manager prefix][zoneKey]<kind>N" (`tab-` or
     * `close-`); a Mark bar has no manager prefix. The bar only accepts an
     * id that carries its own prefix and zone key, so a click aimed at a
     * sibling bar on the same manager (or at a bar on a different manager)
     * never lands here. The match is exact on the prefix and key, so a
     * manager prefix that itself contains "tab-<digits>" cannot mis-route
     * the click (N13).
     */
    private function tabIndexFromZoneId(string $id, string $kind): ?int
    {
        if (!$this->marksZones()) {
            return null;
        }
        $expected = ($this->zoneManager?->prefix() ?? '') . $this->zoneKey . $kind;
        if (!str_starts_with($id, $expected)) {
            return null;
        }
        $suffix = substr($id, strlen($expected));
        if ($suffix === '' || !ctype_digit($suffix)) {
            return null;
        }
        $idx = (int) $suffix;
        return $idx >= 0 && $idx < count($this->labels) ? $idx : null;
    }

    /**
     * Whether tab `$i` is inside the window view() draws. The window start
     * is the one windowParts() uses: an unbounded bar (width 0) draws every
     * tab whatever the stored scrollOffset, so zones on it must all answer.
     */
    private function isVisible(int $i): bool
    {
        $start = $this->width > 0 ? min($this->scrollOffset, count($this->labels) - 1) : 0;
        return $i >= $start && $i <= $this->scrollEnd;
    }

    /** @throws \InvalidArgumentException when `$index` is not a tab. */
    private function assertTab(int $index): void
    {
        if ($index < 0 || $index >= count($this->labels)) {
            throw new \InvalidArgumentException(Lang::t('tabs.bad_index'));
        }
    }

    /**
     * Close tab `$index` and pair the result with the {@see TabClosedMsg}
     * Cmd describing the tab as it was.
     *
     * @return array{0:Tabs, 1:\Closure}
     */
    private function closeWithMsg(int $index): array
    {
        $label = $this->labels[$index];
        return [
            $this->closeTab($index),
            Cmd::send(TabClosedMsg::new($index, $label, $this->zoneKey)),
        ];
    }

    /**
     * Rebuild the bar from `$order` (new position => old index): labels and
     * badges follow their tab, `$activeOld` (an old index) stays active,
     * and the scroll window follows it.
     *
     * @param list<int> $order
     */
    private function reorder(array $order, int $activeOld): self
    {
        $labels = [];
        $badges = [];
        foreach ($order as $new => $old) {
            $labels[] = $this->labels[$old];
            if (isset($this->badges[$old])) {
                $badges[$new] = $this->badges[$old];
            }
        }
        $active = (int) array_search($activeOld, $order, true);
        return $this->mutate(
            labels: $labels,
            badges: $badges,
            active: $active,
            scrollOffset: min($this->scrollOffset, count($labels) - 1),
        )->adjustScroll($active);
    }

    /**
     * Auto-scroll the scroll offset to keep the given tab index visible.
     */
    private function adjustScroll(int $tabIndex): self
    {
        // FirstKeepingActive derives the offset in the constructor from the
        // active tab, so every rebuild already shows the right window.
        if ($this->width <= 0 || $this->scrollWindow === ScrollWindow::FirstKeepingActive) {
            return $this;
        }
        $count = count($this->labels);
        if ($count === 0) {
            return $this;
        }

        // If tab is before visible window, scroll back (left-align the jump).
        if ($tabIndex < $this->scrollOffset) {
            return $this->withScrollOffset($tabIndex);
        }
        // If tab is after visible window, scroll FORWARD by the smallest
        // amount that reveals it: the window slides slot-by-slot instead
        // of page-jumping, so consecutive forward presses advance the
        // offset one cell at a time (W-18). Monotone: a forward press
        // never scrolls the view backwards.
        if ($tabIndex > $this->scrollEnd) {
            $offset = max($this->scrollOffset + 1, min($tabIndex, $count - 1));
            while ($offset > $this->scrollOffset + 1 && $this->endForOffset($offset - 1) >= $tabIndex) {
                $offset--;
            }
            return $this->withScrollOffset($offset);
        }
        return $this;
    }

    /** Visible-window end for a hypothetical scroll offset (W-18 probe). */
    private function endForOffset(int $offset): int
    {
        return $this->withScrollOffset($offset)->scrollEnd;
    }

    /**
     * Compute the last tab index the bar can render FULLY for the current
     * scrollOffset and width — the single window truth shared by
     * {@see view()} and {@see adjustScroll()} (E736/bits-2.4).
     *
     * The cursor walks to the cell where each candidate label ENDS, and a
     * tab only enters the window if it survives rendering: the window's
     * last tab needs to fit within the bar (`labelEnd <= width`), while
     * any other candidate also owes the right mark for the tabs it hides —
     * when the line runs wide view()'s guard keeps only clipBudget()
     * cells (width minus the right mark's display width) before
     * appending that mark, so a mid-list tail tab renders whole only
     * while `labelEnd <= clipBudget()`. Pricing the left mark is part of
     * the same honesty: view() joins it with a divider, so it already
     * occupies leftLeadWidth() cells before the first label starts.
     * Under-counting any of this (too few cells for a wide right mark,
     * nothing for the left)
     * let the stored window claim a tab view() then clipped away — the
     * bold active tab could silently vanish (r87 review MAJOR). The
     * first tab after the offset is included even if it alone overflows
     * — an empty bar is worse than a clipped one. Widths ride the same
     * control-char sanitization view() renders, so the stored window and
     * the drawn window cannot drift.
     */
    private function computeScrollEnd(int $offset): int
    {
        $count = count($this->labels);
        if ($count === 0 || $this->width <= 0) {
            return $count - 1;
        }

        $start = min($offset, $count - 1);
        $dividerWidth = Width::string($this->divider);
        // The left mark is never standalone: view() joins it to the first
        // tab (with a divider unless the separator is off), so it occupies
        // cells before the first label starts.
        $cursor = $this->rendersLeftEllipsis($offset) ? $this->leftLeadWidth() : 0;

        for ($i = $start; $i < $count; $i++) {
            $labelEnd = $cursor + $this->tabWidth($i);
            // A right mark follows every tab except the last; the clip
            // guard preserves only width minus the mark's cells once that
            // line overflows.
            $bound = $i < $count - 1 ? $this->clipBudget() : $this->width;
            if ($labelEnd > $bound && $i > $start) {
                return $i - 1;
            }
            // The next tab joins after this label with a divider.
            $cursor = $labelEnd + $dividerWidth;
        }
        return $count - 1;
    }

    /**
     * Whether view() will prepend the left mark for the current offset.
     *
     * The left mark and its divider (leftLeadWidth()) must leave at least
     * one cell of the first window tab before the clip guard's
     * clipBudget() bound (width minus the right mark's cells): at a narrower
     * width they would eat the whole bar and the active tab would vanish
     * from the frame and from spans(), so the marker is dropped instead.
     */
    private function rendersLeftEllipsis(?int $offset = null): bool
    {
        $offset ??= $this->scrollOffset;
        return $this->width > 0
            && $offset > 0
            && min($offset, count($this->labels) - 1) > 0
            && $this->leftLeadWidth() < $this->clipBudget();
    }

    /** Cells the left mark and its separator take before the first visible tab. */
    private function leftLeadWidth(): int
    {
        return Width::string($this->leftMark)
            + ($this->separatorAfterLeft ? Width::string($this->divider) : 0);
    }

    /**
     * The {@see ScrollWindow::FirstKeepingActive} offset: the smallest one
     * whose window draws the active tab. The active tab's own offset always
     * qualifies, because a window keeps its first tab even when it alone
     * overflows.
     */
    private function firstOffsetKeepingActive(): int
    {
        if ($this->labels === [] || $this->width <= 0) {
            return 0;
        }
        for ($offset = 0; $offset < $this->active; $offset++) {
            if ($this->computeScrollEnd($offset) >= $this->active) {
                return $offset;
            }
        }
        return $this->active;
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

    /** Rendered cell width of one tab (label, badge, close affordance) — zone markup is invisible. */
    private function tabWidth(int $i): int
    {
        $cells = 0;
        foreach ($this->tabTexts($i) as $text) {
            $cells += $text === null ? 0 : Width::string($text);
        }
        return $cells;
    }

    /**
     * The single construction seam behind every `with*()` setter — rebuilds
     * the Tabs via the constructor, the same pattern as Table::mutate().
     */
    private function mutate(
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
        ?string $zoneKey = null,
        ?array $labels = null,
        ?int $scrollOffset = null,
        ?array $badges = null,
        ?bool $closable = null,
        ?string $closeGlyph = null,
        ?Style $badgeStyle = null,
        bool $badgeStyleSet = false,
        ?Mark $mark = null,
        bool $markSet = false,
        ?string $placeholder = null,
        ?int $paddingLeft = null,
        ?int $paddingRight = null,
        ?Style $dividerStyle = null,
        bool $dividerStyleSet = false,
        ?string $leftMark = null,
        ?string $rightMark = null,
        ?Style $markStyle = null,
        bool $markStyleSet = false,
        ?bool $separatorAfterLeft = null,
        ?ScrollWindow $scrollWindow = null,
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
            zoneKey: $zoneKey ?? $this->zoneKey,
            labels: $labels ?? $this->labels,
            scrollOffset: $scrollOffset ?? $this->scrollOffset,
            badges: $badges ?? $this->badges,
            closable: $closable ?? $this->closable,
            closeGlyph: $closeGlyph ?? $this->closeGlyph,
            badgeStyle: $badgeStyleSet ? $badgeStyle : $this->badgeStyle,
            mark: $markSet ? $mark : $this->mark,
            placeholder: $placeholder ?? $this->placeholder,
            paddingLeft: $paddingLeft ?? $this->paddingLeft,
            paddingRight: $paddingRight ?? $this->paddingRight,
            dividerStyle: $dividerStyleSet ? $dividerStyle : $this->dividerStyle,
            leftMark: $leftMark ?? $this->leftMark,
            rightMark: $rightMark ?? $this->rightMark,
            markStyle: $markStyleSet ? $markStyle : $this->markStyle,
            separatorAfterLeft: $separatorAfterLeft ?? $this->separatorAfterLeft,
            scrollWindow: $scrollWindow ?? $this->scrollWindow,
        );
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}
