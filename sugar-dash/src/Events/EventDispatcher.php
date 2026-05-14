<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Events;

/**
 * Event dispatcher for managing and dispatching events.
 */
final class EventDispatcher
{
    /** @var array<string, list<EventHandler>> */
    private array $listeners = [];

    /** @var array<string, list<int>> */
    private array $onceKeysToRemove = [];

    public function __construct() {}

    /**
     * Create a new event dispatcher.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * Register an event handler.
     *
     * @template T of Event
     * @param class-string<T>|string $eventType
     * @param EventHandler<T> $handler
     */
    public function on(string $eventType, callable $handler): self
    {
        $clone = clone $this;
        if (!isset($clone->listeners[$eventType])) {
            $clone->listeners[$eventType] = [];
        }
        $clone->listeners[$eventType][] = $handler;
        return $clone;
    }

    /**
     * Register a one-time event handler (removed after first execution).
     *
     * @template T of Event
     * @param class-string<T>|string $eventType
     * @param EventHandler<T> $handler
     */
    public function once(string $eventType, callable $handler): self
    {
        $clone = clone $this;

        // Add wrapper to the clone's listeners
        if (!isset($clone->listeners[$eventType])) {
            $clone->listeners[$eventType] = [];
        }
        $key = array_push($clone->listeners[$eventType], $handler);
        $onceKey = $key - 1; // array_push returns new count, index is count-1

        // Mark this key for removal after first dispatch
        if (!isset($clone->onceKeysToRemove[$eventType])) {
            $clone->onceKeysToRemove[$eventType] = [];
        }
        $clone->onceKeysToRemove[$eventType][] = $onceKey;

        return $clone;
    }

    /**
     * Remove an event handler.
     *
     * @param class-string<Event>|string $eventType
     */
    public function off(string $eventType, ?callable $handler = null): self
    {
        $clone = clone $this;
        if ($handler === null) {
            unset($clone->listeners[$eventType]);
        } elseif (isset($clone->listeners[$eventType])) {
            $clone->listeners[$eventType] = array_filter(
                $clone->listeners[$eventType],
                fn($h) => $h !== $handler
            );
        }
        return $clone;
    }

    /**
     * Dispatch an event to all registered handlers.
     *
     * @template T of Event
     * @param T $event
     * @return T The event (possibly modified by handlers)
     */
    public function dispatch(Event $event): Event
    {
        $eventType = $event->getType();
        $handlers = $this->listeners[$eventType] ?? [];

        foreach ($handlers as $handler) {
            $result = $handler($event);
            // If handler returns an Event, use that instead
            if ($result instanceof Event) {
                $event = $result;
            }
        }

        // Remove once handlers that were marked for removal
        if (isset($this->onceKeysToRemove[$eventType])) {
            foreach ($this->onceKeysToRemove[$eventType] as $key) {
                unset($this->listeners[$eventType][$key]);
            }
            unset($this->onceKeysToRemove[$eventType]);
        }

        return $event;
    }

    /**
     * Check if there are listeners for a given event type.
     */
    public function hasListeners(string $eventType): bool
    {
        return isset($this->listeners[$eventType])
            && $this->listeners[$eventType] !== [];
    }

    /**
     * Get all registered event types.
     *
     * @return list<string>
     */
    public function getEventTypes(): array
    {
        return array_keys(array_filter($this->listeners));
    }

    /**
     * Remove all listeners.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->listeners = [];
        return $clone;
    }
}
