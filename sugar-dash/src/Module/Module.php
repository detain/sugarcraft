<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Module interface for dashboard modules.
 *
 * A module is a self-contained unit that can be rendered in a dashboard.
 * Modules are updated periodically and can display dynamic content.
 *
 * Mirrors the lattice module pattern.
 */
interface Module
{
    /**
     * Get the module name.
     */
    public function name(): string;

    /**
     * Initialize the module.
     *
     * Called once when the module is first loaded.
     *
     * @return array<string, mixed> Module metadata (name, minSize, interval)
     */
    public function init(): array;

    /**
     * Update the module state.
     *
     * Called periodically when Interval > 0 is set in init().
     * Return value is passed to view() on next render.
     *
     * @param array<string, mixed> $state Current module state
     * @return array<string, mixed> Updated state
     */
    public function update(array $state): array;

    /**
     * Render the module view.
     *
     * @param array<string, mixed> $state Current module state
     * @param int $width Available width
     * @param int $height Available height
     * @return string Rendered content
     */
    public function view(array $state, int $width, int $height): string;

    /**
     * Get the minimum size required by this module.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function minSize(): array;
}
