<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Flex;

/**
 * Item alignment within a {@see FlexBox}.
 *
 * ONE value drives BOTH axes (measured truth, docs-fix-1023) — there is no
 * separate CSS `justify-content` counterpart here, and rows are always
 * placed top-down. In row mode the case selects the box height (Stretch
 * fills the declared totalHeight, every other case takes the tallest item)
 * AND how each item's text sits inside its allocated cell (Start=left,
 * End=right, Center=middle, Stretch=left-fill). In column mode it selects
 * the width each item's lines render into (Stretch=full declared width,
 * every other case the item's measured width) plus the same horizontal
 * text placement. Content beyond the declared height is clamped, never
 * distributed.
 */
enum Align {
    case Start;
    case Center;
    case End;
    case Stretch;
}
