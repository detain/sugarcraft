<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Fuzzy;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * @deprecated since 1.x, use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher
 * @see SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher
 */
final class FuzzyMatcher
{
    private const MATCH_SCORE = 3;
    private const MISMATCH_PENALTY = -3;
    private const GAP_OPEN = -5;
    private const GAP_EXTEND = -1;
    private const ADJACENT_BONUS = 5;

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously).
     *
     * @param string $query    The search query (needle)
     * @param string $candidate The candidate string to score
     * @return int The alignment score (higher = better match)
     */
    public function score(string $query, string $candidate): int
    {
        if ($query === '') {
            return 0;
        }
        if ($candidate === '') {
            return self::GAP_OPEN + (self::GAP_EXTEND * strlen($query));
        }

        $queryLen = strlen($query);
        $candidateLen = strlen($candidate);

        // Use two rows instead of full matrix for memory efficiency
        $prevRow = array_fill(0, $candidateLen + 1, 0);
        $currRow = array_fill(0, $candidateLen + 1, 0);

        $maxScore = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            $qChar = strtolower($query[$i - 1]);
            for ($j = 1; $j <= $candidateLen; $j++) {
                $cChar = strtolower($candidate[$j - 1]);

                $match = $qChar === $cChar
                    ? self::MATCH_SCORE
                    : self::MISMATCH_PENALTY;

                // Add adjacent bonus for consecutive character matches in sequence
                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1) {
                    $prevQChar = strtolower($query[$i - 2]);
                    $prevCChar = strtolower($candidate[$j - 2]);
                    if ($prevQChar === $prevCChar) {
                        $adjBonus = self::ADJACENT_BONUS;
                    }
                }

                $effectiveMatch = $match + $adjBonus;
                $scoreDiag = $prevRow[$j - 1] + $effectiveMatch;
                $scoreUp = $currRow[$j - 1] + ($currRow[$j - 1] === 0 ? self::GAP_OPEN : self::GAP_EXTEND);
                $scoreLeft = $prevRow[$j] + ($prevRow[$j] === 0 ? self::GAP_OPEN : self::GAP_EXTEND);

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
     * Returns candidates sorted by score descending (best matches first).
     * Only returns candidates with a score > 0.
     *
     * @param string $query     The search query
     * @param list<string> $candidates List of candidate strings
     * @return list<array{string, int}> List of [candidate, score] pairs sorted by score desc
     */
    public function match(string $query, array $candidates): array
    {
        if ($query === '' || $candidates === []) {
            return [];
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $score = $this->score($query, $candidate);
            if ($score > 0) {
                $scored[] = [$candidate, $score];
            }
        }

        // Sort by score descending
        usort($scored, static fn(array $a, array $b) => $b[1] <=> $a[1]);

        return $scored;
    }
}