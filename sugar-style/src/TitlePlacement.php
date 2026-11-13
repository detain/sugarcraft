<?php

declare(strict_types=1);

namespace SugarCraft\Style;

/**
 * Horizontal alignment of a border title within its allocated space.
 *
 * Named for what it chooses — where a title is placed, not the SGR bytes
 * it carries (finding 052: the old name TitleSgr suggested ANSI escape
 * codes, which this enum never produces). @internal — Style.php keeps
 * this from leaking into public signatures.
 */
enum TitlePlacement
{
    case Left;
    case Center;
    case Right;
}
