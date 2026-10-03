<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;
use SugarCraft\Fuzzy\SahilmScoring;

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
 * The bonus weights are tunable through an injected {@see SahilmScoring}; the
 * canonical weights (the default) reproduce the historical fixed constants.
 *
 * @see https://github.com/sahilm/fuzzy
 * @implements FuzzyMatcher
 */
final class SahilmMatcher implements FuzzyMatcher
{
    private const SEPARATOR_CHARS = ['_', '-', ' ', '.', '/', '\\', ':'];

    private readonly bool $caseSensitive;

    private readonly SahilmScoring $scoring;

    /**
     * @param bool $caseSensitive When false (default), matching is case-insensitive but
     *                            bonuses (separator, camelCase, first-char, lower-case) are
     *                            still computed from the ORIGINAL character case in the candidate.
     *                            This matches the behavior of sahilm/fuzzy: bonuses reflect what
     *                            the user actually typed, not the lowercased comparison text.
     * @param SahilmScoring|null $scoring Bonus weights (default: {@see SahilmScoring::canonical()})
     */
    public function __construct(bool $caseSensitive = false, ?SahilmScoring $scoring = null)
    {
        $this->caseSensitive = $caseSensitive;
        $this->scoring = $scoring ?? SahilmScoring::canonical();
    }

    /**
     * Named constructor (repo convention); same options as the constructor.
     */
    public static function new(bool $caseSensitive = false, ?SahilmScoring $scoring = null): self
    {
        return new self($caseSensitive, $scoring);
    }

    /** Whether matching compares the original case. */
    public function caseSensitive(): bool
    {
        return $this->caseSensitive;
    }

    /** The bonus weights in effect. */
    public function scoring(): SahilmScoring
    {
        return $this->scoring;
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
        // Ranking barrier: reuse matchAll so the two paths cannot drift
        // (same discipline as SmithWatermanMatcher::matchAllGenerator()).
        foreach ($this->matchAll($query, $candidates, $limit, $minScore) as $result) {
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

        // Per-char folded split, 1:1 with the ORIGINAL code points. Lowercasing
        // the whole string first would expand U+0130 (İ → i + U+0307), shifting
        // every later index and mis-addressing the $cOrig bonus reads below
        // (see CharFold). Case-sensitive mode compares original chars directly.
        $qLow = $this->caseSensitive ? mb_str_split($query, 1, 'UTF-8') : CharFold::foldSplit($query);
        $cLow = $this->caseSensitive ? mb_str_split($candidate, 1, 'UTF-8') : CharFold::foldSplit($candidate);
        $cOrig = mb_str_split($candidate, 1, 'UTF-8');

        $indices = [];
        $score = 0;
        $queryIdx = 0;
        $candidateIdx = 0;
        $prevMatch = false;
        $prevCharLower = '';

        while ($queryIdx < $queryLen && $candidateIdx < $candidateLen) {
            $queryChar = $qLow[$queryIdx];
            $candidateChar = $cLow[$candidateIdx];

            if ($queryChar === $candidateChar) {
                $charScore = $this->scoring->matchScore;

                // First character match bonus
                if ($candidateIdx === 0) {
                    $charScore += $this->scoring->firstCharBonus;
                }

                // Consecutive match bonus
                if ($prevMatch === true) {
                    $charScore += $this->scoring->consecutiveBonus;
                }

                // Check for separator bonus (match after separator char)
                if ($prevCharLower !== '') {
                    if (in_array($prevCharLower, self::SEPARATOR_CHARS, true)) {
                        $charScore += $this->scoring->separatorBonus;
                    }
                    // CamelCase bonus - current is lowercase but prev was uppercase
                    $candidateCharOrig = $cOrig[$candidateIdx];
                    $prevCandidateCharOrig = $cOrig[$candidateIdx - 1];
                    if ($this->isLowerCase($candidateCharOrig) && $this->isUpperCase($prevCandidateCharOrig)) {
                        $charScore += $this->scoring->camelBonus;
                    }
                }

                // Lower case bonus
                if ($this->isLowerCase($cOrig[$candidateIdx])) {
                    $charScore += $this->scoring->lowerCaseBonus;
                }

                $score += $charScore;
                $indices[] = $candidateIdx;

                $prevMatch = true;
                $queryIdx++;
            } else {
                $prevMatch = false;
            }

            $prevCharLower = $candidateChar;
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
