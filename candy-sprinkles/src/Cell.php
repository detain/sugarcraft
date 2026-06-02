<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

/**
 * A single character cell with associated style.
 *
 * Produced by {@see StyleParser} when parsing inline `[text](fg:red)` syntax.
 */
final readonly class Cell
{
    public function __construct(
        public string $rune,
        public Style $style,
    ) {}
}
