<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

/**
 * Factory for creating FuzzyMatcher instances by name.
 *
 * Enables runtime matcher selection without coupling callers to concrete classes.
 */
final class FuzzyMatcherFactory
{
    /**
     * Create a matcher by name.
     *
     * @param string $type 'smith-waterman' or 'sahilm'
     * @return FuzzyMatcher
     * @throws \InvalidArgumentException If type is unknown
     */
    public static function create(string $type): FuzzyMatcher
    {
        return match ($type) {
            'smith-waterman' => new SmithWatermanMatcher(),
            'sahilm' => new SahilmMatcher(),
            default => throw new \InvalidArgumentException("Unknown matcher: $type"),
        };
    }
}
