<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\Connections;

use SugarCraft\Sql\Lang;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Util\Color;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Sql\Admin\AsyncCachingServerContext;
use SugarCraft\Sql\Admin\PageBase;
use SugarCraft\Sql\Admin\RowNavigable;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\StatusSnapshot;
use SugarCraft\Sql\CellValue;
use SugarCraft\Sql\Core\Msg\ReloadReportMsg;
use SugarCraft\Style\Style;
use SugarCraft\Table\{Column, Row, RowData, Table};
use SugarCraft\Widgets\Tabs\Tabs;

/**
 * Connections page component integrating processlist, counters, filters, and actions.
 *
 * Uses sugar-table Table for the processlist grid with columns for
 * Id, User, Host, DB, Command, Time, State, Info. The detail tabs for the
 * selected thread (Details / Attributes / MDL) are a sugar-widgets
 * {@see Tabs} bar built with {@see Tabs::interactive()}, so it publishes
 * Mark zones for mouse clicks.
 *
 * The panel under the bar shows the active tab's data for the selected
 * thread ({@see ConnectionDetailTabs}). Each read is a synchronous server
 * round trip (bounded by the StatementTimeout guard, the SQL-C11 action-path
 * trade-off), so the panel reads only on a one-shot explicit action: Enter
 * (again on a focused bar to re-read), a tab switch by key or click, or
 * {@see withDetailTab()}. It never reads from view(), from the repeatable
 * navigation keys (j/k/Up/Down, f, r) or from a data reload (every admin
 * poll tick): when one of those puts a different thread under the
 * selection, the panel shows a no-read "press Enter to load" line for that
 * thread instead, so a stalled server never freezes a held key or the poll.
 * Esc closes the panel.
 *
 * The table paints a window of at most {@see ROW_WINDOW} rows (fewer when
 * the panel takes height) that always contains the selection, so the
 * highlighted row and the thread the panel describes stay on screen.
 *
 * Detail-tab keys (AUD §2 #19). In the app, Tab cycles the focus pane and
 * the digits pick admin pages before any page sees them, so the old
 * page-level Tab / 1 / 2 / 3 arms could never fire. The bar now follows the
 * Tabs focus law: Enter focuses it, and while it is focused the page claims
 * Tab, Shift+Tab and 1-3 through {@see claimsKey()}, which App consults
 * ahead of its own pane and page chords. Esc hands the keyboard back. A
 * blurred bar claims nothing, so every global key keeps its meaning.
 *
 * @see Mirrors jorgerojas26/lazysql connections page
 */
final class ConnectionsPage extends PageBase implements RowNavigable
{
    public const DETAIL_TAB_DETAILS = 'details';
    public const DETAIL_TAB_ATTRIBUTES = 'attributes';
    public const DETAIL_TAB_MDL = 'mdl';

    /** @var list<string> */
    private const DETAIL_TAB_ORDER = [
        self::DETAIL_TAB_DETAILS,
        self::DETAIL_TAB_ATTRIBUTES,
        self::DETAIL_TAB_MDL,
    ];

    private ?ProcesslistResult $selectedThread = null;
    private ?ConnectionFilters $filters = null;
    private ?ProcesslistProvider $processlistProvider = null;
    private ?ConnectionCounters $counters = null;
    private ?ConnectionActions $actions = null;
    private ?ConnectionDetailTabs $detailTabs = null;

    private int $selectedIndex = 0;

    /**
     * SQL-P09: rows built per frame are capped to this window (house
     * PAGE_SIZE parity with DebugPage / VariablesPage::GRID_WINDOW).
     */
    private const ROW_WINDOW = 50;

    /**
     * The detail-tab bar: its active index is the active detail tab (in
     * {@see DETAIL_TAB_ORDER}), its focus flag says whether the bar owns
     * the tab keys. One instance per page, so its zone key stays stable
     * across clones and a scanned click still names this bar.
     */
    private Tabs $detailBar;

    /**
     * True only for the internal pass that renders the frame with zone
     * markers for hit-testing; the frame App paints never carries them.
     */
    private bool $publishZones = false;

    /**
     * Most lines the detail panel shows; the rest collapse into one ellipsis
     * line. Above the Details tab's ten columns (id through thread_name), so
     * info, the running SQL text, is never the line that collapses.
     */
    private const DETAIL_MAX_LINES = 12;

    /**
     * Fewest panel lines a height-budgeted page shows when the table leaves
     * less room than that: the table's row window shrinks to make them.
     */
    private const DETAIL_MIN_LINES = 4;

    /** Most rows the page may paint, or null for no limit ({@see withHeightBudget()}). */
    private ?int $heightBudget = null;

