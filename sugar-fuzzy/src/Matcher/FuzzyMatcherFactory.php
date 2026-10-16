<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Matcher;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\SahilmScoring;
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
     * Each matcher has its own weight type — {@see ScoringProfile} for
     * 'smith-waterman', {@see SahilmScoring} for 'sahilm'. Passing the other
     * matcher's type throws instead of silently building an un-tuned matcher.
     *
     * @param string                           $type    'smith-waterman' or 'sahilm'
     * @param ScoringProfile|SahilmScoring|null $scoring Optional weights for that matcher
     * @return FuzzyMatcher
     * @throws \InvalidArgumentException If the type is unknown, or the weights belong to the other matcher
     */
    public static function named(string $type, ScoringProfile|SahilmScoring|null $scoring = null): FuzzyMatcher
    {
        return match ($type) {
            'smith-waterman' => $scoring instanceof SahilmScoring
                ? throw new \InvalidArgumentException(
                    "SahilmScoring applies to 'sahilm' only; 'smith-waterman' takes a ScoringProfile",
                )
                : new SmithWatermanMatcher($scoring),
            'sahilm' => $scoring instanceof ScoringProfile
                ? throw new \InvalidArgumentException(
                    "ScoringProfile applies to 'smith-waterman' only; 'sahilm' takes a SahilmScoring",
                )
                : new SahilmMatcher(false, $scoring),
            default => throw new \InvalidArgumentException("Unknown matcher: $type"),
        };
    }

    /**
     * Former spelling of {@see self::named()} (`::create()` is a banned
     * factory name under the repo naming rule). Same contract, same throws.
     *
     * The second parameter keeps its original name `$profile` (not
     * `named()`'s `$scoring`): PHP parameter names are API under named
     * arguments, and existing `create('smith-waterman', profile: $p)` calls
     * must keep resolving.
     *
     * @deprecated Use {@see self::named()}.
     * @throws \InvalidArgumentException If the type is unknown, or the weights belong to the other matcher
     */
    public static function create(string $type, ScoringProfile|SahilmScoring|null $profile = null): FuzzyMatcher
    {
        return self::named($type, $profile);
    }
}
