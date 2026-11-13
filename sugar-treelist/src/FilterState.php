<?php

declare(strict_types=1);

namespace SugarCraft\TreeList;

/**
 * The filter state of a list model — deliberately a two-case machine.
 *
 * Transitions:
 * - unfiltered → filtering  (withFilterFn()/withFuzzyFilter() installs the predicate)
 * - filtering → unfiltered  (withoutFilter()/withoutFuzzyFilter() clears it)
 *
 * Upstream models a third FilterApplied state bridging "a predicate is
 * installed" and "it has been run over the authoritative storage" — a window
 * that exists there because filter-aware mutations can land on the view.
 * This port keeps the pristine list as the single authoritative storage and
 * re-derives the projection on every mutation, so configuration and
 * application are the same clone transition and a third state would describe
 * no behavior (see the Filtering section of the README).
 */
enum FilterState
{
    /** No filter is active — all items are shown. */
    case unfiltered;

    /** A filter function is configured and actively filtering. */
    case filtering;
}
