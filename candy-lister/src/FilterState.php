<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

/**
 * Represents the current filter state of a list model.
 *
 * Transitions:
 * - unfiltered → filtering  (filter function set, filter applied)
 * - filtering → filtered    (filter produced a result)
 * - filtered → unfiltered   (filter function cleared)
 * - filtering → unfiltered  (filter cleared before producing result)
 */
enum FilterState
{
    /** No filter is active — all items are shown. */
    case unfiltered;

    /** A filter function is configured and actively filtering. */
    case filtering;

    /** Filtering produced results; filter remains active. */
    case filtered;
}