    /**
     * The open detail panel: the active tab's data for the selected thread
     * as plain, sanitized lines, or null while the panel is closed.
     *
     * @var list<string>|null
     */
    private ?array $detailLines = null;

    /** The thread the open panel describes (null: no thread was selected). */
    private int|string|null $detailThreadId = null;

    /** Connection awaiting a kill confirmation (null = no pending kill). */
    private ?ProcesslistResult $pendingKill = null;
    /** True when the pending action is KILL QUERY rather than KILL CONNECTION. */
    private bool $pendingKillIsQuery = false;

    /** The kill confirmation is armed — it owns every keystroke. */
    public function isModal(): bool
    {
        return $this->pendingKill !== null;
    }
    /** Transient feedback line shown after an action (kill sent/failed/cancelled). */
    private ?string $actionMessage = null;

    /** @var list<ProcesslistResult>|null Memoized filtered processlist to avoid 2-3× fetch per render */
    private ?array $cachedFilteredProcesslist = null;

    /**
     * The filter state the memo was computed under (adminpages-009). Compared
     * by identity, never by value: an immutable with*() change mints a new
     * ConnectionFilters object, and that new object IS the invalidation.
     */
    private ?ConnectionFilters $memoFilters = null;

    /** The provider object identity the memo was computed against (adminpages-009). */
    private ?int $memoProviderId = null;

    public function __construct(
        ServerContextInterface $context,
        private readonly ?int $maxConnections = 151,
    ) {
        parent::__construct($context);
        $this->filters = ConnectionFilters::new();
        $this->detailBar = Tabs::interactive(
            [Lang::t('conn.tab_details'), Lang::t('conn.tab_attributes'), Lang::t('conn.tab_mdl')],
            Mark::new(),
        )
            ->withDivider('│')
            ->withActiveStyle(Style::new()->bold()->foreground(Color::hex('#fde047')))
            ->withInactiveStyle(Style::new()->foreground(Color::hex('#6b7280')));
    }

    /**
     * Create a new connections page with standard dependencies.
     */
    public static function new(
        ServerContextInterface $context,
        ?int $maxConnections = 151,
    ): self {
        $instance = new self($context, $maxConnections);
        $instance->processlistProvider = ProcesslistProvider::new($context);
        $instance->actions = ConnectionActions::new($context);
        $instance->detailTabs = ConnectionDetailTabs::new($context);
        $instance->counters = ConnectionCounters::fromSnapshot(
            new StatusSnapshot($context->statusVariables(), $context->statusVariablesTs()),
            $maxConnections ?? 151
        );
        return $instance;
    }

    /**
     * Get the processlist table with current filters applied.
     *
     * @return Table
     */
    public function table(): Table
    {
        return $this->buildTable($this->filteredProcesslist());
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::table()} instead. REMOVED-IN: 2.0.0.
     */
    public function getTable(): Table { return $this->table(); }

    /**
     * Get connection counters.
     */
    public function counters(): ConnectionCounters
    {
        return $this->counters;
    }

    /**
     * Get current filters.
     */
    public function filters(): ConnectionFilters
    {
        return $this->filters;
    }

    /**
     * Return a new instance with updated filters.
     */
    public function withFilters(ConnectionFilters $filters): self
    {
        $clone = clone $this;
        $clone->cachedFilteredProcesslist = null; // invalidate memoization
        $clone->filters = $filters;
        return $clone;
    }

    /**
     * Get the current detail tab.
     */
    public function detailTab(): string
    {
        return self::DETAIL_TAB_ORDER[$this->detailBar->active()];
    }

    /**
     * Return a new instance with the detail tab set. An open panel is
     * refetched for the new tab, so the bar and the panel never disagree;
     * a closed panel stays closed.
     */
    public function withDetailTab(string $tab): self
    {
        $index = \array_search($tab, self::DETAIL_TAB_ORDER, true);
        if ($index === false || $index === $this->detailBar->active()) {
            return $this;
        }
        return $this->withDetailBar($this->detailBar->withActive($index))->withDetailRefreshed();
    }

    /**
     * The detail-tab bar, zones attached. Its {@see Tabs::view()} carries
     * the Mark zone markers a host scans; the page's own {@see view()}
     * renders the same bar without them.
     */
    public function detailTabBar(): Tabs
    {
        return $this->detailBar;
    }

    /**
     * The open detail panel's lines (the active tab's data for the selected
     * thread), or null while the panel is closed.
     *
     * @return list<string>|null
     */
    public function detailLines(): ?array
    {
        return $this->detailLines;
    }

