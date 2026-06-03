<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\Card\DefinitionList;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Sprinkles\Layout;
use SugarCraft\Sprinkles\Position;
use SugarCraft\Sprinkles\Style;

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
    private ?ReplicaStatusProvider $replicaProvider = null;

    public function __construct(
        ServerContextInterface $context,
        ?ReplicaStatusProvider $replicaProvider = null,
    ) {
        parent::__construct($context);
        $this->replicaProvider = $replicaProvider ?? ReplicaStatusProvider::new($context);
    }

    /**
     * Create a new ServerStatusPage from the server context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
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
     * Build the complete status page output.
     */
    protected function build(): string
    {
        // Left panel: existing info panels stacked vertically
        $leftPanel = $this->buildLeftPanel();

        // Right panel: live gauge sidebar
        $gaugeSet = SidebarGaugeSet::new($this->context);
        $rightPanel = $gaugeSet->view();

        // 2-column layout: info panels on left, gauges on right
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
        $lines[] = '';
        $lines[] = $this->renderFooter();

        return implode("\n", $lines);
    }

    /**
     * Handle keyboard shortcuts for refresh and quit.
     */
    public function update(\SugarCraft\Core\Msg $msg): array
    {
        if (!$msg instanceof \SugarCraft\Core\Msg\KeyMsg) {
            return [$this, null];
        }

        $ch = $msg->rune ?? '';

        return match (true) {
            $ch === 'r' => [$this->withRefresh(), null],
            $ch === 'q' => [$this->withQuit(), null],
            default => [$this, null],
        };
    }

    // ─── Panel Renderers ─────────────────────────────────────────────────

    private function renderHeader(): string
    {
        $version = $this->context->versionString();
        $flavor = $this->context->flavor();

        $title = Style::new()->bold()->foreground(Color::hex('#22d3ee'))->render('Server Status');

        return sprintf(
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
            ['Stored Programs', $this->tristate($this->hasStoredPrograms($serverVars))],
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
        $replicaStatus = $this->replicaProvider->fetchStatus();

        if ($replicaStatus === null || count($replicaStatus) === 0) {
            return Card::titled('Not configured or not accessible', 'Replication')->render();
        }

        $list = DefinitionList::new()
            ->withPlaceholder('-')
            ->withRows([
                ['Master Host', $this->resolveValue($replicaStatus['Master_Host'] ?? $replicaStatus['Source_Host'] ?? null)],
                ['Master Port', $this->resolveValue($replicaStatus['Master_Port'] ?? $replicaStatus['Source_Port'] ?? null)],
                ['Slave IO Running', $this->replicaState($replicaStatus['Slave_IO_Running'] ?? $replicaStatus['Replica_IO_Running'] ?? null)],
                ['Slave SQL Running', $this->replicaState($replicaStatus['Slave_SQL_Running'] ?? $replicaStatus['Replica_SQL_Running'] ?? null)],
                ['Seconds Behind', $this->secondsBehind($replicaStatus['Seconds_Behind_Master'] ?? $replicaStatus['Seconds_Behind_Source'] ?? null)],
                ['Relay Log File', $this->resolveValue($replicaStatus['Relay_Log_File'] ?? null)],
                ['Relay Pos', $this->resolveValue($replicaStatus['Relay_Log_Pos'] ?? null)],
            ]);

        return Card::titled($list, 'Replication')->render();
    }

    private function renderFirewallPanel(): string
    {
        // Firewall status is typically only available on managed cloud instances;
        // degrade gracefully when the Aurora marker variable is absent.
        $statusVars = $this->context->statusVariables();
        $hasFirewall = isset($statusVars['Aurora_lwm']);

        $list = DefinitionList::new()->withRows([
            ['AWS RDS Firewall', $this->tristate($hasFirewall)],
        ]);

        return Card::titled($list, 'Firewall (AWS RDS compat.)')->render();
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
     */
    private function hasFulltext(array $serverVars): bool
    {
        $version = $this->context->version();
        return $version->isAtLeast(5, 6);
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
     * True when stored procedures/functions are present.
     *
     * Detected by checking if routine-related status variables are non-zero.
     */
    private function hasStoredPrograms(array $serverVars): bool
    {
        $statusVars = $this->context->statusVariables();
        $procs = $statusVars['Procedures'] ?? $statusVars['Functions'] ?? '0';
        return (int) $procs > 0;
    }

    /**
     * True when table partitioning is available.
     */
    private function hasPartitioning(array $serverVars): bool
    {
        // Partitioning is available in MySQL 5.1+ and always compiled in
        $version = $this->context->version();
        return $version->isAtLeast(5, 1);
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

    // ─── Immutable Mutations ────────────────────────────────────────────

    /**
     * Return a refreshed instance.
     *
     * Note: The context reference is shared (readonly), so calling refresh()
     * on it mutates the shared state. This is intentional since the context
     * is also shared in the clone via the reference.
     */
    public function withRefresh(): self
    {
        $clone = clone $this;
        $clone->context->refresh();
        $clone->replicaProvider = $this->replicaProvider->refresh();
        return $clone;
    }

    /**
     * Return a clone (quit is handled by the parent controller).
     */
    public function withQuit(): self
    {
        return clone $this;
    }

    // ─── Accessors ───────────────────────────────────────────────────────

    public function replicaProvider(): ReplicaStatusProvider
    {
        return $this->replicaProvider;
    }
}
