<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\Promise\PromiseInterface;

/**
 * Sentinel Msg that wraps an async promise.
 * Mirrors charmbracelet/bubbletea.Cmd — the async command system.
 *
 * `$timeoutSeconds`, when set, bounds the wait: the Program rejects the
 * wrapped promise with a {@see \RuntimeException} if it has not settled in
 * time, and the rejection follows the usual ExceptionMsg path.
 *
 * `$onFailure`, when set, replaces that ExceptionMsg path for both a rejected
 * promise and a timeout: the Program dispatches the Msg it returns instead and
 * skips the user exception handler. A scoped producer (candy-code's TabScope)
 * uses it so a timed-out tab promise reaches the owning tab, not the root.
 */
final readonly class AsyncCmd implements Msg
{
    /**
     * @param (\Closure(\Throwable): Msg)|null $onFailure
     */
    public function __construct(
        public PromiseInterface $promise,
        public ?float $timeoutSeconds = null,
        public ?\Closure $onFailure = null,
    ) {
    }
}
