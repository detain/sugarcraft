<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Admin\StatusSnapshot;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Table\{Column, Row, RowData, Table};

/**
 * Connections page component integrating processlist, counters, filters, and actions.
 *
 * Uses sugar-table Table for the processlist grid with columns for
 * Id, User, Host, DB, Command, Time, State, Info. Provides detail tabs
 * for selected threads.
 *
 * @see Mirrors charmbracelet/lazysql connections page
 */
final class ConnectionsPage extends PageBase
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
    private string $activeDetailTab = self::DETAIL_TAB_DETAILS;

    /** Connection awaiting a kill confirmation (null = no pending kill). */
    private ?ProcesslistResult $pendingKill = null;
    /** True when the pending action is KILL QUERY rather than KILL CONNECTION. */
    private bool $pendingKillIsQuery = false;
    /** Transient feedback line shown after an action (kill sent/failed/cancelled). */
    private ?string $actionMessage = null;

    /** @var list<ProcesslistResult>|null Memoized filtered processlist to avoid 2-3× fetch per render */
    private ?array $cachedFilteredProcesslist = null;

    public function __construct(
        ServerContextInterface $context,
        private readonly ?int $maxConnections = 151,
    ) {
        parent::__construct($context);
        $this->filters = ConnectionFilters::new();
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
    public function getTable(): Table
    {
        return $this->buildTable($this->filteredProcesslist());
    }

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
        return $this->activeDetailTab;
    }

    /**
     * Return a new instance with the detail tab set.
     */
    public function withDetailTab(string $tab): self
    {
        if (!\in_array($tab, self::DETAIL_TAB_ORDER, true)) {
            return $this;
        }
        $clone = clone $this;
        $clone->activeDetailTab = $tab;
        return $clone;
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
        // Early exit for non-key messages
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';
        $type = $msg->type;

        // While a kill awaits confirmation, only an explicit y/Y fires it; ANY
        // other key cancels (fail-safe: a stray keystroke never kills a session).
        if ($this->pendingKill !== null) {
            if ($ch === 'y' || $ch === 'Y') {
                return $this->executeConfirmedKill();
            }
            return [$this->withClearedKillPrompt('Kill cancelled'), null];
        }

        // j/k or Up/Down navigate the processlist selection
        if ($ch === 'j' || $type === KeyType::Down) {
            return [$this->withNavigateDown(), null];
        }

        if ($ch === 'k' || $type === KeyType::Up) {
            return [$this->withNavigateUp(), null];
        }

        // Tab cycles through detail tabs (Details → Attributes → MDL → Details)
        if ($type === KeyType::Tab && !$msg->shift) {
            return [$this->withCycleDetailTab(), null];
        }

        // 1/2/3 switch to specific detail tabs
        if ($ch === '1') {
            return [$this->withDetailTab(self::DETAIL_TAB_DETAILS), null];
        }
        if ($ch === '2') {
            return [$this->withDetailTab(self::DETAIL_TAB_ATTRIBUTES), null];
        }
        if ($ch === '3') {
            return [$this->withDetailTab(self::DETAIL_TAB_MDL), null];
        }

        // f toggles hide-sleeping filter
        if ($ch === 'f') {
            return [$this->withToggleHideSleeping(), null];
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
            $clone->actionMessage = 'No connection selected';
            return $clone;
        }
        if ($selected->isBackground) {
            $clone->actionMessage = 'Refusing to kill a background thread';
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

        $verb = $this->pendingKillIsQuery ? 'Kill query' : 'Kill';
        $message = $ok
            ? sprintf('%s sent to connection %s', $verb, (string) $target->processId)
            : sprintf('%s failed for connection %s', $verb, (string) $target->processId);

        // Re-fetch so the killed connection drops out of the grid.
        [$clone, $cmd] = $this->handleRefresh();
        $clone->pendingKill = null;
        $clone->pendingKillIsQuery = false;
        $clone->actionMessage = $message;
        return [$clone, $cmd];
    }

    /**
     * Return a new instance with the detail tab cycled to the next one.
     */
    private function withCycleDetailTab(): self
    {
        $current = \array_search($this->activeDetailTab, self::DETAIL_TAB_ORDER, true);
        $next = ($current + 1) % \count(self::DETAIL_TAB_ORDER);
        return $this->withDetailTab(self::DETAIL_TAB_ORDER[$next]);
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

        // Trigger async refresh via AdminFetchStartedMsg - the App's existing
        // async machinery will re-fetch all admin data including processlist.
        // This uses Cmd::send rather than promise since the actual re-fetch
        // happens through the existing AdminQueryCache polling loop.
        return [
            $clone,
            \SugarCraft\Core\Cmd::send(
                new \SugarCraft\Query\Core\Msg\AdminFetchStartedMsg(),
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
        $clone = clone $this;
        $clone->cachedFilteredProcesslist = null; // invalidate memoization
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
        if ($this->cachedFilteredProcesslist === null) {
            $rows = $this->processlistProvider->fetchAll();
            $this->cachedFilteredProcesslist = $this->applyFilters($rows);
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
            $this->errorMessage = 'Unable to fetch server variables';
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
        $lines[] = $this->getTable()->View();
        $lines[] = $this->renderKeyHints();

        return implode("\n", $lines);
    }

    /**
     * Render the kill confirmation prompt, or the latest action feedback line.
     * Returns '' when neither is active.
     */
    private function renderActionLine(): string
    {
        if ($this->pendingKill !== null) {
            $verb = $this->pendingKillIsQuery ? 'Cancel running query on' : 'KILL';
            return Style::new()->bold()->foreground(Color::hex('#f9e2af'))->render(
                sprintf(
                    '%s connection %s (%s@%s)?  [y] confirm   [any other key] cancel',
                    $verb,
                    (string) $this->pendingKill->processId,
                    $this->pendingKill->user !== '' ? $this->pendingKill->user : '?',
                    $this->pendingKill->host !== '' ? $this->pendingKill->host : '?',
                ),
            );
        }
        if ($this->actionMessage !== null) {
            return Style::new()->foreground(Color::hex('#94a3b8'))->render($this->actionMessage);
        }
        return '';
    }

    /**
     * Render the page-specific key hints (the global status bar only shows the
     * generic admin keys, so the kill shortcuts need advertising here).
     */
    private function renderKeyHints(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))->render(
            'j/k:nav  Tab:tabs  K:kill  X:kill-query  f:filter  r:refresh',
        );
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
     * @return Table
     */
    private function buildTable(array $rows): Table
    {
        $columns = $this->buildColumns();
        $tableRows = $this->buildRows($rows);

        $table = Table::withColumns($columns)
            ->withRows($tableRows)
            ->withSelectable()
            ->withZebra();

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
