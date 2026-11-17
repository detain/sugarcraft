<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

/**
 * Main-axis distribution of leftover cells in a {@see FlexBox}.
 *
 * Leftover space exists only when no ratio item absorbs it: fixed-basis
 * items, ratio-0 items that keep their 1-cell floor, and no-flex fallbacks.
 * Mirrors CSS `justify-content` for the values a cell grid can express.
 * `Start` is the default and leaves the line unpadded, exactly as before the
 * option existed. The space-* values follow the CSS edge rules: one item
 * under `SpaceBetween` behaves as `Start`, and under `SpaceAround` or
 * `SpaceEvenly` it is centred.
 */
enum Justify
{
    case Start;
    case End;
    case Center;
    case SpaceBetween;
    case SpaceAround;
    case SpaceEvenly;
}
