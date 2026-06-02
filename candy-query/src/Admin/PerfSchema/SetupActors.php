<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable representation of a Performance Schema actor.
 *
 * Actors define which user/host/role combinations are monitored.
 * Can be enabled/disabled at runtime via withEnabled().
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_actors
 */
final readonly class SetupActors
{
    use Mutable;

    /**
     * @param string $host    Host pattern (e.g. "'%'" for all hosts)
     * @param string $user    User pattern (e.g. "'%'" for all users)
     * @param string $role    Role pattern (e.g. "'%'" for all roles)
     * @param bool   $enabled Whether this actor is currently enabled
     */
    public function __construct(
        public string $host,
        public string $user,
        public string $role,
        public bool $enabled,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $host = '',
        string $user = '',
        string $role = '',
        bool $enabled = false,
    ): self {
        return new self($host, $user, $role, $enabled);
    }

    /**
     * Return a new instance with the enabled state changed.
     *
     * @param bool $enabled New enabled state
     * @return static New instance
     */
    public function withEnabled(bool $enabled): static
    {
        return $this->mutate(['enabled' => $enabled]);
    }

    /**
     * Check if this actor matches all hosts.
     */
    public function isGlobalHost(): bool
    {
        return $this->host === "'%'" || $this->host === '%';
    }

    /**
     * Check if this actor matches all users.
     */
    public function isGlobalUser(): bool
    {
        return $this->user === "'%'" || $this->user === '%';
    }

    /**
     * Check if this actor matches all roles.
     */
    public function isGlobalRole(): bool
    {
        return $this->role === "'%'" || $this->role === '%';
    }

    /**
     * Check if this is a catch-all actor (matches everything).
     */
    public function isCatchAll(): bool
    {
        return $this->isGlobalHost() && $this->isGlobalUser() && $this->isGlobalRole();
    }
}
