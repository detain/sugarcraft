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
}
