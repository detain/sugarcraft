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
 * Features: separator bonus, camelCase bonus, first-character bonus, lower-case
 * bonus, consecutive match bonus.
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

    /**
     * Flipped {@see self::SEPARATOR_CHARS} for O(1) isset lookup (finding
     * sugar-fuzzy-018: the in_array scan linearly walked 7 strings per
     * matched char). Keep the two constants in sync — the list is the
     * roster of record, the map is its index.
     */
    private const SEPARATOR_LOOKUP = [
        '_' => true, '-' => true, ' ' => true, '.' => true,
        '/' => true, '\\' => true, ':' => true,
    ];

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
     * Copy of this matcher with case sensitivity switched (finding
     * sugar-fuzzy-022: tuning a matcher meant rebuilding it).
     */
    public function withCaseSensitive(bool $caseSensitive = true): self
    {
        return $this->mutate(caseSensitive: $caseSensitive);
    }

    /**
     * Copy of this matcher using $scoring for the bonus weights.
     */
    public function withScoring(SahilmScoring $scoring): self
    {
        return $this->mutate(scoring: $scoring);
    }

    /**
     * The single copy-and-replace door for the fluent `with*()` family.
     *
     * A null slot means "inherit". The constructor resolves a null scoring to
     * canonical, so mutate passes the (already-resolved) current one through.
     */
    private function mutate(
        ?bool $caseSensitive = null,
        ?SahilmScoring $scoring = null,
    ): self {
        return new self(
            $caseSensitive ?? $this->caseSensitive,
            $scoring ?? $this->scoring,
        );
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
        // Batch-invariant query work resolved ONCE (finding sugar-fuzzy-020:
        // the comparison split was rebuilt per candidate). A null covers the
        // empty query — every candidate would fail — so the batch is empty.
        $preparedQuery = $this->prepareQuery($query);
        if ($preparedQuery === null) {
            return [];
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $result = $this->compute($query, $candidate, $preparedQuery);
            if ($result !== null && $result->score >= $minScore) {
                $results[] = $result;
            }
        }

        return MatchResultSorter::sortAndSlice($results, $limit);
    }

    /**
     * Match a query against an iterable of candidates, yielding ranked results.
     *
     * LAZINESS LIMITATION: ranking is a global property, so this drains and
     * sorts the whole input before the first `yield` (see
     * {@see SmithWatermanMatcher::matchAllGenerator()} for the full story).
     * Output is identical to {@see self::matchAll()}.
     *
     * @param string    $query      The search query
     * @param iterable<string> $candidates Candidate strings to score
     * @param int|null  $limit      Maximum number of results to return (null = unlimited)
     * @param int       $minScore   Minimum score threshold (default 1; scores are integers so >= 1 ≡ > 0)
     * @return \Generator<MatchResult> Yields MatchResult in ranked order
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
     * Query-side preparation, hoisted out of the per-candidate loop (finding
     * sugar-fuzzy-020).
     *
     * Per-char folded split, 1:1 with the ORIGINAL code points. Lowercasing
     * the whole string first would expand U+0130 (İ → i + U+0307), shifting
     * every later index and mis-addressing the $cOrig bonus reads in
     * {@see self::compute()}. Case-sensitive mode compares original chars
     * directly.
     *
     * @return array{0: list<string>, 1: int}|null [comparison chars, query length];
     *                                             null for an empty query
     */
    private function prepareQuery(string $query): ?array
    {
        $queryLen = mb_strlen($query, 'UTF-8');
        if ($queryLen === 0) {
            return null;
        }

        $qLow = $this->caseSensitive ? mb_str_split($query, 1, 'UTF-8') : CharFold::foldSplit($query);

        return [$qLow, $queryLen];
    }

    /**
     * Compute match result with matched indices.
     *
     * @param array{0: list<string>, 1: int}|null $preparedQuery Batch-resolved query side
     *                                                           ({@see self::prepareQuery()});
     *                                                           resolved inline for single-pair callers
     */
    private function compute(string $query, string $candidate, ?array $preparedQuery = null): ?MatchResult
    {
        $preparedQuery ??= $this->prepareQuery($query);
        if ($preparedQuery === null) {
            return null;
        }
        [$qLow, $queryLen] = $preparedQuery;

        $candidateLen = mb_strlen($candidate, 'UTF-8');
        if ($candidateLen === 0) {
            return null;
        }

        // The candidate is split ONCE (finding sugar-fuzzy-041: it was
        // mb_str_split twice — once for the comparison side, once for $cOrig).
        // Case-sensitive mode needs the original chars for both, so the two
        // slots alias the single list; both are read-only from here on.
        $cOrig = mb_str_split($candidate, 1, 'UTF-8');
        $cLow = $this->caseSensitive ? $cOrig : CharFold::foldSplit($candidate);

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
                    if (isset(self::SEPARATOR_LOOKUP[$prevCharLower])) {
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
        // FUZZY-18 ASCII fast path (finding sugar-fuzzy-018): single-byte
        // chars classify from ord() — identical verdicts to the mb
        // round-trip below (a-z lower; every other ASCII byte is not,
        // matching 'equals lower AND differs from upper'), at O(1).
        if (strlen($char) === 1) {
            $o = ord($char);
            if ($o < 0x80) {
                return $o >= 0x61 && $o <= 0x7A;
            }
        }
        // Round-trip: lowercase iff it equals mb_strtolower($char) and differs from
        // mb_strtoupper($char) (second clause excludes case-less chars like digits/CJK).
        return $char === mb_strtolower($char, 'UTF-8')
            && $char !== mb_strtoupper($char, 'UTF-8');
    }

    private function isUpperCase(string $char): bool
    {
        if (strlen($char) === 1) {
            $o = ord($char);
            if ($o < 0x80) {
                return $o >= 0x41 && $o <= 0x5A;
            }
        }
        return $char === mb_strtoupper($char, 'UTF-8')
            && $char !== mb_strtolower($char, 'UTF-8');
    }
}
