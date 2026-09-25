<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\Card\DefinitionList;
use SugarCraft\Query\Admin\AsyncCachingServerContext;
use SugarCraft\Query\Admin\CacheTtl;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\Sampler;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Core\Msg\ReloadReportMsg;
use SugarCraft\Query\Renderer;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

/**
 * Liveness of the admin data feed backing the Server Status page.
 *
 * Mirrors the green play-arrow / grey stop-square header MySQL Workbench
 * shows above Management :: Server Status (query_dashboard.md line 45):
 * the triangle means the monitor thread is still landing samples, the
 * square means it is not.
 */
enum RunState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Unreachable = 'unreachable';
}

/**
 * Server Status page displaying connection info, features, directories, SSL, replication, and firewall.
 *
 * Provides a comprehensive overview of the MySQL/MariaDB server configuration
 * and runtime state. Each category is a sugar-dash Card wrapping a
 * DefinitionList; boolean features render as Badge::bool() instead of
 * hand-rolled Yes/No ANSI.
 *
 * Keyboard shortcuts:
 *   [r] - refresh data
 *   [q] - quit to previous view
 *
 * @see Mirrors mysql-workbench/wb_admin_server_status
 */
final class ServerStatusPage extends PageBase
{
    /**
     * A sample younger than 3 × the 1 s admin cadence means the fetch loop is
     * still landing data; anything older is treated as a stopped feed. Three
     * full cadences of slack so one slow SHOW GLOBAL STATUS (network blip,
     * lock on the server) does not flicker the Running badge to Stopped.
     */
    private const RUNNING_FRESHNESS_SECONDS = 3 * CacheTtl::DASHBOARD;

    private ?ReplicaStatusProvider $replicaProvider = null;
    private ?Sampler $sampler = null;
    private ?MetricsColumn $column = null;

    /** @var ReplicaStatusKind::*|null */
    private string|null $gtidModeCurrent = null;
    private bool $gtidDialog = false;
    private string $gtidModeEdit = '';
    /**
     * One-line failure text from the last SET @@GLOBAL.GTID_MODE attempt.
     * Non-empty keeps the dialog open (the user stays where the fix is —
     * cycle to another whitelisted mode or cancel) and renders under the
     * dialog line. Empty means no pending error.
     */
    private string $gtidError = '';

    public function __construct(
        ServerContextInterface $context,
        ?ReplicaStatusProvider $replicaProvider = null,
        ?Sampler $sampler = null,
    ) {
        parent::__construct($context);
        $this->replicaProvider = $replicaProvider ?? ReplicaStatusProvider::new($context);
        $this->sampler = $sampler;
    }

    /**
     * Create a new ServerStatusPage from the server context.
     */
    public static function new(ServerContextInterface $context, ?Sampler $sampler = null): self
    {
        $page = new self($context, null, $sampler);
        // First build polls to prime the sampler with the initial snapshot
        // and seed the CPU/Load + graph windows (same role gaugeSet played).
        $page->column = MetricsColumn::forContext($context, $sampler);
        $page->column->poll();
        return $page;
    }

