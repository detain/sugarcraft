<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Immutable stack of screens for modal / sub-screen workflows.
 *
 * @readonly
 */
final class ScreenStack implements \Countable
{
    /** @var list<Screen> */
    private readonly array $screens;

    /**
     * @param list<Screen> $screens
     */
    public function __construct(array $screens = [])
    {
        $this->screens = $screens;
    }

    /**
     * Push a new screen onto the stack.
     *
     * Lifecycle hooks: this builder never fires them. They are the driven
     * root's contract — Program routes ScreenStackPushedMsg to a
     * ScreenStackCapable root, and {@see RootModelWithScreenStack::update()}
     * invokes $screen->onEnter() there (sugar-core-034, RUN-10). A consumer
     * that manipulates the stack directly owns calling the hook itself;
     * silently expecting the builder to fire it is exactly the drift that
     * made hook-only screens look broken.
     */
    public function push(Screen $screen): self
    {
        return new self([...$this->screens, $screen]);
    }

    /**
     * Pop the current screen (if any).
     *
     * Returns a new stack. When the stack is empty, returns $this unchanged —
     * a documented no-op, not a failure (nothing to exit from). Like push(),
     * this builder never fires onExit(); the driven root does, via
     * {@see RootModelWithScreenStack::update()} on ScreenStackPoppedMsg, and
     * direct stack owners must invoke it themselves (sugar-core-034, RUN-10).
     */
    public function pop(): self
    {
        if ($this->screens === []) {
            return $this;
        }
        return new self(array_slice($this->screens, 0, -1));
    }

    /**
     * Return the active screen, or throw if the stack is empty.
     */
    public function current(): Screen
    {
        if ($this->screens === []) {
            throw new \RuntimeException('ScreenStack is empty');
        }
        return $this->screens[count($this->screens) - 1];
    }

    /**
     * Return all screen titles in stack order (oldest first).
     *
     * @return list<string>
     */
    public function breadcrumb(): array
    {
        return array_values(array_filter(
            array_map(static fn (Screen $s): ?string => $s->title, $this->screens)
        ));
    }

    /**
     * Return true if the stack has no screens.
     */
    public function isEmpty(): bool
    {
        return $this->screens === [];
    }

    /**
     * Return a copy of the stack with the CURRENT (topmost) screen's model
     * replaced — title and hooks preserved (audit lib-reviews-1102 C6).
     *
     * This is how {@see Program} persists a pushed screen's next model:
     * without it, the update() result of every non-root screen was
     * silently dropped. On an empty stack, returns the stack unchanged —
     * a late write-back can never mint a screen out of nothing.
     */
    public function replaceCurrentModel(Model $model): self
    {
        if ($this->screens === []) {
            return $this;
        }
        $top = count($this->screens) - 1;
        $next = $this->screens;
        $next[$top] = $next[$top]->withModel($model);
        return new self($next);
    }

    /**
     * Return the number of screens in the stack.
     */
    public function count(): int
    {
        return count($this->screens);
    }
}
