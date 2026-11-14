<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * A single screen in a modal / sub-screen workflow.
 */
final class Screen
{
    /**
     * @param Model $model The Elm-architecture model for this screen.
     * @param string|null $title Optional title for breadcrumb display.
     * @param \Closure(): void|null $onEnter Fired when the screen becomes
     *     active — by {@see RootModelWithScreenStack::update()} on a
     *     ScreenStackPushedMsg; the ScreenStack builder itself never fires
     *     hooks, so a direct stack owner must invoke it (sc-35 wording for
     *     the sugar-core-034/RUN-10 contract, DX-10).
     * @param \Closure(): void|null $onExit Fired when the screen is popped.
     */
    public function __construct(
        public readonly Model $model,
        public readonly ?string $title = null,
        public readonly ?\Closure $onEnter = null,
        public readonly ?\Closure $onExit = null,
    ) {
    }

    /**
     * Return a copy of this screen carrying $model in place of the
     * current one (title and enter/exit hooks preserved).
     *
     * Used by {@see Program} to persist a screen's next model after its
     * update() — the immutability leg that lets pushed screens fold
     * state instead of having their results thrown away (audit
     * lib-reviews-1102 C6).
     */
    public function withModel(Model $model): self
    {
        return new self($model, $this->title, $this->onEnter, $this->onExit);
    }
}