    /**
     * Verify we can query status variables before rendering.
     */
    protected function validate(): bool
    {
        try {
            $vars = $this->context->statusVariables();
            return count($vars) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Render the page, keeping the Workbench liveness label visible even
     * when there is no data to build the panels from: Workbench greys the
     * whole Server Status tab but still shows the stopped indicator, and
     * without the header the user cannot tell "connection died" from
     * "page still loading".
     */
    public function view(): string
    {
        // First async fetch in flight with nothing cached: loading screen,
        // same door PageBase uses — a loading pane must not read as dead.
        if ($this->context instanceof AsyncCachingServerContext
            && $this->context->isLoading()
            && !$this->context->hasCachedData()) {
            return $this->loadingScreen();
        }

        if (!$this->validate()) {
            return $this->runLabel(RunState::Unreachable) . "\n" . $this->errorScreen();
        }

        return $this->build();
    }

    /**
     * Liveness of the admin fetch feeding this page.
     *
     * Derived purely from cached state — the timestamp ServerContext stamps
     * on the last SHOW GLOBAL STATUS — so no extra message or subscription
     * is needed: every App re-render (each landed AdminDataLoadedMsg)
     * re-evaluates it against the current clock.
     */
    private function runState(float $now): RunState
    {
        try {
            $vars = $this->context->statusVariables();
        } catch (\Throwable) {
            return RunState::Unreachable;
        }

        if ($vars === []) {
            return RunState::Unreachable;
        }

        $age = $now - $this->context->statusVariablesTs();
        return $age <= self::RUNNING_FRESHNESS_SECONDS
            ? RunState::Running
            : RunState::Stopped;
    }

    /**
     * Play-triangle + word label (query_dashboard.md line 45), severity
     * colored: green while samples land, amber when the feed stalled, red
     * when no sample has ever arrived.
     */
    private function runLabel(RunState $state): string
    {
        [$glyph, $word, $hex] = match ($state) {
            RunState::Running => ['▶', 'Running', '#a6e3a1'],
            RunState::Stopped => ['■', 'Stopped', '#fbbf24'],
            RunState::Unreachable => ['✖', 'Unreachable', '#f38ba8'],
        };

        return Style::new()
            ->bold()
            ->foreground(Color::hex($hex))
            ->render($glyph . ' ' . $word);
    }

    /**
     * Build the complete status page output.
     */
    protected function build(): string
    {
        // Left panel: existing info panels stacked vertically
        $leftPanel = $this->buildLeftPanel();

        // Right panel: live metric column (CPU/Load first, then the Workbench
        // graph frames — replaces the five-gauge sidebar rendering). Width is
        // whatever the terminal leaves beside the info panels, so graphs never
        // push the layout past the viewport.
        $column = $this->column ?? MetricsColumn::forContext($this->context, $this->sampler);
        $size = Renderer::getTerminalSize();
        $leftWidth = 0;
        foreach (explode("\n", $leftPanel) as $line) {
            $leftWidth = max($leftWidth, Width::of($line));
        }
        $rightWidth = max(22, min(72, ($size['cols'] ?? 120) - $leftWidth - 2));
        $rightPanel = $column->view($rightWidth, (int) ($size['rows'] ?? 24));

        // 2-column layout: info panels on left, metric column on right
        return Layout::joinHorizontal(Position::TOP, $leftPanel, '  ', $rightPanel);
    }

    /**
     * Build the left panel content (existing single-column layout).
     */
    private function buildLeftPanel(): string
    {
        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = ServerInfoCard::new($this->context)->render();
        $lines[] = '';
        $lines[] = $this->renderFeaturesPanel();
        $lines[] = '';
        $lines[] = $this->renderDirectoryPanel();
        $lines[] = '';
        $lines[] = $this->renderSslPanel();
        $lines[] = '';
        $lines[] = $this->renderReplicaPanel();
        $lines[] = '';
        $lines[] = $this->renderFirewallPanel();

        // The GTID dialog is a modal editing surface while open: it must be
        // visible (its state was previously key-handled with no render at
        // all, so neither the mode being edited nor a failure could ever be
        // seen).
        if ($this->gtidDialog) {
            $lines[] = '';
            $lines[] = $this->renderGtidDialogPanel();
        }

        $lines[] = '';
        $lines[] = $this->renderFooter();

        return implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for refresh, quit, and GTID mode selector.
     */
    public function update(\SugarCraft\Core\Msg $msg): array
    {
        if ($msg instanceof ReloadReportMsg) {
            // App forwards this whenever fresh admin data lands in the shared
            // cache (same arm DashboardPage carries): adopt the live values and
            // bypass the 1s poll throttle so the graphs move on the tick that
            // delivered data, not the next one.
            if ($this->context instanceof AsyncCachingServerContext) {
                $this->context->refreshFromLiveCache();
            }
            $this->column?->forcePoll();
            $this->column?->poll();
            return [$this, null];
        }
        if (!$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';

        // GTID dialog takes priority when active
        if ($this->gtidDialog) {
            return $this->updateGtidDialog($msg);
        }

        return match (true) {
            $ch === 'r' => [$this->withRefresh(), null],
            $ch === 'q' => [$this->withQuit(), null],
            $ch === 'g' => $this->withGtidDialog(),
            default => [$this, null],
        };
    }

    // ─── Panel Renderers ─────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $version = $this->context->versionString();
        $flavor = $this->context->flavor();

        $title = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Server Status');

        // Workbench puts the play-arrow liveness label ABOVE the tab title
        // (query_dashboard.md line 45), so it reads as the page's status
        // light rather than part of the connection banner.
        return $this->runLabel($this->runState(microtime(true))) . "\n" . sprintf(
            '%s | %s %s | %s',
            $title,
            $flavor->value,
            $version,
            date('Y-m-d H:i:s'),
        );
    }

    private function renderFeaturesPanel(): string
    {
        $serverVars = $this->context->serverVariables();

        $list = DefinitionList::new()->withRows([
            ['InnoDB', $this->tristate($this->hasInnodb())],
            ['SSL', $this->tristate($this->hasSsl($serverVars))],
            ['Fulltext', $this->tristate($this->hasFulltext($serverVars))],
            ['Events', $this->tristate($this->hasEvents($serverVars))],
            ['Stored Programs', $this->tristate($this->hasStoredPrograms())],
            ['Partitioning', $this->tristate($this->hasPartitioning($serverVars))],
            ['X Plugin', $this->tristate($this->hasXPlugin($serverVars))],
        ]);

        return Card::titled($list, 'Features')->render();
    }

    private function renderDirectoryPanel(): string
    {
        $serverVars = $this->context->serverVariables();

        $list = DefinitionList::new()
            ->withPlaceholder('-')
            ->withRows([
                ['Data Directory', $this->resolveDir($serverVars['datadir'] ?? null)],
                ['Temp Directory', $this->resolveDir($serverVars['tmpdir'] ?? null)],
                ['Log Directory', $this->resolveDir($serverVars['log_error'] ?? null)],
                ['PID File', $this->resolveDir($serverVars['pid_file'] ?? null)],
            ]);

        return Card::titled($list, 'Directories')->render();
    }

    private function renderSslPanel(): string
    {
        $serverVars = $this->context->serverVariables();

        $list = DefinitionList::new()
            ->withPlaceholder('-')
            ->withRows([
                ['SSL Enabled', $this->tristate($this->hasSsl($serverVars))],
                ['SSL Cipher', $this->resolveValue($serverVars['ssl_cipher'] ?? null)],
                ['TLS Version', $this->resolveValue($serverVars['tls_version'] ?? null)],
                ['Have SSL', $this->tristate($this->tristateValue($serverVars['have_ssl'] ?? null))],
                ['SSL CA', $this->resolveDir($serverVars['ssl_ca'] ?? null)],
                ['SSL Cert', $this->resolveDir($serverVars['ssl_cert'] ?? null)],
                ['SSL Key', $this->resolveDir($serverVars['ssl_key'] ?? null)],
            ]);

        return Card::titled($list, 'SSL / Secure Connection')->render();
    }

    private function renderReplicaPanel(): string
    {
        $kind = $this->replicaProvider->lastFetchKind();
        $rows = $this->replicaProvider->fetchStatus();

        // Distinct states for the replica panel
        if ($kind === ReplicaStatusKind::PermissionDenied) {
            return Card::titled('Insufficient privileges (REPLICATION CLIENT)', 'Replication')->render();
        }

        if ($kind === ReplicaStatusKind::Error) {
            return Card::titled('Error accessing replication status', 'Replication')->render();
        }

        if ($kind === ReplicaStatusKind::NotConfigured || count($rows) === 0) {
            return Card::titled('Not configured', 'Replication')->render();
        }

        // Build a card per channel (multi-channel support)
        $cards = [];
        foreach ($rows as $channelRow) {
            $list = DefinitionList::new()
                ->withPlaceholder('-')
                ->withRows([
                    ['Channel', $this->resolveValue($channelRow['Channel_name'] ?? $channelRow['Connection_name'] ?? null)],
                    ['Master Host', $this->resolveValue($channelRow['Master_Host'] ?? $channelRow['Source_Host'] ?? null)],
                    ['Master Port', $this->resolveValue($channelRow['Master_Port'] ?? $channelRow['Source_Port'] ?? null)],
                    ['Slave IO Running', $this->replicaState($channelRow['Slave_IO_Running'] ?? $channelRow['Replica_IO_Running'] ?? null)],
                    ['Slave SQL Running', $this->replicaState($channelRow['Slave_SQL_Running'] ?? $channelRow['Replica_SQL_Running'] ?? null)],
                    ['Seconds Behind', $this->secondsBehind($channelRow['Seconds_Behind_Master'] ?? $channelRow['Seconds_Behind_Source'] ?? null)],
                    ['Relay Log File', $this->resolveValue($channelRow['Relay_Log_File'] ?? null)],
                    ['Relay Pos', $this->resolveValue($channelRow['Relay_Log_Pos'] ?? null)],
                ]);

            $channelLabel = $channelRow['Channel_name'] ?? $channelRow['Connection_name'] ?? 'Default';
            $cards[] = Card::titled($list, "Replication › {$channelLabel}")->render();
        }

        return implode("\n\n", $cards);
    }

    private function renderFirewallPanel(): string
    {
        $serverVars = $this->context->serverVariables();

        $hasFirewall = $this->hasFirewall($serverVars);

        $list = DefinitionList::new()->withRows([
            ['MySQL Firewall', $this->tristate($hasFirewall)],
        ]);

        return Card::titled($list, 'Firewall')->render();
    }

    /**
     * One visible block for the open GTID-mode dialog: the mode under edit +
     * key hints, and the last failure line when the SET was refused.
     */
    private function renderGtidDialogPanel(): string
    {
        $line = Style::new()->bold()->foreground(Color::hex('#cba6f0'))->render(
            sprintf('GTID_MODE [%s]   c: cycle   Enter: apply   Esc: cancel', $this->gtidModeEdit),
        );

        if ($this->gtidError === '') {
            return $line;
        }

        return $line . "\n" . Style::new()->foreground(Color::hex('#f38ba8'))->render(
            'Error: ' . $this->gtidError,
        );
    }

    private function renderFooter(): string
    {
        return Style::new()->foreground(Color::hex('#6b7280'))->render('[r] refresh  [q] quit');
    }

    // ─── Tristate Helper ─────────────────────────────────────────────────

    /**
     * Convert bool|string|null to a Yes/No/Unknown badge.
     *
     * Used for features that may be present, absent, or unknown
     * (e.g., from server variables that may not be set). Delegates to
     * Badge::bool() so the Yes/No/Unknown styling lives in sugar-dash.
     *
     * @param bool|string|null $value
     */
    public function tristate(bool|string|null $value): string
    {
        return Badge::bool($this->toBool($value))->render();
    }

    /**
     * Normalize the loose tristate input to a strict ?bool.
     */
    private function toBool(bool|string|null $value): ?bool
    {
        if ($value === true || $value === 'YES' || $value === 'ON') {
            return true;
        }

        if ($value === false || $value === 'NO' || $value === 'OFF') {
            return false;
        }

        return null;
    }

    /**
     * Convert a server variable value to tristate format.
     */
    private function tristateValue(?string $value): bool|string|null
    {
        if ($value === null) {
            return null;
        }

        $lower = strtolower($value);
        if ($lower === 'yes' || $lower === 'on' || $value === '1') {
            return true;
        }

        if ($lower === 'no' || $lower === 'off' || $value === '0') {
            return false;
        }

        return null;
    }

    // ─── Value Resolution Helpers ────────────────────────────────────────

    /**
     * Resolve a nullable string to a displayable value, or null so the
     * DefinitionList shows its placeholder.
     */
    private function resolveValue(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * Resolve a directory path to a displayable value (abbreviating long
     * paths), or null so the DefinitionList shows its placeholder.
     */
    private function resolveDir(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Abbreviate long paths while keeping them readable
        if (strlen($value) > 40) {
            $value = '...' . substr($value, -37);
        }

        return $value;
    }

    /**
     * Render replica IO/SQL running state as a badge that keeps the raw
     * state text (Yes/No/Connecting) as its label.
     */
    private function replicaState(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $bool = match (strtolower($state)) {
            'yes', 'connecting' => true,
            'no' => false,
            default => null,
        };

        return Badge::bool($bool, yes: $state, no: $state, unknown: $state)->render();
    }

    /**
     * Render seconds behind master with a human-readable duration.
     */
    private function secondsBehind(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $seconds = (int) $value;
        if ($seconds === 0) {
            return '0s (caught up)';
        }

        return Format::duration($seconds);
    }

    // ─── Feature Detection ──────────────────────────────────────────────

    /**
     * True when InnoDB storage engine is available.
     */
    private function hasInnodb(): bool
    {
        $plugins = $this->context->plugins();
        foreach ($plugins as $plugin) {
            if (($plugin['Name'] ?? '') === 'innodb') {
                return true;
            }
        }

        // Fallback: check if InnoDB variables are set
        $serverVars = $this->context->serverVariables();
        return isset($serverVars['innodb_buffer_pool_size']);
    }

    /**
     * True when SSL is available (have_ssl = YES or ssl_cipher is set).
     */
    private function hasSsl(array $serverVars): bool
    {
        $haveSsl = $serverVars['have_ssl'] ?? null;
        if (strtolower((string) $haveSsl) === 'yes') {
            return true;
        }

        $cipher = $serverVars['ssl_cipher'] ?? null;
        return $cipher !== null && $cipher !== '';
    }

    /**
     * True when FULLTEXT indexing is available (MySQL 5.6+).
     *
     * Uses $serverVars to check ft_max_word_len / ft_min_word_len as a
     * proxy for fulltext support being compiled in (these vars are only
     * present when the feature is available).
     */
    private function hasFulltext(array $serverVars): bool
    {
        $version = $this->context->version();
        if (!$version->isAtLeast(5, 6)) {
            return false;
        }

        // These variables only exist when FULLTEXT is compiled in
        return isset($serverVars['ft_max_word_len']) || isset($serverVars['ft_min_word_len']);
    }

    /**
     * True when events scheduler is enabled.
     */
    private function hasEvents(array $serverVars): bool
    {
        $events = $serverVars['event_scheduler'] ?? null;
        return strtolower((string) $events) === 'on';
    }

    /**
     * True when stored procedures or functions are present.
     *
     * Detected by querying information_schema.ROUTINES (supports all dialects;
     * on MariaDB or older MySQL the table exists and the count query is cheap).
     */
    private function hasStoredPrograms(): bool
    {
        try {
            $connection = $this->context->connection();
            $result = $connection->query(
                'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN (\'information_schema\', \'performance_schema\', \'mysql\') LIMIT 1'
            );
            return count($result) > 0 && ($result[0]['COUNT(*)'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True when table partitioning is available.
     *
     * Partitioning is compiled in by default from MySQL 5.1 onward. The
     * have_partitioning server variable (when present and OFF) indicates
     * it was disabled at build time, but in practice it is always available.
     * We use the server version + optionally have_partitioning var.
     */
    private function hasPartitioning(array $serverVars): bool
    {
        $version = $this->context->version();
        if (!$version->isAtLeast(5, 1)) {
            return false;
        }

        // have_partitioning = YES means built-in; OFF means disabled at build
        $have = $serverVars['have_partitioning'] ?? null;
        if ($have !== null) {
            return strtoupper($have) === 'YES';
        }

        return true; // 5.1+ by default has partitioning compiled in
    }

    /**
     * True when X Plugin (MySQL Document Store) is enabled.
     */
    private function hasXPlugin(array $serverVars): bool
    {
        $plugins = $this->context->plugins();
        foreach ($plugins as $plugin) {
            if (($plugin['Name'] ?? '') === 'mysqlx') {
                return true;
            }
        }

        $port = $serverVars['mysqlx_port'] ?? null;
        return $port !== null && (int) $port > 0;
    }

    /**
     * True when the MySQL Enterprise Firewall or audit plugin is active.
     *
     * Detected via mysql_firewall_mode status variable (MySQL Enterprise)
     * or by checking for an active AUDIT plugin that provides firewall
     * functionality on some managed/cloud instances.
     *
     * @param array<string, string> $serverVars
     */
    private function hasFirewall(array $serverVars): bool
    {
        // MySQL Enterprise Firewall sets this status variable when active
        $fwMode = $serverVars['mysql_firewall_mode'] ?? null;
        if ($fwMode !== null) {
            return strtoupper($fwMode) === 'ON';
        }

        // Fallback: check for audit plugin on managed/cloud instances
        // (some cloud providers expose firewall via audit plugin presence)
        $plugins = $this->context->plugins();
        foreach ($plugins as $plugin) {
            $name = strtolower($plugin['Name'] ?? '');
            // AUDIT plugin is used by some cloud firewall implementations
            if ($name === 'audit' || $name === 'firewall') {
                return true;
            }
        }

        return false;
    }

    // ─── Immutable Mutations ────────────────────────────────────────────

    /**
     * Return a refreshed instance.
     *
     * Polls the gauge set to advance the sampler, so the next render uses
     * per-second rate data from two distinct snapshots. The context reference
     * is shared (readonly), so calling refresh() on it mutates the shared state.
     */
    public function withRefresh(): self
    {
        $clone = clone $this;
        $clone->context->refresh();
        $clone->replicaProvider = $this->replicaProvider->refresh();
        // Advance the metric column: fresh data just landed, so skip the
        // throttle and take the sample immediately.
        $clone->column ??= MetricsColumn::forContext($clone->context, $clone->sampler);
        $clone->column->forcePoll();
        $clone->column->poll();
        return $clone;
    }

    /**
     * Return a clone (quit is handled by the parent controller).
     */
    public function withQuit(): self
    {
        return clone $this;
    }

    /**
     * Open the GTID mode selector dialog.
     *
     * Only meaningful on MySQL ≥ 5.7.6 where GTIDs are available.
     * Initializes the edit value from the current server GTID_MODE setting.
     */
    private function withGtidDialog(): array
    {
        $version = $this->context->version();
        if (!$version->isAtLeast(5, 7, 6)) {
            return [$this, null]; // GTID not available
        }

        $clone = clone $this;
        $clone->gtidDialog = true;
        $clone->gtidError = ''; // a reopened dialog starts clean, not on last failure's error
        // Initialize edit value from current server GTID_MODE
        $current = $this->context->serverVariables()['gtid_mode'] ?? 'OFF';
        $clone->gtidModeCurrent = $current;
        $clone->gtidModeEdit = $current;
        return [$clone, null];
    }

    /**
     * Handle keyboard input when the GTID dialog is active.
     *
     * Keys:
     *   c  — cycle to next GTID_MODE in the whitelist
     *   Enter — execute SET @@GLOBAL.GTID_MODE = <mode>
     *   Escape — cancel and close dialog
     */
    private function updateGtidDialog(\SugarCraft\Core\Msg\KeyMsg $msg): array
    {
        $ch = $msg->rune ?? '';

        if ($msg->type === \SugarCraft\Core\KeyType::Escape) {
            $clone = clone $this;
            $clone->gtidDialog = false;
            $clone->gtidError = '';
            return [$clone, null];
        }

        if ($ch === 'c') {
            // Cycle to next mode in the whitelist
            $modes = GtidMode::values();
            $currentIdx = array_search($this->gtidModeEdit, array_column($modes, 'value'), true);
            $nextIdx = ($currentIdx === false ? 0 : ($currentIdx + 1) % count($modes));
            $clone = clone $this;
            $clone->gtidModeEdit = $modes[$nextIdx]->value;
            return [$clone, null];
        }

        if ($msg->type === \SugarCraft\Core\KeyType::Enter) {
            // Execute the GTID_MODE change
            $mode = $this->gtidModeEdit;
            // GTID_MODE is always an identifier (whitelist), not user free-text
            $connection = $this->context->connection();
            try {
                $connection->exec("SET @@GLOBAL.GTID_MODE = {$mode}");
            } catch (\Throwable $e) {
                // Admin mutations must never fail silently: an earlier shape
                // closed the dialog on failure behind a comment claiming the
                // user could read the error — they could not. Keep the dialog
                // open (the target mode is still on screen) and surface a
                // flattened one-line reason, same "Error: <msg>" idiom the
                // other admin pages use (PageBase::errorScreen, PerfSchema).
                $clone = clone $this;
                $reason = (string) preg_replace('/\s+/u', ' ', $e->getMessage());
                $clone->gtidError = 'GTID_MODE = ' . $mode . ': ' . mb_substr(trim($reason), 0, 160);
                return [$clone, null];
            }
            $clone = clone $this;
            $clone->gtidDialog = false;
            $clone->gtidError = '';
            // No explicit refresh here: the admin tick lands the new
            // gtid_mode within its 1s cadence, same as every other change.
            return [$clone, null];
        }

        return [$this, null];
    }

    // ─── Accessors ───────────────────────────────────────────────────────

    public function replicaProvider(): ReplicaStatusProvider
    {
        return $this->replicaProvider;
    }
}
