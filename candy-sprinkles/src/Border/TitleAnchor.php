<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Border;

/**
 * Anchor position for a border title.
 *
 * Mirrors ratatui's block title positioning — six positions around
 * the border rectangle.
 */
enum TitleAnchor
{
    case TopLeft;
    case TopCenter;
    case TopRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
}
