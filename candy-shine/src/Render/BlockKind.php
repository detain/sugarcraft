<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Render;

/**
 * Kinds of blocks that can appear in a document tree.
 *
 * Mirrors glamour's block kind taxonomy for StyleSheet lookup.
 */
enum BlockKind
{
    case Document;
    case Heading;
    case Paragraph;
    case BlockQuote;
    case List;
    case ListItem;
    case CodeBlock;
    case Table;
}
