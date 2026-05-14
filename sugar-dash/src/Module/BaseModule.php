<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Abstract base class for modules with default behavior.
 *
 * Provides sensible defaults for all Module interface methods.
 * Subclasses only need to implement the core name(), view(), and update() methods.
 */
abstract class BaseModule implements Module
{
    protected array $state = [];

    /**
     * {@inheritdoc}
     */
    public function init(): array
    {
        return [
            'name' => $this->name(),
            'minSize' => $this->minSize(),
            'interval' => 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function update(array $state): array
    {
        return $state;
    }

    /**
     * {@inheritdoc}
     */
    public function minSize(): array
    {
        return [30, 4];
    }

    /**
     * Get current module state.
     */
    protected function getState(): array
    {
        return $this->state;
    }

    /**
     * Set current module state.
     */
    protected function setState(array $state): void
    {
        $this->state = $state;
    }
}
