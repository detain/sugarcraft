<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\PageBase;
use SugarCraft\Query\Admin\ServerContextInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * Server Status page displaying connection info, features, directories, SSL, replication, and firewall.
 *
 * Provides a comprehensive overview of the MySQL/MariaDB server configuration
 * and runtime state. Uses a card-based layout with distinct panels for each
 * category of information.
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
        $infoCard = ServerInfoCard::new($this->context)->render();

        $lines = [];

        $lines[] = $this->renderHeader();
        $lines[] = '';
        $lines[] = $infoCard;
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

        return sprintf(
            "\x1b[1;36mServer Status\x1b[0m | %s %s | %s",
            $flavor->value,
            $version,
            date('Y-m-d H:i:s'),
        );
    }

    private function renderFeaturesPanel(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mFeatures\x1b[0m";
        $lines[] = $this->renderSeparator();

        $serverVars = $this->context->serverVariables();

        $features = [
            ' InnoDB' => $this->tristate($this->hasInnodb()),
            ' SSL' => $this->tristate($this->hasSsl($serverVars)),
            ' Fulltext' => $this->tristate($this->hasFulltext($serverVars)),
            ' Events' => $this->tristate($this->hasEvents($serverVars)),
            ' Stored Programs' => $this->tristate($this->hasStoredPrograms($serverVars)),
            ' Partitioning' => $this->tristate($this->hasPartitioning($serverVars)),
            ' X Plugin' => $this->tristate($this->hasXPlugin($serverVars)),
        ];

        foreach ($features as $name => $status) {
            $lines[] = sprintf("  %-20s %s", $name, $status);
        }

        return implode("\n", $lines);
    }

    private function renderDirectoryPanel(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mDirectories\x1b[0m";
        $lines[] = $this->renderSeparator();

        $serverVars = $this->context->serverVariables();

        $directories = [
            'Data Directory' => $this->resolveDir($serverVars['datadir'] ?? null),
            'Temp Directory' => $this->resolveDir($serverVars['tmpdir'] ?? null),
            'Log Directory' => $this->resolveDir($serverVars['log_error'] ?? null),
            'PID File' => $this->resolveDir($serverVars['pid_file'] ?? null),
        ];

        foreach ($directories as $name => $value) {
            $lines[] = sprintf("  %-20s \x1b[37m%s\x1b[0m", $name, $value);
        }

        return implode("\n", $lines);
    }

    private function renderSslPanel(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mSSL / Secure Connection\x1b[0m";
        $lines[] = $this->renderSeparator();

        $serverVars = $this->context->serverVariables();
        $statusVars = $this->context->statusVariables();

        $sslInfo = [
            ' SSL Enabled' => $this->tristate($this->hasSsl($serverVars)),
            ' SSL Cipher' => $this->resolveValue($serverVars['ssl_cipher'] ?? null),
            ' TLS Version' => $this->resolveValue($serverVars['tls_version'] ?? null),
            ' Have SSL' => $this->tristate($this->tristateValue($serverVars['have_ssl'] ?? null)),
            ' SSL CA' => $this->resolveDir($serverVars['ssl_ca'] ?? null),
            ' SSL Cert' => $this->resolveDir($serverVars['ssl_cert'] ?? null),
            ' SSL Key' => $this->resolveDir($serverVars['ssl_key'] ?? null),
        ];

        foreach ($sslInfo as $name => $value) {
            $lines[] = sprintf("  %-20s %s", $name, $value);
        }

        return implode("\n", $lines);
    }

    private function renderReplicaPanel(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mReplication\x1b[0m";
        $lines[] = $this->renderSeparator();

        $replicaStatus = $this->replicaProvider->fetchStatus();
        $flavor = $this->context->flavor();

        if ($replicaStatus === null || count($replicaStatus) === 0) {
            $lines[] = "  \x1b[90mNot configured or not accessible\x1b[0m";
            return implode("\n", $lines);
        }

        // Render replica status fields that are most commonly useful
        $fields = [
            ' Master Host' => $this->resolveValue($replicaStatus['Master_Host'] ?? $replicaStatus['Source_Host'] ?? null),
            ' Master Port' => $this->resolveValue($replicaStatus['Master_Port'] ?? $replicaStatus['Source_Port'] ?? null),
            ' Slave IO Running' => $this->renderReplicaState(
                $replicaStatus['Slave_IO_Running'] ?? $replicaStatus['Replica_IO_Running'] ?? null
            ),
            ' Slave SQL Running' => $this->renderReplicaState(
                $replicaStatus['Slave_SQL_Running'] ?? $replicaStatus['Replica_SQL_Running'] ?? null
            ),
            ' Seconds Behind' => $this->renderSecondsBehind(
                $replicaStatus['Seconds_Behind_Master'] ?? $replicaStatus['Seconds_Behind_Source'] ?? null
            ),
            ' Relay Log File' => $this->resolveValue($replicaStatus['Relay_Log_File'] ?? null),
            ' Relay Pos' => $this->resolveValue($replicaStatus['Relay_Log_Pos'] ?? null),
        ];

        foreach ($fields as $name => $value) {
            $lines[] = sprintf("  %-20s %s", $name, $value);
        }

        return implode("\n", $lines);
    }

    private function renderFirewallPanel(): string
    {
        $lines = [];
        $lines[] = "\x1b[1;35mFirewall (AWS RDS compat.)\x1b[0m";
        $lines[] = $this->renderSeparator();

        // Firewall status is typically only available on managed cloud instances
        // Attempt to read from status variables, degrade gracefully if unavailable
        $statusVars = $this->context->statusVariables();

        $hasFirewall = isset($statusVars['Aurora_lwm']);
        $lines[] = sprintf(
            "  %-20s %s",
            ' AWS RDS Firewall',
            $this->tristate($hasFirewall),
        );

        return implode("\n", $lines);
    }

    private function renderFooter(): string
    {
        return "\x1b[90m[r] refresh  [q] quit\x1b[0m";
    }

    private function renderSeparator(): string
    {
        return "\x1b[36m──\x1b[0m" . str_repeat('─', 20);
    }

    // ─── Tristate Helper ─────────────────────────────────────────────────

    /**
     * Convert bool|string|null to styled Yes/No/Unknown.
     *
     * Used for features that may be present, absent, or unknown
     * (e.g., from server variables that may not be set).
     *
     * @param bool|string|null $value
     */
    public function tristate(bool|string|null $value): string
    {
        if ($value === true || $value === 'YES' || $value === 'ON') {
            return "\x1b[32mYes\x1b[0m";
        }

        if ($value === false || $value === 'NO' || $value === 'OFF') {
            return "\x1b[31mNo\x1b[0m";
        }

        return "\x1b[90mUnknown\x1b[0m";
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
     * Resolve a nullable string to displayable value or unknown.
     */
    private function resolveValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return "\x1b[90m-\x1b[0m";
        }

        return "\x1b[37m" . $value . "\x1b[0m";
    }

    /**
     * Resolve a directory path to displayable value or unknown.
     */
    private function resolveDir(?string $value): string
    {
        if ($value === null || $value === '') {
            return "\x1b[90m-\x1b[0m";
        }

        // Abbreviate long paths while keeping them readable
        if (strlen($value) > 40) {
            $value = '...' . substr($value, -37);
        }

        return "\x1b[37m" . $value . "\x1b[0m";
    }

    /**
     * Render replica IO/SQL running state with appropriate color.
     */
    private function renderReplicaState(?string $state): string
    {
        if ($state === null) {
            return "\x1b[90mUnknown\x1b[0m";
        }

        $lower = strtolower($state);
        if ($lower === 'yes' || $lower === 'connecting') {
            return "\x1b[32m" . $state . "\x1b[0m";
        }

        if ($lower === 'no') {
            return "\x1b[31m" . $state . "\x1b[0m";
        }

        return "\x1b[90m" . $state . "\x1b[0m";
    }

    /**
     * Render seconds behind master with human-readable duration.
     */
    private function renderSecondsBehind(?string $value): string
    {
        if ($value === null) {
            return "\x1b[90mUnknown\x1b[0m";
        }

        $seconds = (int) $value;
        if ($seconds === 0) {
            return "\x1b[32m0s (caught up)\x1b[0m";
        }

        return "\x1b[33m" . Format::duration($seconds) . "\x1b[0m";
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
