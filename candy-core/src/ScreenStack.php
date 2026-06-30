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
     * Note: callers should invoke $screen->onEnter() if needed.
     */
    public function push(Screen $screen): self
    {
        return new self([...$this->screens, $screen]);
    }

    /**
     * Pop the current screen (if any).
     * Note: callers should invoke $screen->onExit() if needed.
     * Returns a new stack. When the stack is empty, returns $this unchanged.
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
     * Return the number of screens in the stack.
     */
    public function count(): int
    {
        return count($this->screens);
    }
}
