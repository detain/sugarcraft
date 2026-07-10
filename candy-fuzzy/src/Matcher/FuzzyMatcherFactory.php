<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\ScoringProfile;

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
     * @param string              $type    'smith-waterman' or 'sahilm'
     * @param ScoringProfile|null $profile Optional scoring profile — applied to
     *                                     'smith-waterman' only; ignored by 'sahilm',
     *                                     which has its own fixed scoring.
     * @return FuzzyMatcher
     * @throws \InvalidArgumentException If type is unknown
     */
    public static function create(string $type, ?ScoringProfile $profile = null): FuzzyMatcher
    {
        return match ($type) {
            'smith-waterman' => new SmithWatermanMatcher($profile),
            'sahilm' => new SahilmMatcher(),
            default => throw new \InvalidArgumentException("Unknown matcher: $type"),
        };
    }
}
