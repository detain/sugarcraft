<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseAction;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\LoadMoreMsg;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Crush\Theme;

/**
 * Keyboard-navigable session picker overlay for the SugarCrush TUI.
 *
 * Supports:
 * - Arrow keys (up/k, down/j) to browse sessions
 * - Enter to resume a session
 * - Space to preview a session without committing
 * - Escape to close the picker
 * - Filter that narrows the list to sessions tied to the current git branch
 * - E744 WS4/WS5: click-to-select on its painted rows (zones owned by the
 *   picker outrank the frame behind it) and a LoadMore edge that widens the
 *   store fetch once browsing exhausts the rows already in hand.
 *
 * Mirrors charmbracelet/crush session picker behavior.
 *
 * E744 WS5 adopted {@see ItemList} as the SELECTION MODEL — cursor position,
 * end-of-list clamping, the mouse row hit-map and the load-more arrival edge
 * all belong to the widget now, raised through `Cmd::send(new LoadMoreMsg())`
 * exactly as any other host would receive them. The PIXELS deliberately stayed
 * here: this overlay paints its own header/border/footer chrome, byte-pinned
 * by the hosted-frame tests, and its scroll window is a center-on-cursor rule
 * rather than the widget's pan window, so `ItemList::view()` is never called.
 * The list is constructed `focused`, with its description/filter/status/help
 * surfaces off and a height that covers every loaded row, which keeps its
 * internal pan offset at zero — the one configuration under which a synthesized
 * click line equals an absolute row (see {@see updateClick()}). The widget's
 * `/` filter key is never forwarded: {@see handleKey()} routes only up/down/j/k
 * navigations into the model, so the list's filtering mode is unreachable.
 *
 * One behavioral divergence rode the adoption: browsing CLAMPS at the ends of
 * the list where the hand-rolled picker WRAPPED. Wrapping past the last row
 * would fight the load-more edge — arriving at the last row while more exist
 * upstream is the signal to fetch, not the signal to jump to row zero.
 */
final class SessionPicker
{
    /** @var list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> */
    private readonly array $sessions;

    /** Current branch filter - only show sessions tied to this branch, or null for no filter. */
    private readonly string|null $branchFilter;

    /**
     * Store fetch limit that produced {@see $sessions}: the initial page for a
     * freshly opened picker and, widened by the same step, the next page a
     * {@see LoadMoreMsg} asks for. A fetch that FILLS its limit leaves
     * {@see $moreInStore} true; a short fetch closes the edge.
     */
    public const PAGE_SIZE = 20;

    /** The selection/scroll/clamp/mouse/load-edge model (E744 WS5). */
    private readonly ItemList $list;

    /** Store limit the CURRENT {@see $sessions} came back from. */
    private readonly int $fetchLimit;

    /** Whether the last store fetch filled its limit (another page may exist). */
    private readonly bool $moreInStore;

    /**
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    private function __construct(
        array $sessions,
        ItemList $list,
        string|null $branchFilter,
        int $fetchLimit,
        bool $moreInStore,
    ) {
        $this->sessions = $sessions;
        $this->list = $list;
        $this->branchFilter = $branchFilter;
        $this->fetchLimit = $fetchLimit;
        $this->moreInStore = $moreInStore;
    }

    /**
     * Create a new SessionPicker with sessions loaded.
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    public static function new(
        array $sessions,
        int $fetchLimit = self::PAGE_SIZE,
        bool $moreInStore = false,
    ): self {
        return new self(
            $sessions,
            self::modelFor(self::filterRows($sessions, null), 0, $moreInStore),
            null,
            $fetchLimit,
            $moreInStore,
        );
    }

    /**
     * Return the filtered sessions based on branch filter.
     *
     * @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}>
     */
    public function filteredSessions(): array
    {
        return self::filterRows($this->sessions, $this->branchFilter);
    }

