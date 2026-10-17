<?php

declare(strict_types=1);

namespace SugarCraft\Core\Progress;

use SugarCraft\Core\ProgressReporter;

/**
 * Progress reporter that delegates to a callable.
 */
final class CallbackProgressReporter implements ProgressReporter
{
    /**
     * @param \Closure(int $current, int $total, ?string $label): void $callback
     */
    public function __construct(
        private readonly \Closure $callback,
    ) {
    }

    public static function new(callable $callback): self
    {
        return new self(\Closure::fromCallable($callback));
    }

    public function report(int $current, int $total, ?string $label = null): void
    {
        ($this->callback)($current, $total, $label);
    }
}
