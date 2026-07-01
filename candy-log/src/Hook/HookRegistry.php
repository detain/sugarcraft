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
     * @var array<int, array{level: Level, callback: callable(Level, string, string, array<mixed>): void}|null>
     * @phpstan-var array<int, array{level: Level, callback: \Closure(Level, string, string, array<mixed>): void}|null>
     */
    private array $handlers = [];

    /** Counter for generating sequential handler keys. */
    private int $nextId = 0;

    /**
     * Register a callback to be invoked for all events at or above $level.
     *
     * @param Level        $level    Minimum level to trigger the callback.
     * @param callable     $callback (Level, string, string, array<mixed>): void
     * @return int                         Sequential registration index — use with remove().
     */
    public function onLevel(Level $level, callable $callback): int
    {
        $id = $this->nextId++;
        $this->handlers[$id] = ['level' => $level, 'callback' => $callback];
        return $id;
    }

    /**
     * Register a Hook object to be invoked for all events at or above $level.
     *
     * @param Level $level  Minimum level to trigger the hook.
     * @param Hook  $hook   Hook implementation to register.
     * @return int          Sequential registration index — use with remove().
     */
    public function addHook(Level $level, Hook $hook): int
    {
        return $this->onLevel($level, [$hook, 'onLevel']);
    }

    /**
     * Remove a registered handler by its ID.
     *
     * After removal, the handler will no longer be invoked when fire() is called.
     * If the ID is invalid or the handler was already removed, this is a no-op.
     *
     * @param int $id  The ID returned by onLevel() or addHook().
     */
    public function remove(int $id): void
    {
        $this->handlers[$id] = null;
    }

    /**
     * Fire all registered handlers whose level matches the given $level.
     *
     * Uses "at or above" semantics — fires for all handlers whose minLevel
     * is less than or equal to the emitted level. There is no way to fire
     * a hook at an exact level only.
     *
     * Note: Hooks fire only via PsrBridge, not when calling Logger directly.
     * Direct Logger calls (e.g., $logger->info()) do not dispatch hooks.
     *
     * @param Level        $level    The level of the emitted log event.
     * @param string       $psrLevel The PSR-3 level string.
     * @param string       $message  The primary log message.
     * @param array<mixed> $context  Key/value pairs attached to the entry.
     * @return void                  This method returns void (nothing).
     */
    public function fire(Level $level, string $psrLevel, string $message, array $context): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler === null) {
                continue;
            }
            if ($level->value >= $handler['level']->value) {
                $handler['callback']($level, $psrLevel, $message, $context);
            }
        }
    }

}
