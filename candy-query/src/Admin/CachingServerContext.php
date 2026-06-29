<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * ServerContext wrapper that returns cached status/server variables when available.
 *
 * Used by the Admin pane to display previously-fetched data while a new async
 * fetch is in flight, avoiding a blank flash while polling.
 */
final class CachingServerContext implements ServerContextInterface
{
    public function __construct(
        private ServerContextInterface $inner,
        private ?array $cachedStatusVars = null,
        private ?array $cachedServerVars = null,
        private bool $isLoading = false,
    ) {}

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        // Route admin-page queries through the async cache so a slow sys query
        // never blocks the event loop during view(). See AdminQueryCache.
        return new CachedConnection($this->inner->connection());
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
        return AdminQueryCache::instance()->getServerVariables() ?? [];
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
        return AdminQueryCache::instance()->getStatusVariables() ?? [];
    }

    public function statusVariablesTs(): float
    {
        return $this->inner->statusVariablesTs();
    }

    /** @return list<array<string, mixed>> */
    public function plugins(): array
    {
        return $this->inner->plugins();
    }

    public function version(): \SugarCraft\Query\Db\Version
    {
        return $this->inner->version();
    }

    public function flavor(): \SugarCraft\Query\Db\Flavor
    {
        return $this->inner->flavor();
    }

    public function versionString(): string
    {
        return $this->inner->versionString();
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
