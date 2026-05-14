<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Foundation;

/**
 * A single character cell with associated style.
 */
final readonly class Cell
{
    public function __construct(
        public string $rune,
        public Style $style,
    ) {}
}
