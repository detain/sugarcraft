<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Query\Admin\Providers\PostgresAdminProvider;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * PostgreSQL adapter that implements ServerContextInterface.
 *
 * Wraps PostgresAdminProvider (which implements AdminProviderInterface)
 * and adapts it to the ServerContextInterface expected by admin pages.
 * This allows all admin pages (DashboardPage, VariablesPage, ServerStatusPage,
 * ReportsPage) to work with PostgreSQL without modification.
 *
 * @see PostgresAdminProvider
 * @see ServerContextInterface
 */
final class PostgresServerContext implements ServerContextInterface
{
    /** @var array<string, string>|null */
    private ?array $serverVariablesCache = null;

    /** @var array<string, string>|null */
    private ?array $statusVariablesCache = null;

    private ?float $statusVariablesTsCache = null;

    public function __construct(
        private readonly PostgresAdminProvider $provider,
        private readonly DatabaseInterface $connection,
        private readonly string $versionString = '',
    ) {}

    /**
     * Create a new PostgresServerContext from a database connection.
     */
    public static function new(DatabaseInterface $connection, PostgresAdminProvider $provider): self
    {
        $versionString = $provider->flavor() === Flavor::Postgres
            ? $connection->serverVersion()
            : 'PostgreSQL unknown';

        return new self(
            provider: $provider,
            connection: $connection,
            versionString: $versionString,
        );
    }

    public function connection(): DatabaseInterface
    {
        return $this->connection;
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        if ($this->serverVariablesCache !== null) {
            return $this->serverVariablesCache;
        }

        $this->serverVariablesCache = $this->provider->fetchServerVariables();
        return $this->serverVariablesCache;
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        if ($this->statusVariablesCache !== null) {
            return $this->statusVariablesCache;
        }

        $this->statusVariablesTsCache = microtime(true);
        $this->statusVariablesCache = $this->provider->fetchStatusVariables();
        return $this->statusVariablesCache;
    }

    public function statusVariablesTs(): float
    {
        if ($this->statusVariablesTsCache !== null) {
            return $this->statusVariablesTsCache;
        }

        $this->statusVariables();
        return $this->statusVariablesTsCache ?? microtime(true);
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        // PostgreSQL does not have a SHOW PLUGINS equivalent
        return [];
    }

    public function version(): Version
    {
        return Version::parse($this->versionString);
    }

    public function flavor(): Flavor
    {
        return Flavor::Postgres;
    }

    public function versionString(): string
    {
        return $this->versionString;
    }

    public function wasReset(): bool
    {
        return $this->provider->wasReset();
    }

    public function refresh(): void
    {
        $this->serverVariablesCache = null;
        $this->statusVariablesCache = null;
        $this->statusVariablesTsCache = null;
        $this->provider->refresh();
    }
}
