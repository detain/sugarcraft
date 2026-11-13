<?php

declare(strict_types=1);

namespace SugarCraft\Log\Hook;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Log\Level;
use SugarCraft\Log\Support\Stdio;

/**
 * Registry for log hooks — collects callbacks per level and fires them
 * when a matching log entry is emitted.
 */
final class HookRegistry
{
    /**
     * @var array<int, array{level: Level, callback: callable(Level, string, string, array<mixed>): void}>
     * @phpstan-var array<int, array{level: Level, callback: \Closure(Level, string, string, array<mixed>): void}>
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
     * The entry is dropped outright — removed handlers keep no tombstone slot.
     *
     * @param int $id  The ID returned by onLevel() or addHook().
     */
    public function remove(int $id): void
    {
        unset($this->handlers[$id]);
    }

    /**
     * Fire all registered handlers whose level matches the given $level.
     *
     * Uses "at or above" semantics — fires for all handlers whose minLevel
     * is less than or equal to the emitted level. There is no way to fire
     * a hook at an exact level only.
     *
     * A handler is a third-party callback: its failure must not cancel the
     * handlers behind it and must never propagate to the logging caller
     * (PSR-3: logging never fails loudly). Each dispatch is individually
     * guarded; a throwing handler is reported once as a sanitized notice on
     * STDERR and the walk continues.
     *
     * Note: Hooks fire only via PsrBridge, not when calling Logger directly.
     * Direct Logger calls (e.g., $logger->info()) do not dispatch hooks.
     *
     * @param Level        $level    The level of the emitted log event.
     * @param string       $psrLevel The PSR-3 level string.
     * @param string       $message  The primary log message.
     * @param array<mixed> $context  Key/value pairs attached to the entry.
     */
    public function fire(Level $level, string $psrLevel, string $message, array $context): void
    {
        foreach ($this->handlers as $handler) {
            if ($level->value < $handler['level']->value) {
                continue;
            }
            try {
                $handler['callback']($level, $psrLevel, $message, $context);
            } catch (\Throwable $e) {
                self::reportHookFailure($e);
            }
        }
    }

    /**
     * One-line sanitized STDERR notice for a failed hook. Guarded so a closed
     * STDERR degrades to silence; the payload can never inject extra lines
     * or control sequences. Never throws.
     */
    private static function reportHookFailure(\Throwable $e): void
    {
        // [PORT-01] SAPI-safe: see SugarCraft\Log\Support\Stdio.
        $stderr = Stdio::err();
        if (null === $stderr) {
            return;
        }
        $detail = \str_replace(["\n", "\r", "\t"], ' ', Sanitize::untrusted($e::class . ': ' . $e->getMessage()));
        \fwrite($stderr, '[sugar-log] hook failed: ' . $detail . "\n");
    }

}
