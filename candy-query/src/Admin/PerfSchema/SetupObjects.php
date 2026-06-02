<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable representation of a Performance Schema object setup entry.
 *
 * Objects define default instrument settings for specific object types.
 * Can be enabled/disabled at runtime via withEnabled() / withTimed().
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_objects
 */
final readonly class SetupObjects
{
    use Mutable;

    /**
     * @param string $objectType   Object type (e.g. "EVENT", "FUNCTION", "TABLE")
     * @param string $objectSchema Object schema pattern (e.g. "'%'" for all schemas)
     * @param string $objectName   Object name pattern (e.g. "'%'" for all objects)
     * @param bool   $enabled      Whether instrumentation is currently enabled
     * @param bool   $timed        Whether timing is currently enabled
     */
    public function __construct(
        public string $objectType,
        public string $objectSchema,
        public string $objectName,
        public bool $enabled,
        public bool $timed,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(
        string $objectType = '',
        string $objectSchema = '',
        string $objectName = '',
        bool $enabled = false,
        bool $timed = false,
    ): self {
        return new self($objectType, $objectSchema, $objectName, $enabled, $timed);
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
     * Return a new instance with the timed state changed.
     *
     * @param bool $timed New timed state
     * @return static New instance
     */
    public function withTimed(bool $timed): static
    {
        return $this->mutate(['timed' => $timed]);
    }

    /**
     * Check if this applies to all schemas.
     */
    public function isGlobalSchema(): bool
    {
        return $this->objectSchema === "'%'" || $this->objectSchema === '%';
    }

    /**
     * Check if this applies to all objects.
     */
    public function isGlobalObject(): bool
    {
        return $this->objectName === "'%'" || $this->objectName === '%';
    }

    /**
     * Check if this is a catch-all entry (matches everything).
     */
    public function isCatchAll(): bool
    {
        return $this->isGlobalSchema() && $this->isGlobalObject();
    }
}