    /**
     * Blur the detail-tab bar and close its panel, so the page claims no
     * key. App calls this when the user leaves the Admin pane: a bar left
     * focused there would keep claiming Tab and 1-3 on the way back.
     */
    public function releaseKeys(): self
    {
        if (!$this->detailBar->focused && $this->detailLines === null) {
            return $this;
        }
        $clone = clone $this;
        $clone->detailBar = $this->detailBar->blur();
        $clone->detailLines = null;
        return $clone;
    }

    /** True while the detail-tab bar owns the tab keys (after Enter, until Esc). */
    public function detailTabsFocused(): bool
    {
        return $this->detailBar->focused;
    }

    /**
     * While the detail-tab bar is focused the page claims the keys that
     * drive it — Tab, Shift+Tab and the digits 1-3 — so App routes them
     * here instead of cycling panes or picking an admin page. A blurred
     * bar claims nothing.
     */
    public function claimsKey(KeyMsg $msg): bool
    {
        if (!$this->detailBar->focused || $this->pendingKill !== null) {
            return false;
        }
        if ($msg->type === KeyType::Tab) {
            return true;
        }
        if ($msg->type !== KeyType::Char || $msg->ctrl || $msg->alt) {
            return false;
        }
        $index = (int) $msg->rune - 1;
        return \ctype_digit($msg->rune) && $index >= 0 && $index < \count(self::DETAIL_TAB_ORDER);
    }

    private function withDetailBar(Tabs $bar): self
    {
        if ($bar === $this->detailBar) {
            return $this;
        }
        $clone = clone $this;
        $clone->detailBar = $bar;
        return $clone;
    }

    /** Fetch the active tab's data for the selected thread into the panel. */
    private function withDetailFetched(): self
    {
        $clone = clone $this;
        $clone->detailLines = $clone->fetchDetailLines();
        $clone->detailThreadId = $clone->threadUnderSelection()?->processId;
        return $clone;
    }

    private function threadUnderSelection(): ?ProcesslistResult
    {
        return $this->filteredProcesslist()[$this->selectedIndex] ?? null;
    }

    /**
     * After a selection move, filter toggle, refresh or data reload: when a
     * different thread now sits under the selection, an open panel swaps
     * its lines for a "press Enter to load" line naming that thread. No
     * read happens here, because these paths repeat (a held j, every poll
     * tick) and a synchronous read on them would freeze the TUI for the
     * whole statement-timeout window per key. The panel, the highlight and
     * the bar still name one thread; Enter reads it.
     */
    private function withDetailFollowingSelection(): self
    {
        // A closed panel must not touch the processlist: reading it on a
        // reload would queue the provider's probe query on every poll.
        if ($this->detailLines === null) {
            return $this;
        }
        $thread = $this->threadUnderSelection();
        if ($thread?->processId === $this->detailThreadId) {
            return $this;
        }
        $clone = clone $this;
        $clone->detailThreadId = $thread?->processId;
        $clone->detailLines = $thread === null
            ? [Lang::t('conn.none_selected')]
            : [Lang::t('conn.detail_pending', [
                'tab' => $this->detailBar->labels()[$this->detailBar->active()],
                'id' => (string) $thread->processId,
            ])];
        return $clone;
    }

    /**
     * Refetch the panel when it is open ({@see withDetailTab()}, an explicit
     * one-shot call). A failed read (a stalled server's
     * StatementTimeoutException) closes the panel and blurs the bar, so the
     * tab change that asked for it still lands.
     */
    private function withDetailRefreshed(): self
    {
        if ($this->detailLines === null) {
            return $this;
        }
        try {
            return $this->withDetailFetched();
        } catch (\Throwable) {
            return $this->releaseKeys();
        }
    }

