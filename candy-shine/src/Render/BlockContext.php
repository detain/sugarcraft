<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

use SugarCraft\Sprinkles\Style;

/**
 * Immutable context record pushed onto the BlockStack when entering a block.
 *
 * @readonly Children inherit accumulated values from parent contexts; explicit
 * overrides at each level propagate downward via StyleCascade.
 */
final readonly class BlockContext
{
    public function __construct(
        public BlockKind $kind,
        public int $depth,
        public int $availableWidth,
        public int $accumulatedIndent,
        public Style $cascadedStyle,
    ) {}
}
