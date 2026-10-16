<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Fuzzy;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Backward-compatibility shim. The former hand-rolled two-row DP core has been
 * removed; all scoring now delegates to the canonical candy-fuzzy SSOT
 * {@see SmithWatermanMatcher} with its default {@see \SugarCraft\Fuzzy\ScoringProfile},
 * which is bit-equivalent to the historical constants for ASCII input. This
 * class survives only to preserve the legacy `match(string, array): list<[string, int]>`
 * ranking shape that {@see SmithWatermanMatcher::match()} no longer exposes, and the
 * legacy negative empty-candidate score (the SSOT returns 0 there).
 *
 * @deprecated since 1.x, use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher
 * @see SmithWatermanMatcher
 */
final class FuzzyMatcher
{
    private readonly SmithWatermanMatcher $delegate;

    public function __construct()
    {
        $this->delegate = SmithWatermanMatcher::new();
    }

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously).
     *
     * @param string $query    The search query (needle)
     * @param string $candidate The candidate string to score
     * @return int The alignment score (higher = better match)
     */
    public function score(string $query, string $candidate): int
    {
        if ($query === '') {
            return 0;
        }

        // Legacy boundary: an empty candidate yields a gap-only NEGATIVE score
        // (gap-open + one gap-extend per query char), whereas the SSOT returns 0.
        // Preserved so direct score() callers keep byte-identical results.
        if ($candidate === '') {
            $profile = $this->delegate->profile();

            return $profile->gapOpen + ($profile->gapExtend * strlen($query));
        }

        return $this->delegate->score($query, $candidate);
    }

    /**
     * Filter and rank candidates by fuzzy match score against the query.
     * Returns candidates sorted by score descending (best matches first).
     * Only returns candidates with a score > 0.
     *
     * @param string $query     The search query
     * @param list<string> $candidates List of candidate strings
     * @return list<array{string, int}> List of [candidate, score] pairs sorted by score desc
     */
    public function match(string $query, array $candidates): array
    {
        if ($query === '' || $candidates === []) {
            return [];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = $this->score($query, $candidate);
            if ($score > 0) {
                $scored[] = [$candidate, $score];
            }
        }

        // Sort by score descending (usort is stable on PHP 8+, so equal scores
        // retain input order — matching the legacy ranking byte-for-byte).
        usort($scored, static fn(array $a, array $b) => $b[1] <=> $a[1]);

        return $scored;
    }
}
