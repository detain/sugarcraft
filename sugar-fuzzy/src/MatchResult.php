<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Readonly result of a fuzzy match operation.
 *
 * @readonly
 */
final class MatchResult
{
    /**
     * @param string     $needle        The search query that was matched
     * @param string     $haystack      The candidate string that was matched
     * @param int        $score         Numeric score (higher = better match)
     * @param list<int>  $matchedIndices 0-based CODE-POINT indices into the ORIGINAL
     *                                   haystack (never the lowercased comparison space),
     *                                   each naming a char the query genuinely matched
     *                                   (the Smith-Waterman traceback omits mismatch
     *                                   diagonals from this list under exotic profiles
     *                                   whose mismatch penalty is non-negative)
     */
    public function __construct(
        public readonly string $needle,
        public readonly string $haystack,
        public readonly int $score,
        /**
         * @deprecated Read via {@see self::indices()} — one canonical accessor
         *     spelling for the same list (finding sugar-fuzzy-034, house
         *     bare-accessor convention). The property itself STAYS public
         *     readonly: MatchResult is constructed positionally inside the
         *     matchers and external code already reads it; only the
         *     doc-surface spelling changes, no BC break.
         * @var list<int> 0-based CODE-POINT indices into the ORIGINAL haystack
         */
        public readonly array $matchedIndices,
        /**
         * Finding sugar-fuzzy-021: the KEY the candidate had in a batch input.
         * `matchAll()`/`matchAllGenerator()` carry it from ARRAY inputs (a list
         * gives 0..n-1; a hash map gives its own keys — the one field that
         * disambiguates two candidates with identical text); every other route
         * — single-pair `match()`/`score()`, non-array iterables whose keys are
         * iterator-defined, and construction outside the matchers — leaves it
         * null. App-side stores can therefore resolve a hit back to its record
         * without rebuilding a haystack→key map.
         */
        public readonly int|string|null $candidateKey = null,
    ) {}

    /**
     * Whether the match is empty (no characters matched).
     */
    public function isEmpty(): bool
    {
        return $this->matchedIndices === [];
    }

    /**
     * Whether this result represents a valid match (score > 0).
     */
    public function isMatched(): bool
    {
        return $this->score > 0;
    }

    /**
     * @return list<int> 0-based CODE-POINT indices into the ORIGINAL haystack
     *                   (never the lowercased comparison space) — same wording
     *                   as the ctor's `$matchedIndices` contract above; this is
     *                   the canonical accessor (finding sugar-fuzzy-031, house
     *                   bare-accessor convention; the property carries a
     *                   @deprecated doc-surface pointer only).
     */
    public function indices(): array
    {
        return $this->matchedIndices;
    }
}
