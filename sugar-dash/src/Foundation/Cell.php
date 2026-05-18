<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * Sugar-dash Cell holds (rune, Style) for the inline-termui
 * renderer. Intentionally distinct from \SugarCraft\Vt\Cell\Cell,
 * which holds (grapheme, Sgr, continuation, hyperlink) for VT
 * emulation. Both are canonical for their lib.
 *
 * See sugar-dash/CALIBER_LEARNINGS.md entry [pattern:dual-cell-shapes].
 */
final readonly class Cell
{
    public function __construct(
        public string $rune,
        public Style $style,
    ) {}
}
