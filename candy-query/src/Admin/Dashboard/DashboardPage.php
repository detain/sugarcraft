<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Dashboard;

use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\QueryLogger;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;
use SugarCraft\Query\Renderer;
use SugarCraft\Layout\Region;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Query\Admin\Alerts\Alert;
use SugarCraft\Query\Admin\Alerts\AlertManager;
use SugarCraft\Query\Admin\Alerts\AlertNotifier;
use SugarCraft\Query\Admin\Alerts\AlertNotifierInterface;
use SugarCraft\Query\Admin\Alerts\AlertThresholds;
use SugarCraft\Sprinkles\Style;

/**
 * Performance Dashboard page with 3-column layout.
 *
 * Shows Network, MySQL, and InnoDB panels with live metrics,
 * timeline graphs, counters, and meters. Refreshes on MySQL Workbench's
 * one-second Performance-Dashboard cadence (gated by CacheTtl::DASHBOARD)
 * by sampling the ServerContext cache.
 *
 * Keyboard shortcuts:
 *   [p] - pause/resume auto-refresh
 *   [r] - reset all counters and graphs
 *   [a] - dismiss pending alerts
 *
 * @see Mirrors mysql-workbench/wb_admin_performance_dashboard
 */
final class DashboardPage extends PageBase
{
    private bool $paused = false;

    private ?float $lastPollAt = null;

    /**
     * Wall-clock of the last committed snapshot. Rate denominators measure
     * against THIS, not the gate stamp: the ReloadReportMsg arm nulls
     * lastPollAt to bypass the throttle, and if elapsed were derived from
     * lastPollAt too, the first post-reload poll would silently fall back to
     * the DASHBOARD window and skew every rate (a 6s data gap divided by 1s
     * reads 6x hot). MySQL Workbench samples against its own last frame.
     */
    private ?float $lastSnapshotAt = null;

    /** @var array<string, MultiSeriesCell> */
    private array $timelineCells = [];

    /** @var array<string, CounterCell> */
    private array $counterCells = [];

    /** @var array<string, MeterCell> */
    private array $meterCells = [];

    /** @var array<Widget> */
    private array $allWidgets = [];

    /** @var array<string, list<Widget>> */
    private array $sectionWidgetCache = [];

    private ?string $previousSnapshot = null;

    private bool $isPostgres = false;

    /** @var array<string, Alert> */
    private array $pendingAlerts = [];

    /** @var array<string, bool> Tracks which alert keys were breached at last check for dedup */
    private array $breachedAlertKeys = [];

    private AlertNotifierInterface $alertNotifier;

    public function __construct(
        ServerContextInterface $context,
        ?Version $version = null,
    ) {
        parent::__construct($context);
        $this->isPostgres = $this->context->flavor() === Flavor::Postgres;
        $this->allWidgets = $this->isPostgres
            ? WidgetRegistry::buildForPostgres()
            : WidgetRegistry::build($version ?? $this->context->version());
        $this->initializeCells();
        $this->buildSectionWidgetCache();
        // Mute-safe by default — no toast factory means notify() is a no-op
        $this->alertNotifier = AlertNotifier::withDefaults(muted: true);
    }

