<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

/**
 * Base event class for all events.
 */
abstract class Event
{
    public function __construct(
        public readonly int $timestamp,
    ) {}

    /**
     * Get the event type name.
     */
    abstract public function getType(): string;

    /**
     * Check if this event matches a given type.
     */
    public function isType(string $type): bool
    {
        return $this->getType() === $type;
    }
}
