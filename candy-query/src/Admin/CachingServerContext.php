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
        return $this->cachedServerVars ?? $this->inner->serverVariables();
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
        // Prefer cached data if available; otherwise delegate to inner context
        // which may have its own sync cache (e.g., ServerContext caches for 3s).
        return $this->cachedStatusVars ?? $this->inner->statusVariables();
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
