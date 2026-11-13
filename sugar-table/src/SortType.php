<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * How a column's values compare under Table::sortBy().
 *
 * Mirrors Evertras/bubble-table's SortType (auto/string/natural).
 */
enum SortType
{
    /** Float compare when both sides are numeric, else case-insensitive text (the historical default). */
    case Auto;

    /** Case-insensitive text always, even for numeric-looking values. */
    case Text;

    /** Case-insensitive natural order: digit runs compare numerically ("item2" < "item10"). */
    case Natural;
}
