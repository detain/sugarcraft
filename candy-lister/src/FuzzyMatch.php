<?php

declare(strict_types=1);

namespace SugarCraft\Lister;

/**
 * Fuzzy substring matcher using Smith-Waterman-style local alignment scoring.
 *
 * Scores candidates using a two-row DP matrix for memory efficiency.
 * Higher scores indicate better matches; scores <= 0 indicate no match.
 */
final class FuzzyMatch
{
    private const MATCH_SCORE = 3;
    private const MISMATCH_PENALTY = -3;
    private const GAP_OPEN = -5;
    private const GAP_EXTEND = -1;
    private const ADJACENT_BONUS = 5;

    /**
     * Score a candidate against a query using Smith-Waterman local alignment.
     *
     * Only considers alignments where the query characters appear in ORDER
     * within the candidate (not necessarily contiguously).
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

        $q = strtolower($query);
        $c = strtolower($candidate);
        $queryLen = strlen($q);
        $candidateLen = strlen($c);

        // Two-row Smith-Waterman for memory efficiency
        $prevRow = array_fill(0, $candidateLen + 1, 0);
        $currRow = array_fill(0, $candidateLen + 1, 0);

        $maxScore = 0;

        for ($i = 1; $i <= $queryLen; $i++) {
            $qChar = $q[$i - 1];
            for ($j = 1; $j <= $candidateLen; $j++) {
                $cChar = $c[$j - 1];

                $match = $qChar === $cChar
                    ? self::MATCH_SCORE
                    : self::MISMATCH_PENALTY;

                // Consecutive character match bonus
                $adjBonus = 0;
                if ($match > 0 && $i > 1 && $j > 1) {
                    if ($q[$i - 2] === $c[$j - 2]) {
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
