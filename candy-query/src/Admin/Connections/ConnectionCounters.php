<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\StatusSnapshot;

/**
 * Connection-related counters from SHOW GLOBAL STATUS.
 *
 * Reads Threads_*, Connections, Aborted_*, Connection_errors_* variables
 * and computes derived ratios (e.g. connection usage ratio).
 *
 * @see Mirrors charmbracelet/lazysql connection counters
 */
final class ConnectionCounters
{
    private ?float $connectionUsageRatio = null;

    public function __construct(
        public readonly int $threadsConnected,
        public readonly int $threadsRunning,
        public readonly int $threadsCached,
        public readonly int $threadsCreated,
        public readonly int $connections,
        public readonly int $maxConnections,
        public readonly int $abortedClients,
        public readonly int $abortedConnects,
        public readonly int $connectionErrorsTotal,
        public readonly int $connectionErrorsMax,
        private readonly ?StatusSnapshot $snapshot,
    ) {}

    /**
     * Create from a StatusSnapshot.
     */
    public static function fromSnapshot(StatusSnapshot $snapshot, int $maxConnections = 151): self
    {
        return new self(
            threadsConnected: $snapshot->getInt('Threads_connected') ?? 0,
            threadsRunning: $snapshot->getInt('Threads_running') ?? 0,
            threadsCached: $snapshot->getInt('Threads_cached') ?? 0,
            threadsCreated: $snapshot->getInt('Threads_created') ?? 0,
            connections: $snapshot->getInt('Connections') ?? 0,
            maxConnections: $maxConnections,
            abortedClients: $snapshot->getInt('Aborted_clients') ?? 0,
            abortedConnects: $snapshot->getInt('Aborted_connects') ?? 0,
            connectionErrorsTotal: self::sumConnectionErrors($snapshot),
            connectionErrorsMax: 0,
            snapshot: $snapshot,
        );
    }

    /**
     * Connection usage ratio (0.0-1.0) computed lazily.
     */
    public function connectionUsageRatio(): float
    {
        if ($this->connectionUsageRatio === null) {
            $this->connectionUsageRatio = $this->maxConnections > 0
                ? (float) $this->threadsConnected / (float) $this->maxConnections
                : 0.0;
        }
        return $this->connectionUsageRatio;
    }

    /**
     * True when connection usage is above 80% (critical threshold).
     */
    public function isConnectionUsageCritical(): bool
    {
        return $this->connectionUsageRatio() >= 0.8;
    }

    /**
     * Aborted connection rate per connection (0.0-1.0+).
     *
     * High values may indicate authentication failures or network issues.
     */
    public function abortedConnectionRate(): float
    {
        if ($this->connections === 0) {
            return 0.0;
        }
        return (float) $this->abortedConnects / (float) $this->connections;
    }

    /**
     * Sum all Connection_errors_* status variables.
     */
    private static function sumConnectionErrors(StatusSnapshot $snapshot): int
    {
        $sum = 0;
        foreach ($snapshot->variables as $key => $value) {
            if (str_starts_with($key, 'Connection_errors_')) {
                $sum += (int) ($value ?? 0);
            }
        }
        return $sum;
    }
}
