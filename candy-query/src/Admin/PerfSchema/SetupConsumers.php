<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\PerfSchema;

use SugarCraft\Core\Concerns\Mutable;

/**
 * Immutable representation of a Performance Schema consumer.
 *
 * Consumers determine which events are collected and stored.
 * Can be enabled/disabled at runtime via withEnabled().
 *
 * @see Mirrors mysql-workbench wb_admin_performance_schema setup_consumers
 */
final readonly class SetupConsumers
{
    use Mutable;

    /**
     * @param string $name    Consumer name (e.g. "events_statements_history")
     * @param bool   $enabled Whether the consumer is currently enabled
     * @param bool   $dirty   Whether this consumer has unsaved changes
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        private bool $dirty = false,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(string $name = '', bool $enabled = false): self
    {
        return new self($name, $enabled, false);
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

        return $this->mutate(['enabled' => $enabled, 'dirty' => true]);
    }

    /**
     * Check if this consumer has unsaved changes.
     */
    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /**
     * Mark this consumer as clean (no pending changes).
     */
    public function markClean(): void
    {
        // Immutable - this method exists for API compatibility but has no effect
        // The caller should replace the instance with a clean copy if needed
    }

    /**
     * Return a clean copy of this consumer.
     */
    public function asClean(): static
    {
        if (!$this->dirty) {
            return $this;
        }

        return new self(
            name: $this->name,
            enabled: $this->enabled,
            dirty: false,
        );
    }

    /**
     * Generate SQL statement to commit changes.
     *
     * Uses IN clause to match this consumer's name.
     * Only emits a statement if the consumer is dirty.
     *
     * @return list<string> SQL statements to execute
     */
    public function commitStatements(): array
    {
        if (!$this->dirty) {
            return [];
        }

        // Backtick-escape the consumer name for safety
        $escapedName = '`' . str_replace('`', '``', $this->name) . '`';

        return [
            sprintf(
                'UPDATE `performance_schema`.`setup_consumers` SET `ENABLED` = %s WHERE `NAME` IN (%s)',
                $this->enabled ? "'YES'" : "'NO'",
                $escapedName
            ),
        ];
    }
}
