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
     * FUZZY-13 (finding sugar-fuzzy-008, DX-FUZZY-02 + PARITY-04 + FZ-PHP-03):
     * the option pass-throughs make the canonical consumer shape expressible
     * through the factory alone — until now `named()` forwarded only weights,
     * so 'sw + requireFullQuery' (the filter-UI shape every command picker
     * uses) forced callers to drop to `new SmithWatermanMatcher(...)`, the
     * concrete-class coupling this factory exists to prevent. Options pointed
     * at the wrong matcher throw, mirroring the weight-type refusal (fail
     * fast, never build a silently-un-tuned matcher). Every parameter is
     * defaulted, so all pre-existing calls stay byte-identical.
     *
     * NOT INTERCHANGEABLE (finding sugar-fuzzy-015): 'smith-waterman' returns
     * PARTIAL alignments, is capped at query 128 / candidate 1000 by default,
     * and can score a query that never fully appears; 'sahilm' is linear-time,
     * uncapped, and requires every query char in the candidate. Naming a
     * different type behind this factory changes what a filter lists — pass
     * requireFullQuery: true to bring 'smith-waterman' closest to the Sahilm
     * whole-query contract. See {@see SmithWatermanMatcher::forFiltering()}.
     *
     * @param string                           $type                'smith-waterman' or 'sahilm'
     * @param ScoringProfile|SahilmScoring|null $scoring             Optional weights for that matcher
     * @param bool                             $requireFullQuery    'smith-waterman' only: reject partial alignments
     * @param int|null                         $maxQueryLength      'smith-waterman' only: DoS cap (null = default 128)
     * @param int|null                         $maxCandidateLength  'smith-waterman' only: DoS cap (null = default 1000)
     * @param bool                             $caseSensitive       'sahilm' only: exact-case matching
     * @return FuzzyMatcher
     * @throws \InvalidArgumentException If the type is unknown, the weights belong to the other
     *                                   matcher, or an option is passed to a matcher that has no such knob
     */
    public static function named(
        string $type,
        ScoringProfile|SahilmScoring|null $scoring = null,
        bool $requireFullQuery = false,
        ?int $maxQueryLength = null,
        ?int $maxCandidateLength = null,
        bool $caseSensitive = false,
    ): FuzzyMatcher {
        return match ($type) {
            'smith-waterman' => self::smithWaterman($scoring, $requireFullQuery, $maxQueryLength, $maxCandidateLength, $caseSensitive),
            'sahilm' => self::sahilm($scoring, $requireFullQuery, $maxQueryLength, $maxCandidateLength, $caseSensitive),
            default => throw new \InvalidArgumentException("Unknown matcher: $type"),
        };
    }

    private static function smithWaterman(
        ScoringProfile|SahilmScoring|null $scoring,
        bool $requireFullQuery,
        ?int $maxQueryLength,
        ?int $maxCandidateLength,
        bool $caseSensitive,
    ): SmithWatermanMatcher {
        if ($scoring instanceof SahilmScoring) {
            throw new \InvalidArgumentException(
                "SahilmScoring applies to 'sahilm' only; 'smith-waterman' takes a ScoringProfile",
            );
        }

        if ($caseSensitive) {
            throw new \InvalidArgumentException(
                "caseSensitive is a 'sahilm' option; SmithWaterman scoring is case-folded through CharFold — use 'sahilm' with caseSensitive: true",
            );
        }

        return new SmithWatermanMatcher(
            $scoring,
            $maxQueryLength ?? SmithWatermanMatcher::DEFAULT_MAX_QUERY_LENGTH,
            $maxCandidateLength ?? SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH,
            $requireFullQuery,
        );
    }

    private static function sahilm(
        ScoringProfile|SahilmScoring|null $scoring,
        bool $requireFullQuery,
        ?int $maxQueryLength,
        ?int $maxCandidateLength,
        bool $caseSensitive,
    ): SahilmMatcher {
        if ($scoring instanceof ScoringProfile) {
            throw new \InvalidArgumentException(
                "ScoringProfile applies to 'smith-waterman' only; 'sahilm' takes a SahilmScoring",
            );
        }

        if ($requireFullQuery || $maxQueryLength !== null || $maxCandidateLength !== null) {
            throw new \InvalidArgumentException(
                "requireFullQuery/maxQueryLength/maxCandidateLength are 'smith-waterman' options; SahilmMatcher is linear and full-query by construction — no knobs to pass",
            );
        }

        return new SahilmMatcher($caseSensitive, $scoring);
    }
}
