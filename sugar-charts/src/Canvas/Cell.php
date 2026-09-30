<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Canvas;

use SugarCraft\Sprinkles\Style;

/**
 * One cell of a {@see Canvas}: a single visible rune plus an optional
 * {@see Style} for SGR styling. Cells are immutable; setters on Canvas
 * write fresh instances.
 *
 * `$width` (audit F6 fix) records the cells the rune occupies on the
 * terminal grid: 1 for ordinary glyphs, 2 for a wide CJK/emoji rune (whose
 * right half is stored as a width-0 continuation cell), 0 for such a
 * continuation — emitted as nothing by {@see Canvas::view()} because the
 * wide cell to its left already painted over its column.
 */
final class Cell
{
    public function __construct(
        public readonly string $rune = ' ',
        public readonly ?Style $style = null,
        public readonly int $width = 1,
    ) {}
}
