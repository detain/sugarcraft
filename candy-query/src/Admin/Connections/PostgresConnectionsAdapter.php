<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Connections;

use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Db\Flavor;

/**
 * PostgreSQL stub connections adapter.
 *
 * Returns ConnectionCounters with zero/inactive values and a stub
 * processlist result list. The notice() method provides guidance
 * for users who need pg_stat_activity access.
 *
 * @see PostgresAdminProvider
 */
final class PostgresConnectionsAdapter
{
    private const NOTICE = 'Postgres process list requires pg_stat_activity GRANT.';

    private ?ConnectionCounters $counters = null;
    /** @var list<ProcesslistResult>|null */
    private ?array $stubProcesslist = null;

    public function __construct(
        private readonly AdminProviderInterface $provider,
    ) {}

    /**
     * Create a new instance from an AdminProviderInterface.
     */
    public static function new(AdminProviderInterface $provider): self
    {
        return new self($provider);
    }

    /**
     * Get connection counters with zero/placeholder values.
     *
     * PostgreSQL does not expose the same connection counter variables
     * as MySQL, so we return a counters object with zeros rather than
     * crashing the connections page.
     */
    public function counters(): ConnectionCounters
    {
        if ($this->counters !== null) {
            return $this->counters;
        }

        $this->counters = new ConnectionCounters(
            threadsConnected: 0,
            threadsRunning: 0,
            threadsCached: 0,
            threadsCreated: 0,
            connections: 0,
            maxConnections: $this->provider->maxConnections(),
            abortedClients: 0,
            abortedConnects: 0,
            connectionErrorsTotal: 0,
            connectionErrorsMax: 0,
            snapshot: null,
        );

        return $this->counters;
    }

    /**
     * Get a stub processlist that returns an empty list.
     *
     * Returns an empty processlist to avoid crashing the connections page.
     * The actual pg_stat_activity data would require the grant mentioned in notice().
     *
     * @return list<ProcesslistResult>
     */
    public function stubProcesslist(): array
    {
        if ($this->stubProcesslist !== null) {
            return $this->stubProcesslist;
        }

        // Attempt to fetch via provider; will return [] on permission error
        $raw = $this->provider->fetchProcesslist();

        $this->stubProcesslist = array_map(
            fn(array $row) => ProcesslistResult::fromPostgresRow($row),
            $raw,
        );

        return $this->stubProcesslist;
    }

    /**
     * Return the user-facing notice about pg_stat_activity requirements.
     */
    public function notice(): string
    {
        return self::NOTICE;
    }

    /**
     * True when the provider's flavor is Postgres.
     */
    public function supportsFlavor(): bool
    {
        return $this->provider->flavor() === Flavor::Postgres;
    }
}
