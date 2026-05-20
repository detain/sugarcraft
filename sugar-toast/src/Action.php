<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

/**
 * A clickable action button for an alert.
 *
 * Encapsulates a label and a closure callback that is invoked when the
 * action is triggered.
 */
final class Action
{
    /**
     * @param non-empty-string $label  User-visible button label
     * @param \Closure(): void $callback  Invoked when action is triggered
     */
    public function __construct(
        public readonly string $label,
        public readonly \Closure $callback,
    ) {}

    /**
     * Factory for fluent construction.
     *
     * @param non-empty-string $label
     * @param \Closure(): void $callback
     */
    public static function make(string $label, \Closure $callback): self
    {
        return new self($label, $callback);
    }
}
