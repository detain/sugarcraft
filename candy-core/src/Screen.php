<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A single screen in a modal / sub-screen workflow.
 *
 * @readonly
 */
final class Screen
{
    /**
     * @param Model $model The Elm-architecture model for this screen.
     * @param string|null $title Optional title for breadcrumb display.
     * @param \Closure(): void|null $onEnter Fired when the screen becomes active.
     * @param \Closure(): void|null $onExit Fired when the screen is popped.
     */
    public function __construct(
        public readonly Model $model,
        public readonly ?string $title = null,
        public readonly ?\Closure $onEnter = null,
        public readonly ?\Closure $onExit = null,
    ) {}
}