    /**
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     *
     * @return list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}>
     */
    private static function filterRows(array $sessions, string|null $branchFilter): array
    {
        if ($branchFilter === null) {
            return $sessions;
        }

        return array_values(array_filter(
            $sessions,
            static fn(array $s): bool => ($s['gitBranch'] ?? '') !== ''
                && $s['gitBranch'] === $branchFilter,
        ));
    }

    /**
     * Build the ItemList model over one row set: focused, surfaces off, one
     * screen tall (so its pan offset never moves and a click line is an
     * absolute row), with the load-more edge armed exactly when more rows may
     * exist upstream.
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $rows
     */
    private static function modelFor(array $rows, int $cursor, bool $moreInStore): ItemList
    {
        $items = array_map(static fn(array $row): SessionRow => SessionRow::fromSession($row), $rows);
        $list = ItemList::new($items, 60, max(1, count($items)))
            ->withShowDescription(false)
            ->withShowFilter(false)
            ->withShowStatusBar(false)
            ->withShowHelp(false)
            ->withHasMore($moreInStore);
        [$focused] = $list->focus();
        \assert($focused instanceof ItemList);

        // select() is moveCursor-with-no-edge: programmatic positioning must
        // not fire the arrival signal that only KEYBOARD/MOUSE navigation
        // raises (loadMoreEdge documents the same split in the widget).
        return $focused->select($cursor);
    }

    /** Swap in a rebuilt model, keeping everything else. */
    private function withList(ItemList $list): self
    {
        return new self($this->sessions, $list, $this->branchFilter, $this->fetchLimit, $this->moreInStore);
    }

    /**
     * Return the currently selected session data, or null if none selected.
     *
     * @return array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}|null
     */
    public function selectedSession(): array|null
    {
        $filtered = $this->filteredSessions();

        if ($this->selectedIndex() < 0 || $this->selectedIndex() >= count($filtered)) {
            return null;
        }

        return $filtered[$this->selectedIndex()];
    }

    /**
     * Return the selected index — the ItemList cursor (E744 WS5: the widget is
     * the selection authority; this class no longer stores a parallel index).
     */
    public function selectedIndex(): int
    {
        return $this->list->index();
    }

    /**
     * Return the current branch filter.
     */
    public function branchFilter(): string|null
    {
        return $this->branchFilter;
    }

    /**
     * Create a new SessionPicker with an updated selection index.
     */
    public function withSelectedIndex(int $index): self
    {
        // select() clamps against the model's own row count, which is the
        // filtered set — the same 0..count-1 law the hand-rolled picker had.
        return $this->withList($this->list->select($index));
    }

    /**
     * Create a new SessionPicker with an updated branch filter.
     */
    public function withBranchFilter(string|null $branch): self
    {
        // When filter changes, reset selection to first item
        return new self(
            $this->sessions,
            self::modelFor(self::filterRows($this->sessions, $branch), 0, $this->moreInStore),
            $branch,
            $this->fetchLimit,
            $this->moreInStore,
        );
    }

    /**
     * Create a new SessionPicker with sessions replaced (e.g., reloaded from store).
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    public function withSessions(array $sessions): self
    {
        return new self(
            $sessions,
            self::modelFor(self::filterRows($sessions, $this->branchFilter), 0, $this->moreInStore),
            $this->branchFilter,
            $this->fetchLimit,
            $this->moreInStore,
        );
    }

    /**
     * Widened the row set after a store re-fetch (E744 WS5, the LoadMore sink).
     *
     * Unlike {@see withSessions()} this KEEPS the cursor: the user is standing
     * on the last row of the previous page, and the point of growing the page
     * under them is to keep walking. The clamp that preserves comes from
     * `ItemList::select()`, which also cannot fire the edge — growth itself is
     * never a navigation.
     *
     * @param list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}> $sessions
     */
    public function withFetchedRows(array $sessions, int $fetchLimit, bool $moreInStore): self
    {
        return new self(
            $sessions,
            self::modelFor(self::filterRows($sessions, $this->branchFilter), $this->selectedIndex(), $moreInStore),
            $this->branchFilter,
            $fetchLimit,
            $moreInStore,
        );
    }

