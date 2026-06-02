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
    ) {}

    public function connection(): \SugarCraft\Query\Db\DatabaseInterface
    {
        return $this->inner->connection();
    }

    /** @return array<string, string> */
    public function serverVariables(): array
    {
        return $this->cachedServerVars ?? $this->inner->serverVariables();
    }

    /** @return array<string, string> */
    public function statusVariables(): array
    {
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
}
