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
     */
    public function __construct(
        public string $name,
        public bool $enabled,
    ) {}

    /**
     * Factory method to create a new instance.
     */
    public static function new(string $name = '', bool $enabled = false): self
    {
        return new self($name, $enabled);
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
}
