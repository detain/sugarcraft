<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Version;

/**
 * ServerContext wrapper that returns cached status/server variables when available.
 *
 * Used by the Admin pane to display previously-fetched data while a new async
 * fetch is in flight, avoiding a blank flash while polling.
 */
final class AsyncCachingServerContext implements ServerContextInterface
{
    /**
     * MySQL-family version probe. Aliased so a cached row matches the shape
     * MysqlDatabase::serverVersion() reads (`SELECT VERSION() as ver`), letting
     * the render path serve version data from the async cache.
     */
    private const VERSION_SQL = 'SELECT VERSION() AS ver';

    /** MySQL-family plugin catalogue query. */
    private const PLUGINS_SQL = 'SHOW PLUGINS';

    public function __construct(
        private ServerContextInterface $inner,
        private ?array $cachedStatusVars = null,
        private ?array $cachedServerVars = null,
        private bool $isLoading = false,
        private ?AdminQueryCache $cache = null,
    ) {}

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        // Route admin-page queries through the async cache so a slow sys query
        // never blocks the event loop during view(). See AdminQueryCache.
        return new AsyncCachedConnection($this->inner->connection(), $this->cache);
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        // Return passed-in cached value if available (from async fetch).
        if ($this->cachedServerVars !== null) {
            return $this->cachedServerVars;
        }
        // Fall back to AdminQueryCache which holds fresh async-fetched data.
        // On a cold miss we return [] rather than calling inner->serverVariables()
        // synchronously: this context is on the render path, and SHOW GLOBAL
        // VARIABLES against a remote server can take seconds (it froze the whole
        // UI). The data arrives shortly via the async admin tick, which fetches
        // and caches it; until then the page shows its loading/empty state.
        return $this->cache()->getServerVariables() ?? [];
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        // Return passed-in cached value if available (from async fetch).
        if ($this->cachedStatusVars !== null) {
            return $this->cachedStatusVars;
        }
        // Cold miss → [] (never a synchronous query on the render path; see
        // serverVariables() above). The async admin tick fills the cache.
        return $this->cache()->getStatusVariables() ?? [];
    }

    /**
     * Resolved lazily (not in the constructor) so the process-global default
     * stays live across AdminQueryCache::reset() in tests.
     */
    private function cache(): AdminQueryCache
    {
        return $this->cache ?? AdminQueryCache::instance();
    }

    public function statusVariablesTs(): float
    {
        return $this->inner->statusVariablesTs();
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        // SHOW PLUGINS is MySQL-only and can be slow on a busy server, so it
        // must not run synchronously on the render path. Route it through
        // AdminQueryCache exactly like serverVariables(): a cold miss returns []
        // and schedules a background fetch (drained by App's admin tick); the
        // next render reads the stored rows. Non-MySQL inners return [] with no
        // query, so delegating there stays non-blocking.
        if (!$this->isMysqlFamily()) {
            return $this->inner->plugins();
        }

        return $this->cache()->lookup(self::PLUGINS_SQL) ?? [];
    }

    public function version(): Version
    {
        // SELECT VERSION() is a synchronous PDO round-trip on the inner MySQL
        // context; serve it from the async cache instead so view() never blocks.
        // Cold miss → a zero Version (loading state), matching how
        // EmptyServerContext degrades; the async tick fills the cache and the
        // next render parses the real string.
        if (!$this->isMysqlFamily()) {
            return $this->inner->version();
        }

        $raw = $this->cachedVersionRaw();

        return Version::parse($raw ?? '');
    }

    public function flavor(): Flavor
    {
        // Not routed through the cache: flavor() never hits PDO on the render
        // path. Every inner resolves it from a pre-set enum (ServerContext is
        // always built with the connection's flavor — see App::createContext())
        // or a constant (Postgres/Empty), so delegating is already non-blocking
        // and returns the authoritative connection flavor.
        return $this->inner->flavor();
    }

    public function versionString(): string
    {
        // Same rationale as version(): serve the raw version string from the
        // async cache so the render path never blocks on SELECT VERSION().
        if (!$this->isMysqlFamily()) {
            return $this->inner->versionString();
        }

        return $this->cachedVersionRaw() ?? '';
    }

    /**
     * True when the inner speaks the MySQL wire protocol (MySQL/MariaDB/Percona)
     * and thus supports SELECT VERSION()/SHOW PLUGINS. flavor() resolves from a
     * pre-set enum/constant on every inner impl, so this never issues a query.
     */
    private function isMysqlFamily(): bool
    {
        return match ($this->inner->flavor()) {
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => true,
            default => false,
        };
    }

    /**
     * Raw server-version string from the async cache, or null on a cold miss.
     *
     * MySQL family only; other flavors capture their version at construction and
     * are already non-blocking, so their callers delegate to the inner context.
     */
    private function cachedVersionRaw(): ?string
    {
        $rows = $this->cache()->lookup(self::VERSION_SQL);
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0];
        if (!\is_array($first) || $first === []) {
            return null;
        }

        // Prefer the aliased column; fall back to the first value in case a
        // driver returns an unaliased/renamed column.
        $value = $first['ver'] ?? \reset($first);

        return \is_scalar($value) ? (string) $value : null;
    }

    public function password(): string
    {
        if (\method_exists($this->inner, 'password')) {
            return $this->inner->password();
        }
        return '';
    }

    public function wasReset(): bool
    {
        return $this->inner->wasReset();
    }

    public function refresh(): void
    {
        $this->inner->refresh();
    }

    public function isLoading(): bool
    {
        return $this->isLoading;
    }

    public function hasCachedData(): bool
    {
        return $this->cachedStatusVars !== null && $this->cachedStatusVars !== [];
    }
}
