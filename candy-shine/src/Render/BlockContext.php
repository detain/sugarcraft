<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

use SugarCraft\Sprinkles\Style;

/**
 * Immutable context record pushed onto the BlockStack when entering a block.
 *
 * `accumulatedIndent` is this block's OWN share of the horizontal indent —
 * the cells it prefixes to its content lines beyond what the stack already
 * charges for its kind — never the parent's total: {@see BlockStack} sums
 * the shares of every open context, so a copied parent total would be
 * charged twice. Styles do cascade: `cascadedStyle` carries the parent's
 * style merged with this level's via StyleCascade.
 */
final readonly class BlockContext
{
    public function __construct(
        public BlockKind $kind,
        public int $depth,
        public int $accumulatedIndent,
        public Style $cascadedStyle,
    ) {}
}
