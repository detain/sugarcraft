<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;

/**
 * Sahilm/fuzzy-style fuzzy matcher.
 *
 * Ports the Go fuzzy matching algorithm used by charmbracelet/gum filter.
 * Features: separator bonus, camelCase bonus, exact-prefix bonus, consecutive match bonus.
 *
 * Greedy first-occurrence matching — advances on the first occurrence of each
 * query char and never backtracks; a scattered early alignment is preferred
 * over a later contiguous run (mirrors sahilm/fuzzy).
 *
 * @see https://github.com/sahilm/fuzzy
 * @implements FuzzyMatcher
 */
final class SahilmMatcher implements FuzzyMatcher
{
    // Scoring constants from sahilm/fuzzy
    private const MATCH_SCORE = 1;
    private const CONSECUTIVE_BONUS = 5;
    private const SEPARATOR_BONUS = 10;
    private const CAMEL_BONUS = 10;
    private const FIRST_CHAR_BONUS = 15;
    private const LOWER_CASE_BONUS = 1;

    private const SEPARATOR_CHARS = ['_', '-', ' ', '.', '/', '\\', ':'];

    private readonly bool $caseSensitive;

    public function __construct(bool $caseSensitive = false)
    {
        $this->caseSensitive = $caseSensitive;
    }

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
     * Compute match result with matched indices.
     */
    private function compute(string $query, string $candidate): ?MatchResult
    {
        $queryLen = mb_strlen($query, 'UTF-8');
        $candidateLen = mb_strlen($candidate, 'UTF-8');

        if ($queryLen === 0 || $candidateLen === 0) {
            return null;
        }

        $queryLower = $this->caseSensitive ? $query : mb_strtolower($query, 'UTF-8');
        $candidateLower = $this->caseSensitive ? $candidate : mb_strtolower($candidate, 'UTF-8');

        // Pre-split once — eliminates per-iteration mb_substr in the hot loop.
        $qLow = mb_str_split($queryLower);
        $cLow = mb_str_split($candidateLower);
        $cOrig = mb_str_split($candidate);

        $indices = [];
        $score = 0;
        $queryIdx = 0;
        $candidateIdx = 0;
        $prevMatch = false;
        $prevCandidateLower = '';

        while ($queryIdx < $queryLen && $candidateIdx < $candidateLen) {
            $queryChar = $qLow[$queryIdx];
            $candidateChar = $cLow[$candidateIdx];

            if ($queryChar === $candidateChar) {
                $charScore = self::MATCH_SCORE;

                // First character match bonus
                if ($candidateIdx === 0) {
                    $charScore += self::FIRST_CHAR_BONUS;
                }

                // Consecutive match bonus
                if ($prevMatch) {
                    $charScore += self::CONSECUTIVE_BONUS;
                }

                // Check for separator bonus (match after separator char)
                if ($prevCandidateLower !== '') {
                    if (in_array($prevCandidateLower, self::SEPARATOR_CHARS, true)) {
                        $charScore += self::SEPARATOR_BONUS;
                    }
                    // CamelCase bonus - current is lowercase but prev was uppercase
                    $candidateCharOrig = $cOrig[$candidateIdx];
                    $prevCandidateCharOrig = $cOrig[$candidateIdx - 1];
                    if ($this->isLowerCase($candidateCharOrig) && $this->isUpperCase($prevCandidateCharOrig)) {
                        $charScore += self::CAMEL_BONUS;
                    }
                }

                // Lower case bonus
                if ($this->isLowerCase($cOrig[$candidateIdx])) {
                    $charScore += self::LOWER_CASE_BONUS;
                }

                $score += $charScore;
                $indices[] = $candidateIdx;

                $prevMatch = true;
                $queryIdx++;
            } else {
                $prevMatch = false;
            }

            $prevCandidateLower = $candidateChar;
            $candidateIdx++;
        }

        // Did we match all query characters?
        if ($queryIdx !== $queryLen) {
            return null;
        }

        return new MatchResult(
            needle: $query,
            haystack: $candidate,
            score: $score,
            matchedIndices: $indices,
        );
    }

    private function isLowerCase(string $char): bool
    {
        // Round-trip: lowercase iff it equals mb_strtolower($char) and differs from
        // mb_strtoupper($char) (second clause excludes case-less chars like digits/CJK).
        return $char === mb_strtolower($char, 'UTF-8')
            && $char !== mb_strtoupper($char, 'UTF-8');
    }

    private function isUpperCase(string $char): bool
    {
        return $char === mb_strtoupper($char, 'UTF-8')
            && $char !== mb_strtolower($char, 'UTF-8');
    }
}
