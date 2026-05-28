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
     * @param list<int>  $matchedIndices 0-based character indices of matched chars in haystack
     */
    public function __construct(
        public readonly string $needle,
        public readonly string $haystack,
        public readonly int $score,
        public readonly array $matchedIndices,
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
     * @return list<int> 0-based character indices of matched chars
     */
    public function indices(): array
    {
        return $this->matchedIndices;
    }
}