    /** Does the last store fetch fill its limit, so more rows may exist? */
    public function needsStoreFetch(): bool
    {
        return $this->moreInStore;
    }

    /** Store limit the CURRENT page came back from. */
    public function fetchLimit(): int
    {
        return $this->fetchLimit;
    }

    /** The widened limit the next {@see LoadMoreMsg} page should ask for. */
    public function nextFetchLimit(): int
    {
        return $this->fetchLimit + self::PAGE_SIZE;
    }

    /**
     * The picker's overlay box for a terminal of $cols x $rows.
     *
     * Single source for BOTH the painting pass ({@see Renderer::renderSessionPicker()})
     * and the click-zone validation pass ({@see \SugarCraft\Crush\Chat}'s
     * picker arm) — the geometry a click is judged against must be the
     * geometry the rows were painted at.
     *
     * @return array{0: int, 1: int} [width, height]
     */
    public static function overlayGeometry(int $cols, int $rows, int $shellChromeCols): array
    {
        $inner = max(20, $cols - $shellChromeCols);

        return [max(20, min($inner - 4, 76)), max(8, $rows - 4)];
    }

    /**
     * The body lines the CURRENT paint shows, keyed by absolute filtered row,
     * for the click-zone registry (E744 WS4).
     *
     * Pure and derived from exactly the same {@see window()} the overlay is
     * painted from — there is no cached copy to drift. Rows outside the
     * painted window simply have no line to mark, so they are not clickable
     * (the arrow keys still reach them), and the `(no sessions)` sentinel is
     * never keyed, because it is not a row.
     *
     * @return array<int, string>
     */
    public function rowZoneLines(int $width, int $height, Theme $theme): array
    {
        [$start, $visible] = $this->window($height);

        $lines = [];
        foreach ($visible as $i => $session) {
            $lines[$start + $i] = $this->renderSessionLine($session, $start + $i === $this->selectedIndex(), $width, $theme);
        }

        return $lines;
    }

    /**
     * The painted window: [first filtered index, sessions shown] for a box of
     * $height rows — the one layout rule shared by render() and the zone map.
     *
     * @return array{0: int, 1: list<array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}>}
     */
    private function window(int $height): array
    {
        $filtered = $this->filteredSessions();
        // headerHeight is the 3 header lines render() emits (title/separator/
        // controls); 4 more come off for border + padding, exactly as before.
        $availableHeight = $height - 3 - 4;
        $start = $this->calculateScrollOffset(count($filtered), $availableHeight);

        return [$start, array_slice($filtered, $start, max(0, $availableHeight))];
    }

    /**
     * Render the session picker overlay.
     */
    public function render(int $width, int $height, Theme $theme): string
    {
        $filtered = $this->filteredSessions();
        $selectedSession = $this->selectedSession();

        // Build header
        $header = $this->renderHeader($width, $theme);

        [$startIndex, $visibleSessions] = $this->window($height);

        // Build session list
        $lines = [];
        foreach ($visibleSessions as $i => $session) {
            $actualIndex = $startIndex + $i;
            $isSelected = $actualIndex === $this->selectedIndex();
            $lines[] = $this->renderSessionLine($session, $isSelected, $width, $theme);
        }

        if ($lines === []) {
            $lines[] = Style::new()
                ->foreground($theme->shellMuted)
                ->render('  (no sessions)');
        }

        $body = implode("\n", $lines);

        // Wrap in border
        $st = Style::new()
            ->border(Border::rounded()->withTitle(' sessions '))
            ->borderForeground($theme->shellPrimary)
            ->padding(0, 1)
            ->width($width);

        // If no sessions match filter, show different styling
        if ($filtered === [] && $this->branchFilter !== null) {
            $st = $st->borderForeground($theme->shellWarning);
        }

        return $header . "\n" . $st->render($body) . $this->renderFooter($width, $selectedSession, $theme);
    }

