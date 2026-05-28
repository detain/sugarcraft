<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Scores character matches (higher for consecutive matches), penalizes
 * gaps and mismatches, and returns candidates sorted by score descending.
 * The key feature: ranked matches WITH scored matched character indices,
 * so UI filter highlighting becomes possible.
 *
 * Mirrors charmbracelet/fuzzy.Candidate pattern used by bubble tea filter models.
 *
 * @see https://github.com/sahilm/fuzzy
 * @see https://github.com/charmbracelet/bubbletea
 */
interface FuzzyMatcher
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
