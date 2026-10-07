<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Utility for sorting MatchResult collections.
 *
 * Sorts by score descending, then by haystack ascending as a tiebreak.
 * This ensures consistent, reproducible ordering for ranked fuzzy match results.
 */
final class MatchResultSorter
{
    /**
     * Sort by score descending, then haystack ascending as tiebreak.
     *
     * Uses the spaceship operator (<=>) for score comparison, then falls
     * through to haystack comparison using the same operator for consistency.
     *
     * Total-order semantics (audit 2026-10-07, pinned in MatchResultSorterTest):
     *  - The haystack tiebreak inherits `<=>`'s numeric-string comparison, so
     *    digit-only haystacks order NUMERICALLY ("10" sorts after "9" ascending),
     *    not lexicographically; "10" vs "2a" mixes types and falls to string order.
     *  - When score AND haystack are both equal the comparator returns 0, and the
     *    pair's relative order is whatever the input held — carried by usort's
     *    stability, guaranteed only since PHP 8.0.0 (composer requires ^8.3, so
     *    the guarantee holds; do not read this as a sort that reorders exact ties).
     *
     * @param array<MatchResult> $results
     * @return array<MatchResult>
     */
    public static function sort(array $results): array
    {
        usort($results, static fn(MatchResult $a, MatchResult $b) =>
            (($b->score <=> $a->score) ?: ($a->haystack <=> $b->haystack))
        );

        return $results;
    }

    /**
     * Sort and slice to limit.
     *
     * Simple full-sort-then-slice — no heap/partial-sort needed for typical
     * TUI list sizes; preserves the stable-tiebreak contract.
     *
     * @param array<MatchResult> $results
     * @param int|null $limit
     * @return array<MatchResult>
     */
    public static function sortAndSlice(array $results, ?int $limit): array
    {
        $results = self::sort($results);

        if ($limit !== null && $limit >= 0) {
            $results = array_slice($results, 0, $limit);
        }

        return $results;
    }
}
