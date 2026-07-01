<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;

/**
 * Smith-Waterman-style local alignment fuzzy matcher.
 *
 * Bit-equivalent in score and ranking to the original
 * SugarCraft\Forms\Fuzzy\FuzzyMatcher implementation.
 * Added: traceback walk to capture matched character indices.
 *
 * PERFORMANCE NOTE: This implementation allocates a full O(queryLen × candidateLen)
 * scoring matrix and traceback matrix in memory. For queries or candidates exceeding
 * ~1,000 characters, consider using SahilmMatcher (O(1) memory) instead, or chunk
 * large candidate lists to avoid excessive memory pressure.
 *
 * @implements FuzzyMatcher
 */
final class SmithWatermanMatcher implements FuzzyMatcher
{
    private const MATCH_SCORE = 3;
    private const MISMATCH_PENALTY = -3;
    private const GAP_OPEN = -5;
    private const GAP_EXTEND = -1;
    private const ADJACENT_BONUS = 5;

    /**
     * Match a single candidate against the query.
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score (haystack)
     * @return MatchResult|null MatchResult with score + indices, or null if no match
     */
    public function match(string $query, string $candidate): ?MatchResult
    {
        if ($query === '' || $candidate === '') {
            return null;
        }

        $result = $this->compute($query, $candidate);
        if ($result === null || $result->score <= 0) {
            return null;
        }

        return $result;
    }

    /**
     * Match a query against an iterable of candidates, returning ranked results.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (null = unlimited)
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return array<MatchResult> Ranked match results
     */
    public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
    {
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $result = $this->compute($query, $candidate);
            if ($result !== null && $result->score >= $minScore) {
                $results[] = $result;
            }
        }

        return MatchResultSorter::sortAndSlice($results, $limit);
    }

    /**
     * Match a query against an iterable of candidates, yielding results as they are found.
     *
     * Generator-based iteration for memory efficiency with large candidate lists.
     * Results are yielded as they are computed, then sorted and sliced at the end.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (null = unlimited)
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return \Generator<MatchResult> Yields MatchResult as they are computed
     */
    public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
    {
        if ($query === '') {
            return;
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $result = $this->compute($query, $candidate);
            if ($result !== null && $result->score >= $minScore) {
                $results[] = $result;
            }
        }

        foreach (MatchResultSorter::sortAndSlice($results, $limit) as $result) {
            yield $result;
        }
    }

    /**
     * Compute match result with traceback for matched indices.
     *
     * LOCAL ALIGNMENT CONSTRAINT: This implementation requires every query character
     * to appear in the candidate for a match (queryLen must be <= candidateLen).
     * This is a stricter requirement than classical Smith-Waterman local alignment,
     * which can find partial matches. The algorithm bails early when query > candidate.
     */
    private function compute(string $query, string $candidate): ?MatchResult
    {
        $queryLen = mb_strlen($query, 'UTF-8');
        $candidateLen = mb_strlen($candidate, 'UTF-8');

        if ($queryLen === 0 || $candidateLen === 0) {
            return null;
        }

        // A full-query alignment requires every query char to appear in the candidate;
        // if the query is longer than the candidate, no such alignment exists.
        if ($queryLen > $candidateLen) {
            return null;
        }

        // Pre-split once — lowercasing the whole string once is equivalent to
        // lowercasing each char; eliminates per-cell mb_substr/mb_strtolower in the hot loop.
        $q = mb_str_split(mb_strtolower($query, 'UTF-8'));
        $c = mb_str_split(mb_strtolower($candidate, 'UTF-8'));

        // Build full scoring matrix for traceback
        // Matrix is (queryLen+1) x (candidateLen+1), initialized to 0
        $matrix = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));

        // Track where each score came from: 0=init, 1=diag, 2=up, 3=left
        $traceback = array_fill(0, $queryLen + 1, array_fill(0, $candidateLen + 1, 0));

        $maxScore = 0;
        $maxI = 0;
        $maxJ = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            for ($j = 1; $j <= $candidateLen; $j++) {
                $match = $q[$i - 1] === $c[$j - 1]
                    ? self::MATCH_SCORE
                    : self::MISMATCH_PENALTY;

                // Add adjacent bonus for consecutive character matches in sequence
                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1) {
                    if ($q[$i - 2] === $c[$j - 2]) {
                        $adjBonus = self::ADJACENT_BONUS;
                    }
                }

                $effectiveMatch = $match + $adjBonus;

                $scoreDiag = $matrix[$i - 1][$j - 1] + $effectiveMatch;
                $scoreUp = $matrix[$i][$j - 1] + ($matrix[$i][$j - 1] === 0 ? self::GAP_OPEN : self::GAP_EXTEND);
                $scoreLeft = $matrix[$i - 1][$j] + ($matrix[$i - 1][$j] === 0 ? self::GAP_OPEN : self::GAP_EXTEND);

                $cell = max(0, $scoreDiag, $scoreUp, $scoreLeft);
                $matrix[$i][$j] = $cell;

                // Track origin for traceback
                if ($cell > 0) {
                    if ($cell === $scoreDiag) {
                        $traceback[$i][$j] = 1; // diag
                    } elseif ($cell === $scoreUp) {
                        $traceback[$i][$j] = 2; // up
                    } else {
                        $traceback[$i][$j] = 3; // left
                    }
                }

                if ($cell > $maxScore) {
                    $maxScore = $cell;
                    $maxI = $i;
                    $maxJ = $j;
                }
            }
        }

        if ($maxScore === 0) {
            return null;
        }

        // Traceback to find matched indices
        $indices = $this->traceback($traceback, $matrix, $maxI, $maxJ);

        return new MatchResult(
            needle: $query,
            haystack: $candidate,
            score: $maxScore,
            matchedIndices: $indices,
        );
    }

    /**
     * Traceback from max score position to get matched character indices.
     *
     * @param array<array<int>> $traceback Origin matrix
     * @param array<array<int>> $matrix    Score matrix
     * @param int             $i          Row of max score
     * @param int             $j          Column of max score
     * @return list<int> Character indices of matched chars
     */
    private function traceback(array $traceback, array $matrix, int $i, int $j): array
    {
        $indices = [];
        $currentI = $i;
        $currentJ = $j;

        while ($currentI > 0 && $currentJ > 0 && $traceback[$currentI][$currentJ] !== 0) {
            $origin = $traceback[$currentI][$currentJ];

            if ($origin === 1) {
                // Diagonal - we have a match at position (currentI-1, currentJ-1) in the original strings
                $indices[] = $currentJ - 1;
                $currentI--;
                $currentJ--;
            } elseif ($origin === 2) {
                // Up - gap in query
                $currentJ--;
            } else {
                // Left - gap in candidate
                $currentI--;
            }
        }

        // Indices are collected in reverse order (from end to start of match)
        return array_reverse($indices);
    }
}
