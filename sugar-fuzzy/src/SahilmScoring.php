<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Scoring weights for the sahilm/fuzzy-style {@see \SugarCraft\Fuzzy\Matcher\SahilmMatcher}.
 *
 * Immutable value object — the Sahilm counterpart of {@see ScoringProfile}.
 * The {@see self::canonical()} values (also the constructor defaults) are the
 * constants the matcher always used, so passing none, or the canonical
 * weights, preserves existing output byte-for-byte.
 *
 * Mirrors the bonus-constant shape of sahilm/fuzzy's scorer (first-char,
 * separator, camelCase and adjacent-match bonuses); the canonical VALUES are
 * this port's historical constants, which the characterization suites pin.
 */
final class SahilmScoring
{
    /**
     * @param int $matchScore       Base reward for every matched character
     * @param int $consecutiveBonus Extra reward when the previous candidate char also matched
     * @param int $separatorBonus   Extra reward for a match right after a separator (`_ - space . / \ :`)
     * @param int $camelBonus       Extra reward for a lowercase match right after an uppercase char
     * @param int $firstCharBonus   Extra reward for matching the candidate's first character
     * @param int $lowerCaseBonus   Extra reward when the matched candidate char is lowercase
     */
    public function __construct(
        public readonly int $matchScore = 1,
        public readonly int $consecutiveBonus = 5,
        public readonly int $separatorBonus = 10,
        public readonly int $camelBonus = 10,
        public readonly int $firstCharBonus = 15,
        public readonly int $lowerCaseBonus = 1,
    ) {}

    /**
     * Root factory (repo convention). Forwards every argument, positional or
     * named, so the constructor defaults stay the single source of the
     * canonical weights.
     */
    public static function new(mixed ...$arguments): self
    {
        return new self(...$arguments);
    }

    /** The historical sahilm/fuzzy weights — the SahilmMatcher default. */
    public static function canonical(): self
    {
        return new self();
    }

    public function withMatchScore(int $matchScore): self
    {
        return $this->mutate(matchScore: $matchScore);
    }

    public function withConsecutiveBonus(int $consecutiveBonus): self
    {
        return $this->mutate(consecutiveBonus: $consecutiveBonus);
    }

    public function withSeparatorBonus(int $separatorBonus): self
    {
        return $this->mutate(separatorBonus: $separatorBonus);
    }

    public function withCamelBonus(int $camelBonus): self
    {
        return $this->mutate(camelBonus: $camelBonus);
    }

    public function withFirstCharBonus(int $firstCharBonus): self
    {
        return $this->mutate(firstCharBonus: $firstCharBonus);
    }

    public function withLowerCaseBonus(int $lowerCaseBonus): self
    {
        return $this->mutate(lowerCaseBonus: $lowerCaseBonus);
    }

    private function mutate(
        ?int $matchScore = null,
        ?int $consecutiveBonus = null,
        ?int $separatorBonus = null,
        ?int $camelBonus = null,
        ?int $firstCharBonus = null,
        ?int $lowerCaseBonus = null,
    ): self {
        return new self(
            $matchScore ?? $this->matchScore,
            $consecutiveBonus ?? $this->consecutiveBonus,
            $separatorBonus ?? $this->separatorBonus,
            $camelBonus ?? $this->camelBonus,
            $firstCharBonus ?? $this->firstCharBonus,
            $lowerCaseBonus ?? $this->lowerCaseBonus,
        );
    }
}
