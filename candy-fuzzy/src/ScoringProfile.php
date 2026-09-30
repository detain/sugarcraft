<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Scoring constants for the Smith-Waterman fuzzy matcher.
 *
 * Immutable value object tuning the local-alignment scoring parameters, so a
 * single matcher class can serve strict, default, and lenient matching modes
 * without duplicating the algorithm.
 *
 * The {@see self::default()} values are the canonical SugarCraft scores and are
 * bit-equivalent to the historical hard-coded constants in
 * `SugarCraft\Forms\Fuzzy\FuzzyMatcher` and `SugarCraft\Lister\FuzzyMatch`,
 * so passing the default profile (or none) preserves existing matcher output
 * byte-for-byte.
 *
 * This class is the scoring SSOT for the ecosystem: `SugarCraft\Lister\ScoringProfile`
 * is now a `class_alias` to it, and the deprecated
 * `SugarCraft\Forms\Fuzzy\FuzzyMatcher` shim delegates to
 * {@see \SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher}, which defaults here.
 */
final class ScoringProfile
{
    /**
     * @param int $matchScore      Reward for a matched character pair (diagonal)
     * @param int $mismatchPenalty Penalty for a mismatched character pair
     * @param int $gapOpen         Penalty for opening an alignment gap
     * @param int $gapExtend       Penalty for extending an existing gap
     * @param int $adjacentBonus   Extra reward when the previous pair also matched (consecutive run)
     */
    public function __construct(
        public readonly int $matchScore = 3,
        public readonly int $mismatchPenalty = -3,
        public readonly int $gapOpen = -5,
        public readonly int $gapExtend = -1,
        public readonly int $adjacentBonus = 5,
    ) {}

    /**
     * Root factory (repo convention). Equivalent to the constructor.
     *
     * Forwards ALL arguments — positional or named — so the constructor's
     * promoted defaults stay the single source of the canonical profile;
     * re-listing them here would let the two literal sets silently fork.
     * Wrong arity or types fail in the constructor with a TypeError.
     */
    public static function new(mixed ...$arguments): self
    {
        return new self(...$arguments);
    }

    /**
     * Canonical SugarCraft scores. Bit-equivalent to the pre-SSOT hard-coded
     * constants — the SmithWatermanMatcher default profile.
     */
    public static function default(): self
    {
        return new self();
    }

    /** Tighter matching — higher rewards, harsher penalties. */
    public static function strict(): self
    {
        return new self(
            matchScore: 4,
            mismatchPenalty: -4,
            gapOpen: -6,
            gapExtend: -2,
            adjacentBonus: 6,
        );
    }

    /** Lenient matching — lower rewards, gentler penalties. */
    public static function lenient(): self
    {
        return new self(
            matchScore: 2,
            mismatchPenalty: -2,
            gapOpen: -3,
            gapExtend: -1,
            adjacentBonus: 3,
        );
    }

    public function withMatchScore(int $matchScore): self
    {
        return new self($matchScore, $this->mismatchPenalty, $this->gapOpen, $this->gapExtend, $this->adjacentBonus);
    }

    public function withMismatchPenalty(int $mismatchPenalty): self
    {
        return new self($this->matchScore, $mismatchPenalty, $this->gapOpen, $this->gapExtend, $this->adjacentBonus);
    }

    public function withGapOpen(int $gapOpen): self
    {
        return new self($this->matchScore, $this->mismatchPenalty, $gapOpen, $this->gapExtend, $this->adjacentBonus);
    }

    public function withGapExtend(int $gapExtend): self
    {
        return new self($this->matchScore, $this->mismatchPenalty, $this->gapOpen, $gapExtend, $this->adjacentBonus);
    }

    public function withAdjacentBonus(int $adjacentBonus): self
    {
        return new self($this->matchScore, $this->mismatchPenalty, $this->gapOpen, $this->gapExtend, $adjacentBonus);
    }
}
