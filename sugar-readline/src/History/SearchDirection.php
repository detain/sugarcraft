<?php

declare(strict_types=1);

namespace SugarCraft\Readline\History;

/**
 * Which way an incremental history scan walks.
 *
 * The polarity home (finding 091): this enum replaces the int constants
 * TextPrompt::SEARCH_REVERSE / TextPrompt::SEARCH_FORWARD, which lived on
 * the prompt surface and forced any other storage-backed search — or any
 * host with a second surface — to copy the values. Cases are int-backed
 * with the legacy constant values (Reverse = 1, Forward = -1), so the
 * deprecated aliases and any host still passing raw ints map 1:1.
 *
 * Semantics are unchanged: Reverse scans toward older entries (higher
 * indexes in the storage-order list — the readline Ctrl+R leg), Forward
 * toward newer ones (Ctrl+S). An out-of-range start index means the
 * direction is already exhausted; scanHistory returns null, never a
 * clamped rematch (pinned since the search() era).
 *
 * @see HistoryInterface::search()
 */
enum SearchDirection: int
{
    /** Toward older entries (higher indexes) — readline Ctrl+R. */
    case Reverse = 1;

    /** Toward newer entries (lower indexes) — readline Ctrl+S. */
    case Forward = -1;
}
