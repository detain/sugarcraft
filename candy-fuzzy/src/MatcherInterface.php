<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Interface for swap-in fuzzy matching solvers.
 *
 * Mirrors charmbracelet/fuzzy pattern used by bubble tea filter models.
 */
interface MatcherInterface
{
    /**
     * Match a single candidate against the query.
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score (haystack)
     * @return MatchResult|null MatchResult with score + indices, or null if no match
     */
    public function match(string $query, string $candidate): ?MatchResult;

    /**
     * Match a query against an iterable of candidates, returning ranked results.
     *
     * Results are sorted by score descending, then by candidate ascending as tiebreak.
     * Only returns candidates with a score > 0.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @return list<MatchResult> Ranked match results
     */
    public function matchAll(string $query, iterable $candidates): array;
}
