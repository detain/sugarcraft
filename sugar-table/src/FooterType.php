<?php

declare(strict_types=1);

namespace SugarCraft\Table;

/**
 * Footer display type for table pagination.
 *
 * Controls whether the footer shows "Page N of M", "Showing X to Y of Z rows",
 * or both combined.
 */
enum FooterType: string
{
    /** Show "Page N of M" style footer. */
    case Page = 'page';

    /** Show "Showing X to Y of Z rows" style footer. */
    case Rows = 'rows';

    /** Show both page and rows info combined. */
    case Both = 'both';
}
