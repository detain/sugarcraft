<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

use SugarCraft\Core\Msg;

/**
 * Abstract base class for modules with default behaviour.
 *
 * Provides sensible defaults for all Module interface methods.
 * Subclasses only need to implement name(), update(), and view().
 *
 * State is kept as a private array. Use withState(...) to produce
 * a clone with updated fields — the immutable with*() pattern.
 */
abstract class BaseModule implements Module
{
    /** @var array<string, mixed> */
    private array $state = [];

    /**
     * {@inheritdoc}
     */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * {@inheritdoc}
     *
     * Base implementation returns [self, null] — subclasses override
     * to return a new instance with updated state via withState(...).
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    /**
     * {@inheritdoc}
     *
     * Base implementation returns empty string — subclasses override
     * to render their current state.
     */
    public function view(): string
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function minSize(): array
    {
        return [30, 4];
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Get current module state.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Create a clone of this module with the given state merged in.
     *
     * @param array<string, mixed> $overrides State fields to merge
     * @return static A new instance with merged state
     */
    protected function withState(array $overrides): static
    {
        $clone = clone $this;
        $clone->state = array_merge($this->state, $overrides);
        return $clone;
    }
}
