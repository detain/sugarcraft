<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Internal;

/**
 * Did-you-mean lookup for usage errors: the closest candidate by edit
 * distance, or null when none is close enough to be a plausible typo.
 *
 * The distance is optimal string alignment (Levenshtein plus adjacent
 * transposition as one edit), because swapped letters are the commonest
 * typo: plain Levenshtein scores `modle` two edits from `model` but one from
 * `mode`, and would suggest the wrong option.
 *
 * The cap is two edits, and never as many edits as the input is long, so a
 * one-letter typo of a one-letter word suggests nothing (every one-letter
 * word is one edit from every other). Ties go to the earlier candidate, so
 * declaration order breaks them deterministically.
 *
 * @internal
 */
final class Suggest
{
    public const MAX_DISTANCE = 2;

    /** @param iterable<int|string> $candidates */
    public static function closest(string $input, iterable $candidates): ?string
    {
        $limit = min(self::MAX_DISTANCE, strlen($input) - 1);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            // Names taken from array keys arrive as ints when all digits.
            $candidate = (string) $candidate;
            if ($candidate === $input) {
                continue;
            }
            $distance = self::distance($input, $candidate);
            if ($distance <= $limit && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Optimal string alignment distance over bytes. */
    public static function distance(string $a, string $b): int
    {
        $n = strlen($a);
        $m = strlen($b);
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$n][$m];
    }
}
