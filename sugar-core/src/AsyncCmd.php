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
 */
final readonly class AsyncCmd implements Msg
{
    public function __construct(
        public PromiseInterface $promise,
        public ?float $timeoutSeconds = null,
    ) {
    }
}
