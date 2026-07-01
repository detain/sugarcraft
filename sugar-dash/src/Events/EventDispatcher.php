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
        $handlerObjId = \spl_object_id($handler);
        if (!isset($clone->listeners[$eventType])) {
            $clone->listeners[$eventType] = [];
        }
        $clone->listeners[$eventType][$handlerObjId] = $handler;
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

        // Use object_id as key to avoid index-shift issues after off() calls
        $handlerObjId = \spl_object_id($handler);

        if (!isset($clone->listeners[$eventType])) {
            $clone->listeners[$eventType] = [];
        }
        $clone->listeners[$eventType][$handlerObjId] = $handler;

        if (!isset($clone->onceKeysToRemove[$eventType])) {
            $clone->onceKeysToRemove[$eventType] = [];
        }
        $clone->onceKeysToRemove[$eventType][] = $handlerObjId;

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
            // Use object_id for consistent key-based removal (avoid array_filter key-shift issues)
            $handlerObjId = \is_object($handler) ? \spl_object_id($handler) : null;
            if ($handlerObjId !== null && isset($clone->listeners[$eventType][$handlerObjId])) {
                unset($clone->listeners[$eventType][$handlerObjId]);
            } else {
                // Fallback for non-object callables (strings, arrays) — scan and unset
                foreach ($clone->listeners[$eventType] as $key => $h) {
                    if ($h === $handler) {
                        unset($clone->listeners[$eventType][$key]);
                        break;
                    }
                }
            }
        }
        return $clone;
    }

    /**
     * Dispatch an event to all registered handlers.
     *
     * Returns a tuple of [event, new-dispatcher] to maintain immutability.
     * Once-handlers are removed only in the returned new dispatcher.
     *
     * @template T of Event
     * @param T $event
     * @return array{0: T, 1: self}
     */
    public function dispatch(Event $event): array
    {
        $eventType = $event->getType();
        $handlers = $this->listeners[$eventType] ?? [];

        $clone = clone $this;

        foreach ($handlers as $handler) {
            $result = $handler($event);
            if ($result instanceof Event) {
                $event = $result;
            }
        }

        // Remove once handlers only in the cloned dispatcher (immutable)
        if (isset($clone->onceKeysToRemove[$eventType])) {
            foreach ($clone->onceKeysToRemove[$eventType] as $handlerObjId) {
                unset($clone->listeners[$eventType][$handlerObjId]);
            }
            unset($clone->onceKeysToRemove[$eventType]);
        }

        return [$event, $clone];
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
