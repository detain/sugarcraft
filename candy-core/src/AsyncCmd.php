<?php

declare(strict_types=1);

namespace SugarCraft\Core;

use React\Promise\PromiseInterface;

/**
 * Sentinel Msg that wraps an async promise.
 * Mirrors charmbracelet/bubbletea Cmd system.
 */
final readonly class AsyncCmd implements Msg
{
    public function __construct(
        public PromiseInterface $promise,
    ) {
    }
}
