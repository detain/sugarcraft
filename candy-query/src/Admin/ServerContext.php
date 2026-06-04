<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * MySQL-focused ServerContext implementation.
 *
 * Caches SHOW GLOBAL VARIABLES, SHOW GLOBAL STATUS (with timestamp),
 * SHOW PLUGINS, and parsed version/flavor. Gracefully degrades on
 * MySQL errors 1142/1227/1146/2002/2003/2013.
 *
 * @see Mirrors charmbracelet/lazysql ServerContext
 */
final class ServerContext implements ServerContextInterface
{
    private const STATUS_CACHE_TTL = 3.0;
    private const SERVER_CACHE_TTL = 30.0;

    private ?array $serverVariablesCache = null;
    private ?float $serverVariablesTsCache = null;
    private ?array $statusVariablesCache = null;
    private ?float $statusVariablesTsCache = null;
    /** @var list<array<string, mixed>>|null */
    private $pluginsCache = null;
    private ?Version $versionCache = null;
    private ?Flavor $flavorCache = null;
    private ?string $versionStringCache = null;
    private ?bool $wasResetCache = null;
    private ?int $lastUptime = null;

    public function __construct(
        private readonly DatabaseInterface $connection,
        private readonly ?Flavor $flavor = null,
    ) {
        // If flavor is provided, use it to pre-set the cache
        if ($this->flavor !== null) {
            $this->flavorCache = $this->flavor;
        }
    }

    public function connection(): DatabaseInterface
    {
        return $this->connection;
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        $now = microtime(true);

        if ($this->serverVariablesCache !== null && $this->serverVariablesTsCache !== null) {
            if (($now - $this->serverVariablesTsCache) < self::SERVER_CACHE_TTL) {
                return $this->serverVariablesCache;
            }
        }

        $this->serverVariablesTsCache = $now;
        $this->serverVariablesCache = $this->fetchServerVariables();
        return $this->serverVariablesCache;
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        $now = microtime(true);

        if ($this->statusVariablesCache !== null && $this->statusVariablesTsCache !== null) {
            if (($now - $this->statusVariablesTsCache) < self::STATUS_CACHE_TTL) {
                return $this->statusVariablesCache;
            }
        }

        $this->statusVariablesTsCache = $now;
        $this->statusVariablesCache = $this->fetchStatusVariables();
        $this->detectReset();
        return $this->statusVariablesCache;
    }

    public function statusVariablesTs(): float
    {
        if ($this->statusVariablesTsCache !== null) {
            return $this->statusVariablesTsCache;
        }
        $this->statusVariables();
        return $this->statusVariablesTsCache ?? 0.0;
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        if ($this->pluginsCache !== null) {
            return $this->pluginsCache;
        }

        $this->pluginsCache = $this->fetchPlugins();
        return $this->pluginsCache;
    }

    public function version(): Version
    {
        if ($this->versionCache !== null) {
            return $this->versionCache;
        }

        $raw = $this->connection()->serverVersion();
        $this->versionStringCache = $raw;
        $this->versionCache = Version::parse($raw);
        return $this->versionCache;
    }

    public function flavor(): Flavor
    {
        if ($this->flavorCache !== null) {
            return $this->flavorCache;
        }

        $version = $this->version();
        $numericVersion = $version->major . '.' . $version->minor . '.' . $version->release;
        $raw = $this->connection()->serverVersion();
        $this->flavorCache = Flavor::detectFromVersionString($numericVersion, $raw);
        return $this->flavorCache;
    }

    public function versionString(): string
    {
        if ($this->versionStringCache !== null) {
            return $this->versionStringCache;
        }

        return $this->connection()->serverVersion();
    }

    public function password(): string
    {
        // Delegated to the underlying connection if it has password().
        // CachedConnection no longer exposes password() so we return ''
        // when the connection doesn't support it.
        if (method_exists($this->connection(), 'password')) {
            return $this->connection()->password();
        }
        return '';
    }

    public function wasReset(): bool
    {
        $this->statusVariables();
        return $this->wasResetCache ?? false;
    }

    /**
     * Get the last known server uptime.
     *
     * Returns null if uptime has not been tracked yet.
     */
    public function lastUptime(): ?int
    {
        $this->statusVariables();
        return $this->lastUptime;
    }

    /**
     * Force a refresh of all cached values.
     */
    public function refresh(): void
    {
        $this->serverVariablesCache = null;
        $this->serverVariablesTsCache = null;
        $this->statusVariablesCache = null;
        $this->statusVariablesTsCache = null;
        $this->pluginsCache = null;
        $this->versionCache = null;
        $this->flavorCache = null;
        $this->versionStringCache = null;
    }

    /**
     * @return array<string, string>
     */
    private function fetchServerVariables(): array
    {
        try {
            $rows = $this->connection()->query('SHOW GLOBAL VARIABLES');
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['Variable_name'], $row['Value'])) {
                    $out[(string) $row['Variable_name']] = (string) $row['Value'];
                }
            }
            return $out;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchStatusVariables(): array
    {
        try {
            $rows = $this->connection()->query('SHOW GLOBAL STATUS');
            $out = [];
            foreach ($rows as $row) {
                if (isset($row['Variable_name'], $row['Value'])) {
                    $out[(string) $row['Variable_name']] = (string) $row['Value'];
                }
            }
            return $out;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchPlugins(): array
    {
        try {
            $rows = $this->connection()->query('SHOW PLUGINS');
            /** @var list<array<string, mixed>> */
            return $rows;
        } catch (\PDOException $e) {
            if ($this->isIgnorableError($e)) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Detect whether the server has restarted since the last poll.
     *
     * Compares the current Uptime status variable against the last observed
     * value. If the new uptime is less than the previous (wrap or restart),
     * sets wasResetCache to true. This is the single authoritative source of
     * restart detection — consumers (Sampler, StatusPoller) delegate to
     * ServerContext via StatusSnapshotProviderInterface::wasReset().
     *
     * Called automatically by statusVariables() after fetching fresh data.
     */
    private function detectReset(): void
    {
        $uptime = $this->statusVariablesCache['Uptime'] ?? null;
        if ($uptime === null) {
            return;
        }

        $currentUptime = (int) $uptime;
        if ($this->lastUptime !== null && $currentUptime < $this->lastUptime) {
            $this->wasResetCache = true;
        }
        $this->lastUptime = $currentUptime;
    }

    /**
     * True for errors that indicate missing privileges or unavailable features.
     */
    private function isIgnorableError(\PDOException $e): bool
    {
        $code = $e->getCode();
        if ($code === '42000') {
            return true;
        }
        $message = strtolower($e->getMessage());
        return str_contains($message, 'access denied')
            || str_contains($message, 'command denied')
            || (str_contains($message, 'table') && str_contains($message, 'doesn\'t exist'))
            || str_contains($message, 'can\'t connect')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'timeout');
    }
}