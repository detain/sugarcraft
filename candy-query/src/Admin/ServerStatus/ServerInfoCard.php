<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

use SugarCraft\Query\Admin\Format;
use SugarCraft\Query\Admin\ServerContextInterface;

/**
 * Info card displaying server connection details and uptime.
 *
 * Shows: host, socket, port, version, and uptime (formatted as duration
 * with computed running-since timestamp).
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
     * Render the info card as an ANSI-formatted string.
     */
    public function render(): string
    {
        $serverVars = $this->context->serverVariables();
        $statusVars = $this->context->statusVariables();

        $host = $serverVars['Hostname'] ?? $this->resolveHost();
        $hostSet = $host !== null;

        $socket = $serverVars['Socket'] ?? null;
        $socketSet = $socket !== null && $socket !== '';

        $port = $serverVars['Port'] ?? $serverVars['mysqlx_port'] ?? null;
        $port = $port !== null ? (int) $port : null;
        $portSet = $port !== null && $port > 0;

        $version = $this->context->versionString();
        $versionSet = $version !== '';

        $uptimeStr = $statusVars['Uptime'] ?? null;
        $uptime = $uptimeStr !== null ? (int) $uptimeStr : null;
        $uptimeSet = $uptime !== null;

        $lines = [];

        $lines[] = $this->renderTitle();
        $lines[] = $this->renderSeparator();
        $lines[] = $this->renderRow('Host', $hostSet ? $this->renderHost($host) : $this->renderUnknown());
        $lines[] = $this->renderRow('Socket', $socketSet ? $this->renderSocket($socket) : $this->renderUnknown());
        $lines[] = $this->renderRow('Port', $portSet ? $this->renderPort($port) : $this->renderUnknown());
        $lines[] = $this->renderRow('Version', $versionSet ? $this->renderVersion($version) : $this->renderUnknown());
        $lines[] = $this->renderRow('Uptime', $uptimeSet && $uptime !== null ? $this->renderUptime($uptime) : $this->renderUnknown());
        $lines[] = $this->renderRow('Running Since', $uptimeSet && $uptime !== null ? $this->renderRunningSince($uptime) : $this->renderUnknown());

        return implode("\n", $lines);
    }

    private function renderTitle(): string
    {
        return "\x1b[1;36mServer Information\x1b[0m";
    }

    private function renderSeparator(): string
    {
        return "\x1b[36m──\x1b[0m" . str_repeat('─', 20);
    }

    private function renderRow(string $label, string $value): string
    {
        return sprintf(
            "  \x1b[90m%-12s\x1b[0m %s",
            $label . ':',
            $value,
        );
    }

    private function renderHost(string $host): string
    {
        return "\x1b[37m" . $host . "\x1b[0m";
    }

    private function renderSocket(string $socket): string
    {
        return "\x1b[37m" . $socket . "\x1b[0m";
    }

    private function renderPort(int $port): string
    {
        return "\x1b[37m" . (string) $port . "\x1b[0m";
    }

    private function renderVersion(string $version): string
    {
        // Highlight the version number in cyan
        $versionNum = preg_replace('/^(\d+\.\d+\.\d+)/', "\x1b[36m$1\x1b[0m", $version);
        return $versionNum ?? $version;
    }

    private function renderUptime(int $uptime): string
    {
        // Use Format::duration() to convert seconds to human-readable string
        $duration = Format::duration($uptime);
        $seconds = "\x1b[90m(" . $uptime . "s)\x1b[0m";
        return "\x1b[37m" . $duration . "\x1b[0m " . $seconds;
    }

    private function renderRunningSince(int $uptime): string
    {
        // Compute running-since from current time minus uptime
        $runningSince = time() - $uptime;
        $formatted = date('Y-m-d H:i:s', $runningSince);
        return "\x1b[37m" . $formatted . "\x1b[0m";
    }

    private function renderUnknown(): string
    {
        return "\x1b[90mUnknown\x1b[0m";
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
