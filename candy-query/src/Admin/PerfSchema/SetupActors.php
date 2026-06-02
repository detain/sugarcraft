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
     * Change type constants for commitStatements().
     */
    public const CHANGE_INSERT = 'insert';
    public const CHANGE_UPDATE = 'update';
    public const CHANGE_DELETE = 'delete';
    public const CHANGE_NONE = 'none';

    /**
     * @param string  $host       Host pattern (e.g. "'%'" for all hosts)
     * @param string  $user       User pattern (e.g. "'%'" for all users)
     * @param string  $role       Role pattern (e.g. "'%'" for all roles)
     * @param bool    $enabled    Whether this actor is currently enabled
     * @param bool    $dirty      Whether this actor has unsaved changes
     * @param string  $changeType Type of change: insert, update, delete, none
     */
    public function __construct(
        public string $host,
        public string $user,
        public string $role,
        public bool $enabled,
        private bool $dirty = false,
        private string $changeType = self::CHANGE_NONE,
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
        return new self($host, $user, $role, $enabled, false, self::CHANGE_NONE);
    }

    /**
     * Return a new instance with the enabled state changed.
     *
     * @param bool $enabled New enabled state
     * @return static New instance
     */
    public function withEnabled(bool $enabled): static
    {
        if ($this->enabled === $enabled) {
            return $this;
        }

        // If this was marked for deletion or was never tracked, mark as update
        // Otherwise keep the existing change type
        $changeType = match ($this->changeType) {
            self::CHANGE_DELETE => self::CHANGE_DELETE,
            self::CHANGE_INSERT => self::CHANGE_INSERT,
            default => self::CHANGE_UPDATE,
        };

        return $this->mutate(['enabled' => $enabled, 'dirty' => true, 'changeType' => $changeType]);
    }

    /**
     * Mark this actor for deletion.
     */
    public function markForDeletion(): static
    {
        if ($this->changeType === self::CHANGE_INSERT) {
            // If it was a new actor being inserted, deletion just means don't insert
            return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_NONE]);
        }

        return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_DELETE]);
    }

    /**
     * Mark this actor as a new insertion.
     */
    public function markForInsertion(): static
    {
        if ($this->changeType === self::CHANGE_DELETE) {
            // If it was marked for deletion, just update instead
            return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_UPDATE]);
        }

        return $this->mutate(['dirty' => true, 'changeType' => self::CHANGE_INSERT]);
    }

    /**
     * Check if this actor has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Mark this actor as clean (no pending changes).
     */
    public function markClean(): void
    {
        // Immutable - this method exists for API compatibility but has no effect
    }

    /**
     * Return a clean copy of this actor.
     */
    public function asClean(): static
    {
        if (!$this->dirty) {
            return $this;
        }

        return new self(
            host: $this->host,
            user: $this->user,
            role: $this->role,
            enabled: $this->enabled,
            dirty: false,
            changeType: self::CHANGE_NONE,
        );
    }

    /**
     * Get the type of change this actor has.
     */
    public function getChangeType(): string
    {
        return $this->changeType;
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

    /**
     * Generate SQL statement(s) to commit changes.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitStatements(): array
    {
        if (!$this->dirty || $this->changeType === self::CHANGE_NONE) {
            return [];
        }

        // Backtick-escape values for safety
        $escapedHost = $this->quote($this->host);
        $escapedUser = $this->quote($this->user);
        $escapedRole = $this->quote($this->role);

        return match ($this->changeType) {
            self::CHANGE_INSERT => [
                sprintf(
                    'INSERT INTO `performance_schema`.`setup_actors` (`HOST`, `USER`, `ROLE`, `ENABLED`) VALUES (%s, %s, %s, %s)',
                    $escapedHost,
                    $escapedUser,
                    $escapedRole,
                    $this->enabled ? "'YES'" : "'NO'"
                ),
            ],

            self::CHANGE_UPDATE => [
                sprintf(
                    'UPDATE `performance_schema`.`setup_actors` SET `ENABLED` = %s WHERE `HOST` = %s AND `USER` = %s AND `ROLE` = %s',
                    $this->enabled ? "'YES'" : "'NO'",
                    $escapedHost,
                    $escapedUser,
                    $escapedRole
                ),
            ],

            self::CHANGE_DELETE => [
                sprintf(
                    'DELETE FROM `performance_schema`.`setup_actors` WHERE `HOST` = %s AND `USER` = %s AND `ROLE` = %s',
                    $escapedHost,
                    $escapedUser,
                    $escapedRole
                ),
            ],

            default => [],
        };
    }

    /**
     * Quote a string value for SQL.
     */
    private function quote(string $value): string
    {
        $escaped = str_replace("'", "''", $value);
        return "'" . $escaped . "'";
    }
}
