<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Dash\Components\Card\Card;
use SugarCraft\Dash\Components\Card\DefinitionList;
use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\ServerContextInterface;

/**
 * Info card displaying server connection details and uptime.
 *
 * Shows: host, socket, port, version, and uptime (formatted as duration
 * with computed running-since timestamp). Rendered as a sugar-dash
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
     * Render the info card via a sugar-dash Card + DefinitionList.
     */
    public function render(): string
    {
        $serverVars = $this->context->serverVariables();
        $statusVars = $this->context->statusVariables();

        $host = $serverVars['Hostname'] ?? $this->resolveHost();

        $socket = $serverVars['Socket'] ?? null;
        $socket = ($socket !== null && $socket !== '') ? $socket : null;

        $port = $serverVars['Port'] ?? $serverVars['mysqlx_port'] ?? null;
        $port = ($port !== null && (int) $port > 0) ? (string) (int) $port : null;

        $version = $this->context->versionString();
        $version = $version !== '' ? $version : null;

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
     * Human-readable uptime keeping the raw seconds for reference.
     */
    private function formatUptime(int $uptime): string
    {
        return Format::duration($uptime) . ' (' . $uptime . 's)';
    }

    /**
     * Resolve hostname from connection when not in server variables.
     */
    private function resolveHost(): ?string
    {
        try {
            $connection = $this->context->connection();
            // Use the database name as a fallback identifier
            $dbName = $connection->database();
            if ($dbName !== '') {
                return $dbName;
            }
        } catch (\Throwable) {
            // Connection may not be established yet
        }

        return null;
    }
}
