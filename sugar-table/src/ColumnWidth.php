<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Column width specification for table columns.
 *
 * Backed enum for PHP 8.3 compatibility. The actual width parameters are
 * stored separately in the Column object (width for Fixed, percentValue for Percent).
 */
enum ColumnWidth: string
{
    /** Fixed character count width (uses Column.width). */
    case Fixed = 'fixed';

    /** Percentage of total table width (uses Column.percentValue). */
    case Percent = 'percent';

    /** Dynamic width: min-width from content, max from table. */
    case Dynamic = 'dynamic';

    /** Content-based: exactly fit content, compress if needed. */
    case Content = 'content';

    /**
     * Flexible: take an equal share of the width left after Fixed/Percent
     * columns, IGNORING content length (content is truncated to fit). The "fill
     * the rest" column — unlike Dynamic it never grows past its share, so a table
     * with a Flex column and a set width ({@see Table::withWidth()}) renders to an
     * exact, deterministic total width.
     */
    case Flex = 'flex';
}
