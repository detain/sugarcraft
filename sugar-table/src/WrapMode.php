<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Text wrapping mode for table cells.
 */
enum WrapMode
{
    /** No wrapping — truncate with ellipsis at column width. */
    case None;

    /** Wrap at word boundaries. */
    case WordWrap;

    /** Wrap at character boundaries within column width. */
    case Character;
}