    /**
     * The active tab's data for the selected thread, one line per field,
     * attribute or lock. A read the server refuses (no performance_schema
     * access) or an empty answer gives one explanatory line instead.
     *
     * @return list<string>
     */
    private function fetchDetailLines(): array
    {
        $thread = $this->threadUnderSelection();
        if ($thread === null) {
            return [Lang::t('conn.none_selected')];
        }
        $id = $thread->processId;
        $data = match ($this->detailTab()) {
            self::DETAIL_TAB_DETAILS => $this->detailTabs?->details($id),
            self::DETAIL_TAB_ATTRIBUTES => $this->detailTabs?->attributes($id)['attributes'] ?? null,
            default => $this->detailTabs?->mdlLocks($id),
        };
        if ($data === null || $data === []) {
            return [Lang::t('conn.detail_none', [
                'tab' => $this->detailBar->labels()[$this->detailBar->active()],
                'id' => (string) $id,
            ])];
        }

        $lines = [];
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                // An MDL lock row: every column on one line.
                $cells = [];
                foreach ($value as $column => $cell) {
                    $cells[] = $column . '=' . self::cellText($cell);
                }
                $lines[] = CellValue::sanitize(\implode('  ', $cells));
            } else {
                $lines[] = CellValue::sanitize($key . ': ' . self::cellText($value));
            }
        }
        if (\count($lines) > self::DETAIL_MAX_LINES) {
            $lines = \array_slice($lines, 0, self::DETAIL_MAX_LINES - 1);
            $lines[] = '…';
        }
        return $lines;
    }

    private static function cellText(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Return a new instance with the selected index moved down.
     */
    private function withNavigateDown(): self
    {
        $filtered = $this->filteredProcesslist();
        $newIndex = $this->selectedIndex;
        if ($newIndex < \count($filtered) - 1) {
            $newIndex++;
        }
        return $this->withSelectedIndex($newIndex);
    }

    /**
     * Return a new instance with the selected index moved up.
     */
    private function withNavigateUp(): self
    {
        $newIndex = $this->selectedIndex;
        if ($newIndex > 0) {
            $newIndex--;
        }
        return $this->withSelectedIndex($newIndex);
    }

    /**
     * Handle keyboard shortcuts for connection navigation and filtering.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof ReloadReportMsg) {
            // Fresh data just landed in the shared AdminQueryCache (App forwards
            // this on every admin load/drain completion). Without this arm the
            // filtered-processlist memo set at last render sticks forever and
            // the table shows a frozen snapshot despite rows arriving in cache.
            if ($this->context instanceof AsyncCachingServerContext) {
                $this->context->refreshFromLiveCache();
            }
            $clone = clone $this;
            $clone->cachedFilteredProcesslist = null;
            if ($clone->processlistProvider !== null) {
                // refresh() also re-probes @@performance_schema: a cold-miss []
                // from the async cache previously stuck the probe at false for
                // the page's lifetime, pinning the fallback query even after
                // the real answer (ps=1) arrived.
                $clone->processlistProvider = $clone->processlistProvider->refresh();
            }
            if ($clone->counters !== null) {
                $clone->counters = ConnectionCounters::fromSnapshot(
                    new StatusSnapshot($this->context->statusVariables(), $this->context->statusVariablesTs()),
                    $this->maxConnections ?? 151,
                );
            }
            // The rows under the selection may have moved: an open panel
            // re-reads the thread that now sits at selectedIndex, the row the
            // table highlights, when that is a different thread.
            return [$clone->withDetailFollowingSelection(), null];
        }

        if ($msg instanceof MouseMsg) {
            return [$this->withMouseClick($msg), null];
        }

        // Early exit for non-key messages
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune;
        $type = $msg->type;

        // While a kill awaits confirmation, only an explicit y/Y fires it; ANY
        // other key cancels (fail-safe: a stray keystroke never kills a session).
        if ($this->pendingKill !== null) {
            if ($ch === 'y' || $ch === 'Y') {
                return $this->executeConfirmedKill();
            }
            return [$this->withClearedKillPrompt(Lang::t('conn.kill_cancelled')), null];
        }

        // j/k or Up/Down navigate the processlist selection
        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown()->withDetailFollowingSelection(), null];
        }

        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp()->withDetailFollowingSelection(), null];
        }

        // Enter focuses the detail-tab bar and opens its panel (on a focused
        // bar it re-reads the panel for the selected thread); Esc hands the
        // keyboard back and closes the panel. While focused, the bar's own
        // key map drives it: Tab / Shift+Tab cycle Details → Attributes →
        // MDL, and 1/2/3 jump (AUD §2 #19).
        if ($type === KeyType::Enter) {
            return [$this->withDetailBar($this->detailBar->focus())->withDetailFetched(), null];
        }
        if ($type === KeyType::Escape) {
            return [$this->releaseKeys(), null];
        }
        if ($this->claimsKey($msg)) {
            [$bar] = $this->detailBar->update($msg);
            assert($bar instanceof Tabs);
            return [$this->withDetailBarSwitched($bar), null];
        }

        // f toggles hide-sleeping filter
        if ($ch === 'f') {
            return [$this->withToggleHideSleeping()->withDetailFollowingSelection(), null];
        }

        // r triggers async refresh
        if ($ch === 'r') {
            return $this->handleRefresh();
        }

        // K arms a KILL CONNECTION; X arms a KILL QUERY. Both are destructive,
        // so they only set up a confirmation prompt — the kill fires on y.
        if ($ch === 'K') {
            return [$this->withPendingKill(false), null];
        }
        if ($ch === 'X') {
            return [$this->withPendingKill(true), null];
        }

        return [$this, null];
    }

    /**
     * Arm a kill confirmation for the currently-selected connection.
     *
     * Refuses background/system threads outright (killing them can destabilise
     * the server) and reports when there is nothing selected.
     */
    private function withPendingKill(bool $isQuery): self
    {
        $clone = clone $this;
        $selected = $this->filteredProcesslist()[$this->selectedIndex] ?? null;

        if ($selected === null) {
            $clone->actionMessage = Lang::t('conn.none_selected');
            return $clone;
        }
        if ($selected->isBackground) {
            $clone->actionMessage = Lang::t('conn.refuse_background');
            return $clone;
        }

        $clone->pendingKill = $selected;
        $clone->pendingKillIsQuery = $isQuery;
        $clone->actionMessage = null;
        return $clone;
    }

    /**
     * Clear any pending kill prompt, optionally leaving a feedback message.
     */
    private function withClearedKillPrompt(?string $message): self
    {
        $clone = clone $this;
        $clone->pendingKill = null;
        $clone->pendingKillIsQuery = false;
        $clone->actionMessage = $message;
        return $clone;
    }

    /**
     * Execute the confirmed kill against the pending connection, then refresh.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function executeConfirmedKill(): array
    {
        $target = $this->pendingKill;
        if ($target === null || $this->actions === null) {
            return [$this->withClearedKillPrompt(null), null];
        }

        $ok = $this->pendingKillIsQuery
            ? $this->actions->killQuery($target->processId, $target->isBackground)
            : $this->actions->kill($target->processId, $target->isBackground);

        $verb = $this->pendingKillIsQuery ? Lang::t('conn.verb_kill_query') : Lang::t('conn.verb_kill');
        $message = $ok
            ? Lang::t('conn.sent', ['verb' => $verb, 'id' => (string) $target->processId])
            : Lang::t('conn.failed', ['verb' => $verb, 'id' => (string) $target->processId]);

        // Re-fetch so the killed connection drops out of the grid.
        [$clone, $cmd] = $this->handleRefresh();
        $clone->pendingKill = null;
        $clone->pendingKillIsQuery = false;
        $clone->actionMessage = $message;
        return [$clone, $cmd];
    }

    /**
     * Adopt a bar the user just drove; a change of active tab (re)fetches
     * the panel for the new tab.
     */
    private function withDetailBarSwitched(Tabs $bar): self
    {
        $switched = $bar->active() !== $this->detailBar->active();
        $next = $this->withDetailBar($bar);
        return $switched ? $next->withDetailFetched() : $next;
    }

    /**
     * Route a left-button press through the detail bar's Mark zones.
     *
     * The coordinates are page-relative and 1-based (column 1, row 1 is
     * the first cell of this page's frame), the Scanner's convention; App
     * subtracts the page's screen origin ({@see \SugarCraft\Sql\Renderer::adminPageOrigin()})
     * before forwarding. The frame is re-rendered with zone markers,
     * scanned, and a hit on a tab zone activates that tab and opens its
     * panel. A click never grants keyboard focus (the Tabs law), and a miss
     * leaves the page unchanged.
     */
    private function withMouseClick(MouseMsg $msg): self
    {
        if ($msg->action !== MouseAction::Press || $msg->button !== MouseButton::Left) {
            return $this;
        }
        $marked = clone $this;
        $marked->publishZones = true;
        $zone = Scanner::new()->scan($marked->view())->hit($msg->x, $msg->y);
        if ($zone === null) {
            return $this;
        }
        [$bar] = $this->detailBar->clickZone($zone->id);
        assert($bar instanceof Tabs);
        $next = $this->withDetailBar($bar);
        return $next->detailLines === null || $bar->active() !== $this->detailBar->active()
            ? $next->withDetailFetched()
            : $next;
    }

    /**
     * Return a new instance with hide-sleeping filter toggled.
     */
    private function withToggleHideSleeping(): self
    {
        $current = $this->filters;
        return $this->withFilters(
            $current->withHideSleeping(!$current->hideSleeping),
        );
    }

    /**
     * Handle the refresh action, returning a new instance and async command.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleRefresh(): array
    {
        // Invalidate memoization so next render fetches fresh data
        $clone = clone $this;
        $clone->cachedFilteredProcesslist = null;
        $clone->processlistProvider = $this->processlistProvider->refresh();
        $clone = $clone->withDetailFollowingSelection();

        // Trigger async refresh via AdminFetchStartedMsg - the App's existing
        // async machinery will re-fetch all admin data including processlist.
        // This uses Cmd::send rather than promise since the actual re-fetch
        // happens through the existing AdminQueryCache polling loop.
        return [
            $clone,
            \SugarCraft\Core\Cmd::send(
                new \SugarCraft\Sql\Core\Msg\AdminFetchStartedMsg(),
            ),
        ];
    }

    /**
     * Get the selected thread for detail view.
     */
    public function selectedThread(): ?ProcesslistResult
    {
        return $this->selectedThread;
    }

    /**
     * Get the current selected index in the filtered processlist.
     */
    public function selectedIndex(): int
    {
        return $this->selectedIndex;
    }

    /**
     * Select a thread by index in the current filtered list.
     *
     * @param int $index 0-based index in filtered processlist
     * @return self
     */
    public function withSelectedIndex(int $index): self
    {
        // (adminpages-009) A cursor move cannot change which rows pass the
        // filters — invalidating the memo here made every j/k keystroke
        // re-fetch and re-wrap the entire processlist DTO. The filter result
        // is keyed on (filter identity, provider identity) in
        // filteredProcesslist(); moving the selection keeps the memo warm.
        $clone = clone $this;
        $filtered = $clone->filteredProcesslist();

        $clone->selectedIndex = $index;
        if ($index >= 0 && $index < \count($filtered)) {
            $clone->selectedThread = $filtered[$index];
        } else {
            $clone->selectedThread = null;
        }
        return $clone;
    }

    /**
     * Get the processlist rows with current filters applied.
     *
     * @return list<ProcesslistResult>
     */
    public function filteredProcesslist(): array
    {
        // (adminpages-009) Fail loud for an unwired page: a bare `new` (no ::new)
        // has no provider, and an empty-list fallback here would silently pin a
        // forever-empty grid instead of surfacing the construction error.
        $provider = $this->processlistProvider
            ?? throw new \LogicException('ConnectionsPage rendered before its processlist provider was wired.');
        // (adminpages-009) The memo is valid for exactly one (filters, provider)
        // pair: eager nulling at the filter-touching clone arms is the fast path,
        // these identity checks are the correctness belt — a provider swap
        // (refresh()) changes the data under an unchanged-looking page clone,
        // while a cursor move (withSelectedIndex) changes neither and stays warm.
        $providerId = \spl_object_id($provider);
        if (
            $this->cachedFilteredProcesslist === null
            || $this->filters !== $this->memoFilters
            || $providerId !== $this->memoProviderId
        ) {
            $rows = $provider->fetchAll();
            $this->cachedFilteredProcesslist = $this->applyFilters($rows);
            $this->memoFilters = $this->filters;
            $this->memoProviderId = $providerId;
        }
        return $this->cachedFilteredProcesslist;
    }

    /**
     * Refresh processlist data.
     */
    public function refresh(): self
    {
        $this->context->refresh();
        $clone = clone $this;
        // (adminpages-009) No memo clear needed: the new provider object fails
        // the identity belt in filteredProcesslist(), so the next read re-derives.
        $clone->processlistProvider = $this->processlistProvider->refresh();
        return $clone;
    }

    /**
     * Validate that the context is usable.
     */
    protected function validate(): bool
    {
        try {
            $this->context->serverVariables();
            return true;
        } catch (\Throwable) {
            $this->errorMessage = Lang::t('conn.vars_fail');
            return false;
        }
    }

    /**
     * Compose the connections page output: header, counters, and processlist table.
     */
    protected function build(): string
    {
        $lines = [
            Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Connections'),
            '',
            $this->renderCounters(),
        ];

        $actionLine = $this->renderActionLine();
        if ($actionLine !== '') {
            $lines[] = $actionLine;
        }

        $lines[] = '';
        $lines[] = $this->renderDetailTabBar();
        // +1: the key-hint line under the table.
        [$detail, $table] = $this->fitDetailPanel(\count($lines) + 1);
        $panel = Style::new()->foreground(Color::hex('#cbd5e1'));
        foreach ($detail as $line) {
            $lines[] = '   ' . $panel->render($line);
        }
        $lines[] = $table->View();
        $lines[] = $this->renderKeyHints();

        return implode("\n", $lines);
    }

    /**
     * The page with a height budget: the most rows its frame may take,
     * or null for no limit (standalone use and tests).
     *
     * The App frame hard-truncates overflow from the bottom, so without a
     * budget an open detail panel above the processlist would push the
     * table's last rows and the key-hint line off the screen. With one,
     * {@see fitDetailPanel()} keeps the page inside it.
     */
    public function withHeightBudget(?int $rows): self
    {
        $rows = $rows === null ? null : \max(0, $rows);
        if ($rows === $this->heightBudget) {
            return $this;
        }
        $clone = clone $this;
        $clone->heightBudget = $rows;
        return $clone;
    }

    /**
     * The panel lines and processlist table to paint, inside the height
     * budget.
     *
     * The panel first takes the rows the table leaves free, so a table that
     * fit before Enter still fits after it. When that slack is smaller than
     * the panel, the panel still gets up to {@see DETAIL_MIN_LINES} (cut with
     * an ellipsis line) and the table's row window gives up exactly that
     * many rows, so the key-hint line and the frame stay on screen. The
     * table never drops below its chrome plus the selected row, so on a
     * short processlist the panel gets fewer lines, or none.
     *
     * @param int $fixed rows the page takes besides the panel and the table
     * @return array{list<string>, Table}
     */
    private function fitDetailPanel(int $fixed): array
    {
        $table = $this->table();
        $panel = $this->detailLines ?? [];
        if ($panel === [] || $this->heightBudget === null) {
            return [$panel, $table];
        }

        $room = \max(0, $this->heightBudget - $fixed);
        $tableHeight = \substr_count($table->View(), "\n") + 1;
        $slack = $room - $tableHeight;
        if ($slack >= \count($panel)) {
            return [$panel, $table];
        }

        // The table can give up its rows but not its header, separator and
        // footer, and it keeps the selected row (the thread the panel
        // describes): the panel's floor never eats into that.
        $rows = $this->filteredProcesslist();
        $painted = \min(\count($rows), self::ROW_WINDOW);
        $keep = \min(1, $painted);
        $tableFloor = \substr_count($this->buildTable($rows, $keep)->View(), "\n") + 1;
        $take = \max(0, \min(\count($panel), \max($slack, self::DETAIL_MIN_LINES), $room - $tableFloor));
        if ($take === 0) {
            return [[], $table];
        }
        if ($take < \count($panel)) {
            $panel = [...\array_slice($panel, 0, $take - 1), '…'];
        }

        $over = $tableHeight + \count($panel) - $room;
        if ($over > 0) {
            $table = $this->buildTable($rows, \max($keep, $painted - $over));
        }
        return [$panel, $table];
    }

    /**
     * The detail-tab bar line. The painted frame never carries zone
     * markers (the renderer prices every cell, and a stray marker is a
     * visible box on some terminals); only the hit-test pass in
     * {@see withMouseClick()} renders them.
     */
    private function renderDetailTabBar(): string
    {
        $bar = $this->publishZones ? $this->detailBar : $this->detailBar->withMark(null);
        return ' ' . $bar->view();
    }

    /**
     * Render the kill confirmation prompt, or the latest action feedback line.
     * Returns '' when neither is active.
     */
    private function renderActionLine(): string
    {
        if ($this->pendingKill !== null) {
            $verb = $this->pendingKillIsQuery ? Lang::t('conn.confirm_verb_query') : Lang::t('conn.confirm_verb_kill');
            return Style::new()->bold()->foreground(Color::hex('#f9e2af'))->render(
                Lang::t('conn.confirm', [
                    'verb' => $verb,
                    'id' => (string) $this->pendingKill->processId,
                    'user' => $this->pendingKill->user !== '' ? $this->pendingKill->user : '?',
                    'host' => $this->pendingKill->host !== '' ? $this->pendingKill->host : '?',
                ]),
            );
        }
        if ($this->actionMessage !== null) {
            return Style::new()->foreground(Color::hex('#94a3b8'))->render($this->actionMessage);
        }
        return '';
    }

    /**
     * Render the page-specific key hints (the global status bar only shows the
     * generic admin keys, so the kill shortcuts need advertising here). The
     * kill shortcuts are dropped when there is nothing to kill — SQL-P16
     * (adminpages-033): an empty processlist used to advertise `K`/`X`
     * bindings that could only ever beep at the user.
     */
    private function renderKeyHints(): string
    {
        // SQL-P18 (adminpages-020): bracket grammar like every other page.
        $hints = $this->filteredProcesslist() === []
            ? Lang::t('conn.help_empty')
            : Lang::t('conn.help_full');
        if ($this->detailBar->focused) {
            $hints = Lang::t('conn.help_tabs') . '  ' . $hints;
        }

        return Style::new()->foreground(Color::hex('#6b7280'))->render($hints);
    }

    /**
     * Render the connection counters as a single styled summary line. The
     * usage figure colours itself green/red at the critical-usage threshold.
     *
     * (Dash\Stat / Metric were considered but fit poorly here: Stat's align is
     * inverted and Metric is float-only, so neither renders the mixed integer +
     * "%" counters cleanly — a Sprinkles\Style line reads better.)
     */
    private function renderCounters(): string
    {
        $c = $this->counters;
        $label = Style::new()->foreground(Color::hex('#6b7280'));
        $value = Style::new()->foreground(Color::hex('#cdd6f4'));
        $usageStyle = Style::new()->foreground(
            $c->isConnectionUsageCritical() ? Color::hex('#f38ba8') : Color::hex('#a6e3a1'),
        );

        $pairs = [
            $label->render('Connected ') . $value->render((string) $c->threadsConnected),
            $label->render('Running ') . $value->render((string) $c->threadsRunning),
            $label->render('Cached ') . $value->render((string) $c->threadsCached),
            $label->render('Max ') . $value->render((string) $c->maxConnections),
            $label->render('Usage ') . $usageStyle->render(number_format($c->connectionUsageRatio() * 100.0, 1) . '%'),
            $label->render('Aborted ') . $value->render((string) $c->abortedConnects),
        ];

        return implode($label->render('  ·  '), $pairs);
    }

    /**
     * Apply filters to processlist rows.
     *
     * @param list<ProcesslistResult> $rows
     * @return list<ProcesslistResult>
     */
    private function applyFilters(array $rows): array
    {
        if ($this->filters->hideSleeping) {
            $rows = \array_values(
                \array_filter($rows, fn(ProcesslistResult $r) => $r->command !== 'Sleep')
            );
        }

        if ($this->filters->hideBackground) {
            $rows = \array_values(
                \array_filter($rows, fn(ProcesslistResult $r) => !$r->isBackground())
            );
        }

        return $rows;
    }

    /**
     * Build a sugar-table Table from processlist rows.
     *
     * @param list<ProcesslistResult> $rows
     * @param int $limit most rows to paint
     * @return Table
     */
    private function buildTable(array $rows, int $limit = self::ROW_WINDOW): Table
    {
        $columns = $this->buildColumns();
        // SQL-P09 (adminpages-010): the SHOW FULL PROCESSLIST mirror carries
        // up to 500 rows and the pane clips everything past the frame edge;
        // building a Row (RowData wrap) per row per frame for ~480 lines no
        // terminal can show is pure churn. Every interaction keeps reading
        // the complete filteredProcesslist(); only string construction is
        // bounded.
        //
        // The painted window scrolls so the selection is its last row once
        // the cursor moves past it: the highlighted row is the thread the
        // detail panel describes, and both must stay on screen.
        $limit = \min(\max(0, $limit), self::ROW_WINDOW);
        $start = $this->selectedIndex >= $limit && $this->selectedIndex < \count($rows)
            ? $this->selectedIndex - $limit + 1
            : 0;
        $tableRows = $this->buildRows(\array_slice($rows, $start, $limit));

        // The highlight follows the page cursor. A selection past the end
        // after a reload (or a zero-row window) shows no highlight rather
        // than a wrong one.
        $cursor = $this->selectedIndex - $start;
        $shown = $cursor >= 0 && $cursor < \count($tableRows);
        $table = Table::fromColumns($columns)
            ->withRows($tableRows)
            ->withSelectable($shown)
            ->withZebra();
        if ($shown) {
            $table = $table->withSelectedIndex($cursor);
        }

        if ($this->filters->skipFullInfo) {
            $table = $table->withShowFooter(false);
        }

        return $table;
    }

    /**
     * Build the column definitions for the processlist table.
     *
     * @return list<Column>
     */
    private function buildColumns(): array
    {
        return [
            Column::new('id', 'Id', 8)->withAlignLeft(),
            Column::new('user', 'User', 12)->withFilterable(),
            Column::new('host', 'Host', 20)->withFilterable()->withAlignLeft(),
            Column::new('db', 'DB', 12)->withFilterable()->withAlignLeft(),
            Column::new('command', 'Command', 10),
            Column::new('time', 'Time', 8),
            Column::new('state', 'State', 15)->withAlignLeft(),
            Column::new('info', 'Info', 40)->withAlignLeft()->withMaxWidth(100),
        ];
    }

    /**
     * Convert processlist rows to table Row objects.
     *
     * @param list<ProcesslistResult> $rows
     * @return list<Row>
     */
    private function buildRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $data = RowData::from([
                'id' => $row->processId,
                'user' => $row->user,
                'host' => $row->host,
                'db' => $row->database,
                'command' => $row->command,
                'time' => $row->time,
                'state' => $row->state,
                'info' => $row->info,
            ]);
            $result[] = Row::new($data);
        }
        return $result;
    }
}