    protected function validate(): bool
    {
        try {
            $vars = $this->context->statusVariables();
            return count($vars) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function view(): string
    {
        // Show loading screen when fetch is in flight and no cached data available
        if ($this->context instanceof AsyncCachingServerContext
            && $this->context->isLoading()
            && !$this->context->hasCachedData()) {
            return $this->renderLoadingScreen();
        }
        if (!$this->validate()) {
            return $this->errorScreen();
        }
        return $this->build();
    }

    private function renderLoadingScreen(): string
    {
        $glyph = Spinner::new(SpinnerStyle::dot())->view();
        $muted = Style::new()->foreground(Color::hex('#6b7280'));

        return implode("\n", [
            Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('  Performance Dashboard'),
            '',
            Style::new()->foreground(Color::hex('#fbbf24'))->render("    {$glyph} Fetching server metrics..."),
            '',
            '  ' . $muted->render('SHOW GLOBAL STATUS / pg_stat_database'),
            '',
            '  ' . $muted->render('Press [q] to return to browse mode'),
        ]);
    }

    protected function build(): string
    {
        $this->pollAndUpdateCells();

        // C3: size the dashboard from the live terminal (the Program's
        // WindowSizeMsg, forwarded into Renderer::setSize) instead of a fixed
        // 80×24, so it tracks resizes. The dashboard fills the admin pane's
        // content column; Renderer::adminContentWidth() is the single source of
        // truth for that width (shared with Renderer::adminPane()), so the two
        // can no longer drift apart.
        $size = Renderer::getTerminalSize();
        $width = Renderer::adminContentWidth($size['cols']);
        $height = max(12, $size['rows'] - 4);

        $region = Region::fromSize($width, $height);

        $colConstraints = [
            Constraint::percentage(33),
            Constraint::percentage(34),
            Constraint::percentage(33),
        ];

        $solver = GreedySolver::new();
        $columns = $solver->solve($region, Direction::Horizontal, $colConstraints);

        $networkCol = $columns[0] ?? $region;
        $mysqlCol = $columns[1] ?? $region;
        $innodbCol = $columns[2] ?? $region;

        $networkContent = $this->renderPanel($this->isPostgres ? 'I/O' : 'Network', $networkCol, 'network');
        $mysqlContent = $this->renderPanel($this->isPostgres ? 'Transactions' : 'MySQL', $mysqlCol, 'mysql');
        $innodbContent = $this->renderPanel($this->isPostgres ? 'Cache' : 'InnoDB', $innodbCol, 'innodb');

        $header = $this->renderHeader();
        $footer = $this->renderFooter();
        $queryLog = $this->renderQueryLog($width);

        return $this->assembleLayout($header, $networkContent, $mysqlContent, $innodbContent, $queryLog, $footer);
    }

    /** Newest-first query-log rows shown in the dashboard strip. */
    private const QUERY_LOG_ROWS = 5;

    /**
     * Compact live view of the most recent admin queries, drawn straight from
     * {@see QueryLogger}. Mirrors the standalone Debug pane but trimmed to a
     * few newest-first rows so the dashboard can show what's actually hitting
     * the server without leaving the page.
     */
    private function renderQueryLog(int $width): string
    {
        $title = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Recent Queries');

        $entries = QueryLogger::getEntries();
        if ($entries === []) {
            $muted = Style::new()->foreground(Color::ansi(8))->render('  (no queries yet)');
            return $title . "\n" . $muted;
        }

        // Newest first, capped to the strip height.
        $recent = array_slice(array_reverse($entries), 0, self::QUERY_LOG_ROWS);

        $lines = [$title];
        foreach ($recent as $entry) {
            $lines[] = $this->renderQueryLogRow($entry, $width);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{timestamp: float, type: string, sql: string, rows: int, error: string|null} $entry
     */
    private function renderQueryLogRow(array $entry, int $width): string
    {
        $ts = date('H:i:s', (int) $entry['timestamp']);
        $ms = (int) (($entry['timestamp'] - floor($entry['timestamp'])) * 1000);
        $time = Style::new()->foreground(Color::hex('#6b7280'))->render(sprintf('%s.%03d', $ts, $ms));

        $typeColor = match ($entry['type']) {
            'error' => Color::hex('#f38ba8'),
            'status', 'server' => Color::hex('#89b4fa'),
            default => Color::hex('#a6e3a1'),
        };
        $type = Style::new()->foreground($typeColor)->render(str_pad($entry['type'], 8));

        // Budget the SQL column from the strip width: 12 (time) + 9 (type) +
        // ~14 (rows/err) + separators. Clamp so a wide query can't overflow the
        // dashboard's content column (the diff renderer is 1 line per row).
        $sqlBudget = max(10, $width - 40);
        $sql = $entry['sql'];
        if (strlen($sql) > $sqlBudget) {
            $sql = substr($sql, 0, $sqlBudget - 1) . '…';
        }
        $sqlStyled = Style::new()->foreground(Color::hex('#cdd6f4'))->render($sql);

        $rows = $entry['rows'] > 0
            ? Style::new()->foreground(Color::hex('#6b7280'))->render(" [{$entry['rows']} rows]")
            : '';

        $err = $entry['error'] !== null
            ? ' ' . Style::new()->foreground(Color::hex('#f38ba8'))->render('⚠ ' . $entry['error'])
            : '';

        return "  {$time} {$type} {$sqlStyled}{$rows}{$err}";
    }

    public function update(\SugarCraft\Core\Msg $msg): array
    {
        if ($msg instanceof \SugarCraft\Query\Core\Msg\ReloadReportMsg) {
            // App forwards this whenever fresh admin data lands in the shared
            // cache. Without the arm the page keeps the construction-time
            // snapshot pinned in AsyncCachingServerContext forever: every poll
            // would compute current == previous, all rates read 0, and
            // TimeSeriesCell drops non-positive samples — flat/empty graphs.
            if ($this->context instanceof AsyncCachingServerContext) {
                $this->context->refreshFromLiveCache();
            }
            // Force the next view() to poll immediately instead of waiting out
            // the 1s throttle window — fresh data just arrived.
            $this->lastPollAt = null;
            return [$this, null];
        }
        if (!$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';

        return match (true) {
            $ch === 'p' => [$this->withTogglePause(), null],
            $ch === 'r' => [$this->withReset(), null],
            $ch === 'a' => [$this->withClearAlerts(), null],
            default => [$this, null],
        };
    }

    private function pollAndUpdateCells(): void
    {
        if ($this->paused) {
            return;
        }

        $now = microtime(true);

        if ($this->lastPollAt !== null && ($now - $this->lastPollAt) < CacheTtl::DASHBOARD) {
            return;
        }

        $current = $this->context->statusVariables();
        $previous = $this->previousSnapshot !== null
            ? (array) json_decode($this->previousSnapshot, true)
            : $current;
        $serverVars = $this->context->serverVariables();

        // Rate denominator = true wall-clock since the last committed snapshot,
        // never the gate stamp: the ReloadReportMsg arm nulls only lastPollAt
        // (a gate bypass), so a poll that arrives 6s after the last frame
        // divides by 6 — not by the fallback window (C4/M1: no 3s/1s-fallback
        // skew on the first post-reload sample). The fallback remains for the
        // very first poll, where there is no previous frame to span.
        $elapsed = $this->lastSnapshotAt === null
            ? CacheTtl::DASHBOARD
            : max(0.001, $now - $this->lastSnapshotAt);

        $this->lastPollAt = $now;
        $this->lastSnapshotAt = $now;

        foreach ($this->timelineCells as $cell) {
            $cell->ingest($current, $previous, $elapsed);
        }

        foreach ($this->counterCells as $cell) {
            $cell->ingest($current, $previous, $elapsed);
        }

        foreach ($this->meterCells as $cell) {
            $cell->ingest($current, $previous, $elapsed, $serverVars);
        }

        $this->previousSnapshot = json_encode($current);

        // Non-blocking alert check — failures here don't stall the dashboard
        $this->checkAlerts($current, $serverVars);
    }

    /**
     * Check metrics against alert thresholds and queue any violations.
     *
     * Uses a mute-safe notifier by default; provide a factory via
     * withAlertNotifier() to enable toast notifications.
     *
     * Only fires a toast notification when a key transitions from not-breached
     * to breached — not on every poll tick while the breach persists. The
     * $breachedAlertKeys array tracks which alert keys were active at the last
     * check; array_diff_key against the current alert keys isolates newly-breached
     * entries. This prevents alert storms when a threshold remains breached
     * across consecutive 1s poll cycles.
     *
     * @param array $statusVars  SHOW GLOBAL STATUS (or pg_stat_database for Postgres)
     * @param array $serverVars  SHOW GLOBAL VARIABLES (or pg_settings for Postgres)
     */
    private function checkAlerts(array $statusVars, array $serverVars): void
    {
        $manager = AlertManager::new()
            ->withThresholds(AlertThresholds::default())
            ->withNotifier($this->alertNotifier);

        $alerts = $manager->checkAllMetrics($statusVars, $serverVars);

        // Compute keys that are newly breached (not in last-breached set).
        // Use array_diff_key+array_flip to compare by key name, not value.
        $currentKeys = array_flip(array_keys($alerts));
        $previousKeys = $this->breachedAlertKeys;
        $newKeys = array_diff_key($currentKeys, $previousKeys);

        if ($alerts !== []) {
            // Merge new alerts, avoiding duplicates by key
            $this->pendingAlerts = array_merge($this->pendingAlerts, $alerts);

            // Dispatch to notifier only for NEWLY breached keys (dedup).
            // Continuously-breached keys do NOT re-fire the toast.
            foreach ($alerts as $key => $alert) {
                if (isset($newKeys[$key])) {
                    $this->alertNotifier = $this->alertNotifier->notify($alert);
                }
            }
        }

        // Persist current breach keys so the next tick can detect new entries.
        // Keys that have cleared naturally drop out since $alerts only holds
        // currently-breached entries.
        $this->breachedAlertKeys = $currentKeys;
    }

    private function initializeCells(): void
    {
        foreach ($this->allWidgets as $widget) {
            $id = $this->widgetId($widget);

            match ($widget->kind) {
                // Workbench plots every dashboard timeline as its own colored
                // trace, so all timelines route through MultiSeriesCell: tuple
                // calcs get one series per member, scalar calcs a single trace.
                // Height 4 rows: the Network panel stacks Bytes In/Out charts
                // plus the Connections chart+bar in the ~20-row panel budget of
                // a 24-row terminal; braille's 4x vertical resolution keeps a
                // 4-cell trace legible (16 dot rows).
                WidgetRegistry::KIND_TIMELINE => $this->timelineCells[$id] = new MultiSeriesCell($widget, width: 40, height: 4),
                WidgetRegistry::KIND_COUNTER => $this->counterCells[$id] = new CounterCell($widget),
                WidgetRegistry::KIND_ROUND, WidgetRegistry::KIND_LEVEL => $this->meterCells[$id] = new MeterCell($widget),
                default => null,
            };
        }
    }

    /**
     * Build the per-section widget cache once during construction.
     *
     * This avoids rebuilding widget lists every frame, which was causing
     * the catalog to re-read version and drift from the keyed cells.
     */
    private function buildSectionWidgetCache(): void
    {
        if ($this->isPostgres) {
            $this->sectionWidgetCache = [
                'network' => WidgetRegistry::postgresIo(),
                'mysql' => WidgetRegistry::postgresTransactions(),
                'innodb' => WidgetRegistry::postgresCache(),
            ];
            return;
        }

        $version = $this->context->version();
        $this->sectionWidgetCache = [
            'network' => WidgetRegistry::network(),
            'mysql' => WidgetRegistry::mysql($version),
            'innodb' => WidgetRegistry::innodb(),
        ];
    }

    private function widgetId(Widget $widget): string
    {
        return $widget->caption . ':' . $widget->kind;
    }

    /**
     * Render a dashboard panel: section header, then one block per widget with
     * the caption on its own line and the visualisation underneath.
     *
     * Workbench composes each metric as graph + labels in one frame, so some
     * widgets are not drawn standalone: the network/disk kb/s counters become
     * labels under their timelines, and the Connections level meter is drawn
     * beside its chart instead of below it. Such siblings are marked consumed
     * (keyed by widget id) and skipped when the walk reaches them.
     */
    private function renderPanel(string $title, Region $region, string $section): string
    {
        $lines = [];
        $lines[] = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render($title);

        $widgets = $this->getWidgetsForSection($section);
        $panelWidth = max(10, $region->width - 2);

        /** @var array<string,bool> $consumed */
        $consumed = [];

        foreach ($widgets as $widget) {
            $id = $this->widgetId($widget);
            if (isset($consumed[$id])) {
                continue;
            }

            $color = $widget->color;
            $caption = Style::new()
                ->foreground(Color::rgb($color['r'], $color['g'], $color['b']))
                ->render($widget->caption);
            $lines[] = $caption;

            foreach ($this->renderWidgetBlock($widget, $panelWidth, $consumed) as $row) {
                $lines[] = ' ' . $row;
            }
        }

        $padding = $region->height - count($lines);
        for ($i = 0; $i < $padding; $i++) {
            $lines[] = '';
        }

        return implode("\n", array_slice($lines, 0, $region->height));
    }

    /**
     * The visualisation block for one widget. MySQL panel captions select the
     * Workbench-shaped compositions; anything else (every Postgres widget,
     * future additions) falls through to a generic chart/donut/counter block,
     * which keeps PG rendering intact while still gaining multi-series traces
     * for its tuple timelines (e.g. Transactions commits/rollbacks).
     *
     * @param array<string,bool> $consumed in-out: sibling ids folded into this block
     * @return list<string>
     */
    private function renderWidgetBlock(Widget $widget, int $panelWidth, array &$consumed): array
    {
        $id = $this->widgetId($widget);

        if (isset($this->timelineCells[$id])) {
            $cell = $this->timelineCells[$id];
            return match ($widget->caption) {
                'Bytes In' => $this->labeledRateBlock($cell, 'Bytes In:counter', 'receiving', $panelWidth, $consumed, true),
                'Bytes Out' => $this->labeledRateBlock($cell, 'Bytes Out:counter', 'sending', $panelWidth, $consumed, true),
                'Connections' => $this->connectionsBlock($cell, $panelWidth, $consumed),
                'SQL Statements' => $this->sqlStatementsBlock($cell, $panelWidth, $consumed),
                'InnoDB Disk Writes' => $this->diskWritesBlock($cell, $panelWidth, $consumed),
                'InnoDB Disk Reads' => $this->labeledRateBlock($cell, 'InnoDB Disk Reads:counter', 'reading', $panelWidth, $consumed, false),
                default => $this->plainChartRows($cell, $panelWidth),
            };
        }

        if (isset($this->meterCells[$id])) {
            $meter = $this->meterCells[$id];
            if ($widget->caption === 'Buffer Pool Usage') {
                return $this->bufferPoolBlock($meter, $consumed);
            }
            return $this->rows($meter->view());
        }

        if (isset($this->counterCells[$id])) {
            return [$this->counterCells[$id]->view()];
        }

        return [];
    }

    /**
     * Chart plus a one-line rate label built from the paired byte-counter
     * ("receiving 12.3 kb/s" / "sending …", Workbench wording). The counter
     * widget is consumed here so it never renders as a duplicate standalone row.
     *
     * @param array<string,bool> $consumed
     * @return list<string>
     */
    private function labeledRateBlock(MultiSeriesCell $cell, string $counterId, string $verb, int $panelWidth, array &$consumed, bool $asKb): array
    {
        $lines = $this->plainChartRows($cell, $panelWidth);

        $counter = $this->counterCells[$counterId] ?? null;
        if ($counter === null) {
            return $lines;
        }
        $consumed[$counterId] = true;
        if (!$counter->hasValue()) {
            return $lines;
        }

        $lines[] = $asKb
            ? sprintf('%s %.1f kb/s', $verb, $counter->lastValue() / 1024.0)
            : sprintf('%s %s b/s', $verb, sprintf('%.0f', $counter->lastValue()));

        return $lines;
    }

    /**
     * InnoDB disk-writes frame: line graph plus the three spec labels
     * (query_dashboard.md lines 32-34: "data written xx kb/s", "writes xx
     * #/s", "writing xxx kb/s"). Both paired counters are consumed here so
     * neither renders as a duplicate standalone row; a missing counter only
     * drops its label lines, never the chart.
     *
     * @param array<string,bool> $consumed
     * @return list<string>
     */
    private function diskWritesBlock(MultiSeriesCell $cell, int $panelWidth, array &$consumed): array
    {
        $lines = $this->plainChartRows($cell, $panelWidth);

        $bytes = $this->counterCells['InnoDB Disk Writes:counter'] ?? null;
        if ($bytes !== null) {
            $consumed['InnoDB Disk Writes:counter'] = true;
        }
        $kb = $bytes !== null && $bytes->hasValue() ? $bytes->lastValue() / 1024.0 : null;
        if ($kb !== null) {
            $lines[] = sprintf('data written %.1f kb/s', $kb);
        }

        $writes = $this->counterCells['Disk Write Requests:counter'] ?? null;
        if ($writes !== null) {
            $consumed['Disk Write Requests:counter'] = true;
            if ($writes->hasValue()) {
                $lines[] = sprintf('writes %s #/s', $writes->scaledFormatted());
            }
        }

        if ($kb !== null) {
            $lines[] = sprintf('writing %.1f kb/s', $kb);
        }

        return $lines;
    }

    /**
     * Connections history chart with the current-vs-limit vertical bar on its
     * right (Workbench puts the bar in the same frame) and a threads/max
     * readout below. Consumes the 'Connections' level meter widget.
     *
     * @param array<string,bool> $consumed
     * @return list<string>
     */
    private function connectionsBlock(MultiSeriesCell $cell, int $panelWidth, array &$consumed): array
    {
        $meter = $this->meterCells['Connections:level'] ?? null;
        if ($meter === null) {
            return $this->plainChartRows($cell, $panelWidth);
        }
        $consumed['Connections:level'] = true;

        // ~10 cells for the vertical bar plus gutter; the chart takes the rest.
        $chart = implode("\n", $this->plainChartRows($cell, max(8, $panelWidth - 10)));
        $joined = Layout::joinHorizontal(Position::TOP, $chart, ' ', $meter->viewMeter(4));

        $lines = $this->rows($joined);
        if ($meter->hasValue()) {
            $lines[] = sprintf('threads %d / max %d', (int) $meter->value(), (int) $meter->max());
        }
        return $lines;
    }

    /**
     * The seven-trace statements chart plus per-verb x/s labels, in a two
     * column grid so seven labels still fit a narrow panel (Workbench lists
     * select/insert/update/delete/create/alter/drop under the graph). The
     * seven verb counters are consumed here.
     *
     * @param array<string,bool> $consumed
     * @return list<string>
     */
    private function sqlStatementsBlock(MultiSeriesCell $cell, int $panelWidth, array &$consumed): array
    {
        $lines = $this->plainChartRows($cell, $panelWidth);

        $labels = [];
        foreach (['select', 'insert', 'update', 'delete', 'create', 'alter', 'drop'] as $verb) {
            $counterId = strtoupper($verb) . ':counter';
            $counter = $this->counterCells[$counterId] ?? null;
            if ($counter === null) {
                continue;
            }
            $consumed[$counterId] = true;
            $labels[] = sprintf('%-7s %s', $verb, $counter->hasValue() ? $counter->scaledFormatted() : '0');
        }

        foreach (array_chunk($labels, 2) as $pair) {
            $lines[] = implode('  ', $pair);
        }

        return $lines;
    }

    /**
     * Buffer pool donut (usage % in the hole) with the read-reqs / write-reqs /
     * disk-reads per-second labels underneath, mirroring the counter column
     * Workbench docks beside DBRoundMeter. Consumes those three counters.
     *
     * @param array<string,bool> $consumed
     * @return list<string>
     */
    private function bufferPoolBlock(MeterCell $meter, array &$consumed): array
    {
        $lines = $this->rows($meter->viewRound());

        foreach ([
            ['Buffer Pool Read Reqs', 'read reqs %s pages/s'],
            ['Buffer Pool Write Reqs', 'write reqs %s pages/s'],
            ['Disk Reads (not from pool)', 'disk reads %s /s'],
        ] as [$caption, $format]) {
            $counterId = $caption . ':counter';
            $counter = $this->counterCells[$counterId] ?? null;
            if ($counter === null) {
                continue;
            }
            $consumed[$counterId] = true;
            $lines[] = sprintf($format, $counter->hasValue() ? $counter->scaledFormatted() : '0');
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function plainChartRows(MultiSeriesCell $cell, int $panelWidth): array
    {
        return $this->rows($cell->view($panelWidth));
    }

    /**
     * Split a multi-row renderer (chart, donut, joined columns) into panel rows.
     *
     * @return list<string>
     */
    private function rows(string $rendered): array
    {
        return explode("\n", rtrim($rendered, "\n"));
    }

    /**
     * @return list<Widget>
     */
    private function getWidgetsForSection(string $section): array
    {
        return $this->sectionWidgetCache[$section] ?? [];
    }

    private function renderHeader(): string
    {
        $version = $this->context->versionString();
        $status = $this->paused ? ' [PAUSED]' : '';

        if ($this->isPostgres) {
            return sprintf(
                "Performance Dashboard%s | PostgreSQL %s | Uptime: N/A\n",
                $status,
                $version,
            );
        }

        $uptime = $this->context->statusVariables()['Uptime'] ?? '0';
        $uptimeStr = Format::duration((float) $uptime);

        return sprintf(
            "Performance Dashboard%s | MySQL %s | Uptime: %s\n",
            $status,
            $version,
            $uptimeStr,
        );
    }

    private function renderFooter(): string
    {
        $shortcuts = '[p] pause  [r] reset';

        if ($this->pendingAlerts !== []) {
            $count = count($this->pendingAlerts);
            $alertLabel = Style::new()
                ->foreground(Color::hex('#f59e0b'))
                ->bold()
                ->render("[!] {$count} alert" . ($count !== 1 ? 's' : ''));
            $shortcuts .= '  ' . $alertLabel . '  [a] dismiss';
        }

        return Style::new()->foreground(Color::hex('#6b7280'))->render($shortcuts);
    }

    private function assembleLayout(
        string $header,
        string $network,
        string $mysql,
        string $innodb,
        string $queryLog,
        string $footer,
    ): string {
        // renderPanel pads every column to the region height, so a separator
        // sized to the tallest column spans the whole body. Sprinkles\Layout
        // joins the columns ANSI-width-aware (and aligns the dividers into a
        // straight vertical rule, which the old per-row concat did not).
        $contentHeight = max(
            substr_count($network, "\n"),
            substr_count($mysql, "\n"),
            substr_count($innodb, "\n"),
        ) + 1;

        $sepLine = ' ' . Style::new()->foreground(Color::hex('#22d3ee'))->render('│') . ' ';
        $separator = implode("\n", array_fill(0, $contentHeight, $sepLine));

        $body = Layout::joinHorizontal(Position::TOP, $network, $separator, $mysql, $separator, $innodb);

        return implode("\n", array_merge(
            explode("\n", $header),
            explode("\n", $body),
            [''],
            explode("\n", $queryLog),
            explode("\n", $footer),
        ));
    }

    public function withTogglePause(): self
    {
        $clone = clone $this;
        $clone->paused = !$clone->paused;
        return $clone;
    }

    public function withPaused(bool $paused): self
    {
        if ($this->paused === $paused) {
            return $this;
        }
        $clone = clone $this;
        $clone->paused = $paused;
        return $clone;
    }

    public function withReset(): self
    {
        $clone = clone $this;
        foreach ($clone->timelineCells as $cell) {
            $cell->reset();
        }
        foreach ($clone->counterCells as $cell) {
            $cell->reset();
        }
        foreach ($clone->meterCells as $cell) {
            $cell->reset();
        }
        $clone->previousSnapshot = null;
        $clone->lastPollAt = null;
        $clone->lastSnapshotAt = null;
        return $clone;
    }

    public function withClearAlerts(): self
    {
        $clone = clone $this;
        $clone->pendingAlerts = [];
        // Also reset breach tracking so new breaches on the same keys re-fire toasts.
        $clone->breachedAlertKeys = [];
        return $clone;
    }

    /**
     * Return a new DashboardPage with the given alert notifier.
     *
     * Use this to enable toast notifications by providing a notifier
     * with a Toast factory:
     *
     *   $notifier = AlertNotifier::withDefaults(muted: false);
     *   $page = $page->withAlertNotifier($notifier);
     */
    public function withAlertNotifier(AlertNotifierInterface $notifier): self
    {
        $clone = clone $this;
        $clone->alertNotifier = $notifier;
        return $clone;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * @return array<string, Alert>
     */
    public function pendingAlerts(): array
    {
        return $this->pendingAlerts;
    }

    public function alertCount(): int
    {
        return count($this->pendingAlerts);
    }

    public function alertNotifier(): AlertNotifierInterface
    {
        return $this->alertNotifier;
    }

}
