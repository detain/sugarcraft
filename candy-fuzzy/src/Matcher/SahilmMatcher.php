<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;

/**
 * Sahilm/fuzzy-style fuzzy matcher.
 *
 * Ports the Go fuzzy matching algorithm used by charmbracelet/gum filter.
 * Features: separator bonus, camelCase bonus, exact-prefix bonus, consecutive match bonus.
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
     * @return list<MatchResult> Ranked match results
     */
    public function matchAll(string $query, iterable $candidates): array
    {
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $result = $this->compute($query, $candidate);
            if ($result !== null && $result->score > 0) {
                $results[] = $result;
            }
        }

        // Sort by score descending, then candidate ascending as tiebreak
        usort($results, static fn(MatchResult $a, MatchResult $b) => [
            $b->score <=> $a->score,
            $a->haystack <=> $b->haystack,
        ]);

        return $results;
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

        $indices = [];
        $score = 0;
        $queryIdx = 0;
        $candidateIdx = 0;
        $prevMatch = false;
        $prevMatchedLower = '';
        $prevCandidateLower = '';

        while ($queryIdx < $queryLen && $candidateIdx < $candidateLen) {
            $queryChar = mb_substr($queryLower, $queryIdx, 1, 'UTF-8');
            $candidateChar = mb_substr($candidateLower, $candidateIdx, 1, 'UTF-8');

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
                    $candidateCharOrig = mb_substr($candidate, $candidateIdx, 1, 'UTF-8');
                    $prevCandidateCharOrig = mb_substr($candidate, $candidateIdx - 1, 1, 'UTF-8');
                    if ($this->isLowerCase($candidateCharOrig) && $this->isUpperCase($prevCandidateCharOrig)) {
                        $charScore += self::CAMEL_BONUS;
                    }
                }

                // Lower case bonus
                if ($this->isLowerCase(mb_substr($candidate, $candidateIdx, 1, 'UTF-8'))) {
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
        $ord = mb_ord($char, 'UTF-8');
        return $ord >= 97 && $ord <= 122;
    }

    private function isUpperCase(string $char): bool
    {
        $ord = mb_ord($char, 'UTF-8');
        return $ord >= 65 && $ord <= 90;
    }
}
