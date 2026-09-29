<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles;

/**
 * Horizontal alignment of a border title within its allocated space.
 *
 * @internal Used by Style to position titles on the border line.
 */
enum TitleSgr
{
    case Left;
    case Center;
    case Right;
}
