<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Border;

/**
 * A single title entry on a border.
 *
 * @internal Not part of the public API — rendered by Style::applyBorder().
 */
readonly class BorderTitle
{
    public function __construct(
        public string $text,
        public TitleAnchor $anchor,
        public string $separator = ' ',
    ) {}
}
