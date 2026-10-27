<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui\Menu;

/**
 * What a {@see MenuNode} IS to the menu: the four shapes a nested-menu row can
 * take. `MenuBar` grew out of a flat `list<string>` and has no shape concept
 * at all (every row was an implied command); the kit needs the discriminator
 * because the cursor may only stop on {@see MenuNode::selectable()} rows, the
 * cascade draws separators and check columns differently, and only a
 * {@see MenuNodeKind::Submenu} row may carry an open panel beneath it.
 */
enum MenuNodeKind
{
    /** A leaf that dispatches something (its id names the action). */
    case Command;

    /** A leaf that flips a boolean: draws the check column, rides {@see MenuNode::withChecked()}. */
    case Toggle;

    /** A row owning children — opens a panel to its right (or left, flipped). */
    case Submenu;

    /** A visual divider: drawn, never highlighted, never chosen. */
    case Separator;
}
