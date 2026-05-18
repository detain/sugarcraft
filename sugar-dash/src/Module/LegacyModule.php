<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Legacy module contract — superseded by Module.
 *
 * The old array-state-based update pattern kept for one release
 * so existing third-party modules do not break. New code should
 * implement Module (which aligns with \SugarCraft\Core\Model).
 *
 * @deprecated v0.x — use Module instead
 */
interface LegacyModule
{
    /**
     * Get the module name.
     */
    public function name(): string;

    /**
     * Initialize the module.
     *
     * @return array<string, mixed> Module metadata (name, minSize, interval)
     */
    public function init(): array;

    /**
     * Update the module state.
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