    /**
     * Render the header with title and branch filter indicator.
     */
    private function renderHeader(int $width, Theme $theme): string
    {
        $title = Style::new()
            ->foreground($theme->shellPrimary)
            ->bold()
            ->render(' session picker ');

        $filterText = '';
        if ($this->branchFilter !== null) {
            $filterText = '  ' . Style::new()
                ->foreground($theme->shellWarning)
                ->render('branch:') . ' ' . Style::new()
                ->foreground($theme->shellPrimary)
                ->render($this->branchFilter);
        }

        $controls = Style::new()
            ->foreground($theme->shellMuted)
            ->render(' ↑↓ browse  ');
        $controls .= Style::new()
            ->foreground($theme->shellMuted)
            ->render('↵ resume  ');
        $controls .= Style::new()
            ->foreground($theme->shellMuted)
            ->render('space preview  ');
        $controls .= Style::new()
            ->foreground($theme->shellMuted)
            ->render('esc close');

        // Themed, like its twin in renderFooter(): an unstyled run is the
        // terminal's own default foreground, which is the one colour this shell
        // is not entitled to choose for a rule it drew itself.
        $separator = Style::new()
            ->foreground($theme->border)
            ->render(str_repeat('─', max(0, $width - 2)));

        return $title . $filterText . "\n" . $separator . "\n" . $controls;
    }

    /**
     * Render a single session line.
     *
     * @param array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string} $session
     */
    private function renderSessionLine(array $session, bool $isSelected, int $width, Theme $theme): string
    {
        $indicator = $isSelected ? '▶' : ' ';
        $nameStyle = $isSelected ? $theme->shellPrimary : $theme->shellForeground;
        $metaStyle = $theme->shellMuted;

        // Truncate name if needed
        $maxNameLen = 20;
        $name = $session['sessionName'];
        if (strlen($name) > $maxNameLen) {
            $name = substr($name, 0, $maxNameLen - 1) . '…';
        }

        // Show summary preview (first line, truncated)
        $summary = $session['summary'] ?? '';
        $maxSummaryLen = $width - 40; // Account for indicator, name, branch, padding
        if (strlen($summary) > $maxSummaryLen && $maxSummaryLen > 0) {
            $summary = substr($summary, 0, $maxSummaryLen - 2) . '…';
        }

        $branch = $session['gitBranch'] ?? null;
        $branchStr = $branch !== null
            ? Style::new()->foreground($theme->shellMuted)->render(' @' . $branch)
            : '';

        $displayLine = sprintf(
            '%s %s%s %s',
            $indicator,
            Style::new()->foreground($nameStyle)->render($name),
            $branchStr,
            Style::new()->foreground($metaStyle)->render($summary ? '· ' . $summary : ''),
        );

        return $displayLine;
    }

    /**
     * Render the footer with selected session details.
     *
     * @param array{sessionId: string, sessionName: string, summary: string, gitBranch: string|null, lastActivity: string}|null $session
     */
    private function renderFooter(int $width, array|null $session, Theme $theme): string
    {
        if ($session === null) {
            return '';
        }

        $summary = $session['summary'] ?? '(no summary)';
        $maxWidth = max(1, $width - 10);

        if (strlen($summary) > $maxWidth) {
            $summary = substr($summary, 0, $maxWidth - 3) . '…';
        }

        $footer = "\n" . Style::new()
            ->foreground($theme->border)
            ->render('─'.str_repeat('─', $width - 2));
        $footer .= "\n" . Style::new()
            ->foreground($theme->shellForeground)
            ->render('  ' . $summary);

        return $footer;
    }

