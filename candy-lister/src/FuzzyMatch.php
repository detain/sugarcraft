<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

/**
 * Scoring constants for FuzzyMatch algorithm.
 *
 * Allows tuning the Smith-Waterman scoring parameters for different
 * matching behaviors (strict vs. lenient matching).
 */
final class ScoringProfile
{
    public function __construct(
        public readonly int $matchScore = 3,
        public readonly int $mismatchPenalty = -3,
        public readonly int $gapOpen = -5,
        public readonly int $gapExtend = -1,
        public readonly int $adjacentBonus = 5,
    ) {}

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
}

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Scores candidates using a two-row DP matrix for memory efficiency.
 * Higher scores indicate better matches; scores <= 0 indicate no match.
 *
 * ## CancellationToken support (intended API — requires candy-async)
 * When candy-async is installed, match() will accept an optional
 * `?CancellationToken $token = null` parameter. Scoring is batched
 * (e.g., 100 candidates per futureTick) via loop()->futureTick().
 * If cancelled, throws `SugarCraft\Async\OperationCancelledException`.
 *
 * ## matchAsync() (future work)
 * Consider adding matchAsync() that scores candidates in batches using
 * React\Promise\Deferred and loop()->futureTick() for time-slicing
 * on large candidate lists.
 */
final class FuzzyMatch
{
    private ScoringProfile $profile;

    public function __construct(?ScoringProfile $profile = null)
    {
        $this->profile = $profile ?? ScoringProfile::default();
    }

    /**
     * Set a custom scoring profile.
     */
    public function withProfile(ScoringProfile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        return $clone;
    }

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     *
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously).
     *
     * Uses \mb_strtolower(..., 'UTF-8') for locale-independent Unicode
     * case folding (vs. strtolower which is locale-dependent).
     *
     * @param string $query     The search query (needle)
     * @param string $candidate The candidate string to score
     * @return int The alignment score (higher = better match)
     */
    public function score(string $query, string $candidate): int
    {
        if ($query === '') {
            return 0;
        }
        if ($candidate === '') {
            return 0;
        }

        // Locale-independent Unicode case folding via mb_strtolower
        $q = \mb_strtolower($query, 'UTF-8');
        $c = \mb_strtolower($candidate, 'UTF-8');
        $queryLen = \mb_strlen($q, 'UTF-8');
        $candidateLen = \mb_strlen($c, 'UTF-8');

        // Two-row Smith-Waterman for memory efficiency
        $prevRow = array_fill(0, $candidateLen + 1, 0);
        $currRow = array_fill(0, $candidateLen + 1, 0);

        $maxScore = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            $qChar = \mb_substr($q, $i - 1, 1, 'UTF-8');
            for ($j = 1; $j <= $candidateLen; $j++) {
                $cChar = \mb_substr($c, $j - 1, 1, 'UTF-8');

                $match = $qChar === $cChar
                    ? $this->profile->matchScore
                    : $this->profile->mismatchPenalty;

                // Consecutive character match bonus
                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1) {
                    $prevQChar = \mb_substr($q, $i - 2, 1, 'UTF-8');
                    $prevCChar = \mb_substr($c, $j - 2, 1, 'UTF-8');
                    if ($prevQChar === $prevCChar) {
                        $adjBonus = $this->profile->adjacentBonus;
                    }
                }

                $effectiveMatch = $match + $adjBonus;
                $scoreDiag = $prevRow[$j - 1] + $effectiveMatch;
                $scoreUp = $currRow[$j - 1] + ($currRow[$j - 1] === 0 ? $this->profile->gapOpen : $this->profile->gapExtend);
                $scoreLeft = $prevRow[$j] + ($prevRow[$j] === 0 ? $this->profile->gapOpen : $this->profile->gapExtend);

                $cell = max(0, $scoreDiag, $scoreUp, $scoreLeft);
                $currRow[$j] = $cell;

                if ($cell > $maxScore) {
                    $maxScore = $cell;
                }
            }
            // Swap rows
            $temp = $prevRow;
            $prevRow = $currRow;
            $currRow = $temp;
        }

        return $maxScore;
    }

    /**
     * Filter and rank candidates by fuzzy match score against the query.
     *
     * Returns candidates sorted by score descending (best matches first).
     * Only returns candidates with a score > 0.
     *
     * @param string          $query      The search query
     * @param list<\Stringable> $items    List of Stringable items to score
     * @return list<array{\Stringable, int}> List of [item, score] pairs sorted by score desc
     */
    public function match(string $query, array $items): array
    {
        if ($query === '' || $items === []) {
            return [];
        }

        $scored = [];
        foreach ($items as $item) {
            $score = $this->score($query, (string) $item);
            if ($score > 0) {
                $scored[] = [$item, $score];
            }
        }

        // Sort by score descending
        usort($scored, static fn(array $a, array $b) => $b[1] <=> $a[1]);

        return $scored;
    }
}
