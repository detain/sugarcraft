<?php

declare(strict_types=1);

namespace SugarCraft\Log\Hook;

use SugarCraft\Log\Level;

/**
 * Registry for log hooks — collects callbacks per level and fires them
 * when a matching log entry is emitted.
 */
final class HookRegistry
{
    /**
     * @var array<int, array<int, callable(Level, string, string, array<mixed>): void>>
     * @phpstan-var array<int, array<int, \Closure(Level, string, string, array<mixed>): void>>
     */
    private array $handlers = [];

    /** Counter for generating sequential handler keys. */
    private int $nextId = 0;

    /**
     * Register a callback to be invoked for all events at or above $level.
     *
     * @param Level        $level    Minimum level to trigger the callback.
     * @param callable     $callback (Level, string, string, array<mixed>): void
     * @return int                         Sequential registration index (not a removal handle).
     */
    public function onLevel(Level $level, callable $callback): int
    {
        $id = $this->nextId++;
        $this->handlers[$level->value][] = $callback;
        return $id;
    }

    /**
     * Register a Hook object to be invoked for all events at or above $level.
     *
     * @param Level $level  Minimum level to trigger the hook.
     * @param Hook  $hook   Hook implementation to register.
     * @return int          Sequential registration index (not a removal handle).
     */
    public function addHook(Level $level, Hook $hook): int
    {
        return $this->onLevel($level, [$hook, 'onLevel']);
    }

    /**
     * Fire all registered handlers whose level matches the given $level.
     *
     * @param Level        $level    The level of the emitted log event.
     * @param string       $psrLevel The PSR-3 level string.
     * @param string       $message  The primary log message.
     * @param array<mixed> $context  Key/value pairs attached to the entry.
     */
    public function fire(Level $level, string $psrLevel, string $message, array $context): void
    {
        foreach ($this->handlers as $minLevel => $callbacks) {
            if ($level->value >= $minLevel) {
                foreach ($callbacks as $callback) {
                    $callback($level, $psrLevel, $message, $context);
                }
            }
        }
    }

}