    /**
     * Calculate scroll offset to keep selected item visible.
     */
    private function calculateScrollOffset(int $totalItems, int $visibleHeight): int
    {
        if ($totalItems <= $visibleHeight) {
            return 0;
        }

        $maxOffset = $totalItems - $visibleHeight;

        // If selected is not within visible range, scroll to center it
        if ($this->selectedIndex() < 0 || $this->selectedIndex() >= $visibleHeight) {
            $idealOffset = $this->selectedIndex() - (int) floor($visibleHeight / 2);
            return max(0, min($maxOffset, $idealOffset));
        }

        return 0;
    }

    /**
     * Handle a keypress and return the action taken.
     *
     * E744 WS5 widened the tuple with a third slot: the Cmd an {@see ItemList}
     * navigation may raise (today only the arrival-on-last-row `Cmd::send(new
     * LoadMoreMsg())` edge). Call sites that destructure two slots are
     * unaffected — the actions that never navigate the model carry null there.
     *
     * @return array{0: self, 1: string|null, 2: ?\Closure} [newPicker, action, cmd]
     *   action is 'browse' (arrow moved selection), 'resume' (enter pressed), 'preview' (space pressed), 'close' (escape pressed), or null
     */
    public function handleKey(string $key): array
    {
        return match ($key) {
            'up', 'k' => $this->browsing(new KeyMsg(KeyType::Up)),
            'down', 'j' => $this->browsing(new KeyMsg(KeyType::Down)),
            'enter' => [$this, $this->selectedSession() !== null ? 'resume' : null, null],
            ' ' => [$this, $this->selectedSession() !== null ? 'preview' : null, null],
            'escape' => [$this, 'close', null],
            'ctrl+b' => [$this->withBranchFilter($this->branchFilter === null ? $this->getCurrentGitBranch() : null), 'browse', null],
            default => [$this, null, null],
        };
    }

    /**
     * Forward one navigation into the ItemList model and wrap its answer as a
     * browse action. Only up/down/j/k reach here — see the class docblock for
     * why no other key is ever forwarded.
     *
     * @return array{0: self, 1: string, 2: ?\Closure}
     */
    private function browsing(KeyMsg $key): array
    {
        [$next, $cmd] = $this->list->update($key);
        \assert($next instanceof ItemList);

        return [$this->withList($next), 'browse', $cmd];
    }

    /**
     * Select the row painted at filtered index $row by a pointer click
     * (E744 WS4) — forwarded as a list-relative one-based screen line into
     * `ItemList::handleMouse()`'s hit-map, which is exact here because the
     * model's pan offset never leaves zero at full-window height.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function updateClick(int $row): array
    {
        if ($row < 0 || $row >= count($this->filteredSessions())) {
            return [$this, null];
        }

        [$next, $cmd] = $this->list->update(new MouseClickMsg(1, $row + 1, MouseButton::Left, MouseAction::Press));
        \assert($next instanceof ItemList);

        return [$this->withList($next), $cmd];
    }

    /**
     * Wheel the selection by one row (E744 WS4) — the widget's own wheel arm
     * moves the cursor and funnels the result through the same load-more edge
     * the keyboard uses.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function updateWheel(MouseButton $direction): array
    {
        [$next, $cmd] = $this->list->update(new MouseWheelMsg(1, 1, $direction, MouseAction::Press));
        \assert($next instanceof ItemList);

        return [$this->withList($next), $cmd];
    }

    /**
     * Get the current git branch, or null if not in a git repo.
     */
    private function getCurrentGitBranch(): string|null
    {
        // Guard: must be in a git repo
        $revParse = @exec('git rev-parse --is-inside-work-tree 2>/dev/null');
        if ($revParse !== 'true') {
            return null;
        }

        $branch = @exec('git branch --show-current 2>/dev/null');
        if ($branch === false || $branch === '') {
            return null;
        }

        return trim($branch);
    }

    /**
     * Check if the session picker has any sessions to show.
     */
    public function isEmpty(): bool
    {
        return $this->filteredSessions() === [];
    }

    /**
     * Return the count of filtered sessions.
     */
    public function count(): int
    {
        return count($this->filteredSessions());
    }
}
