<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin\ServerStatus;

use SugarCraft\Dashboard\Components\Card\Card;
use SugarCraft\Dashboard\Components\Card\DefinitionList;
use SugarCraft\Sql\Admin\Format;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\CellValue;

/**
 * Info card displaying server connection details and uptime.
 *
 * Shows: host, socket, port, version, and uptime (formatted as duration
 * with computed running-since timestamp). Rendered as a sugar-dashboard
 * Card wrapping a DefinitionList; missing fields fall back to the
 * "Unknown" placeholder rather than hand-rolled ANSI.
 *
 * @see Mirrors mysql-workbench/server_status_info
 */
final class ServerInfoCard
{
    public function __construct(
        private readonly ServerContextInterface $context,
    ) {}

    /**
     * Create a new ServerInfoCard from the current server context.
     */
    public static function new(ServerContextInterface $context): self
    {
        return new self($context);
    }

    /**
     * Render the info card via a sugar-dashboard Card + DefinitionList.
     */
    public function render(): string
    {
        $serverVars = $this->context->serverVariables();
        $statusVars = $this->context->statusVariables();

        // Hostname/Socket/Version are server-controlled strings and the
        // DefinitionList renders without neutralizing (adminpages-006).
        $host = $serverVars['Hostname'] ?? $this->resolveHost();
        $host = is_string($host) ? CellValue::sanitize($host) : null;

        $socket = $serverVars['Socket'] ?? null;
        $socket = ($socket !== null && $socket !== '') ? CellValue::sanitize($socket) : null;

        $port = $serverVars['Port'] ?? $serverVars['mysqlx_port'] ?? null;
        $port = ($port !== null && (int) $port > 0) ? (string) (int) $port : null;

        $version = $this->context->versionString();
        $version = $version !== '' ? CellValue::sanitize($version) : null;

        $uptimeStr = $statusVars['Uptime'] ?? null;
        $uptime = $uptimeStr !== null ? (int) $uptimeStr : null;

        $list = DefinitionList::new()
            ->withPlaceholder('Unknown')
            ->withRows([
                ['Host', $host],
                ['Socket', $socket],
                ['Port', $port],
                ['Version', $version],
                ['Uptime', $uptime !== null ? $this->formatUptime($uptime) : null],
                ['Running Since', $uptime !== null ? date('Y-m-d H:i:s', time() - $uptime) : null],
            ]);

        return Card::titled($list, 'Server Information')->render();
    }

    /**
     * Human-readable uptime (SQL-P18, adminpages-040): the debug-style
     * "(298800s)" parenthetical carried no information duration does not.
     */
    private function formatUptime(int $uptime): string
    {
        return Format::duration($uptime);
    }

    /**
     * Resolve hostname from connection when not in server variables.
     */
    private function resolveHost(): ?string
    {
        try {
            // Use the database name as a fallback identifier (DatabaseInterface
            // exposes dsn(), not a database() accessor — parse the name from it).
            $dbName = $this->databaseNameFromDsn($this->context->connection()->dsn());
            if ($dbName !== '') {
                return $dbName;
            }
        } catch (\Throwable) {
            // Connection may not be established yet
        }

        return null;
    }

    private function databaseNameFromDsn(string $dsn): string
    {
        if (preg_match('/(?:^|;)dbname=([^;]+)/', $dsn, $m) === 1) {
            return $m[1];
        }

        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, strlen('sqlite:'));
            $path = preg_replace('#^/*/#', '/', $path) ?? $path;
            if ($path === '' || str_contains($path, ':memory:')) {
                return '';
            }
            return basename($path);
        }

        return '';
    }
}
