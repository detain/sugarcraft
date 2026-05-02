<?php

declare(strict_types=1);

namespace CandyCore\Charts\Canvas;

use CandyCore\Sprinkles\Style;

/**
 * One cell of a {@see Canvas}: a single visible rune plus an optional
 * {@see Style} for SGR styling. Cells are immutable; setters on Canvas
 * write fresh instances.
 */
final class Cell
{
    public function __construct(
        public readonly string $rune = ' ',
        public readonly ?Style $style = null,
    ) {}
}
