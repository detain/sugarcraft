<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * Stage 5: a block of at least three lines found by its FIRST and LAST lines
 * (whitespace-trimmed, exact), accepted when the lines between are alike enough
 * — {@see THRESHOLD}, scored line by line with an edit distance.
 *
 * This is the stage for an `old_string` whose ends the model copied correctly
 * and whose middle it paraphrased or mis-remembered. It is the loosest stage
 * that still anchors on text the model actually wrote, which is why the chain
 * runs it only after every stricter one has found nothing, why it must find
 * exactly ONE block clearing the threshold, and why
 * {@see EditMatcher::isDisproportionateMatch()} refuses a block whose size is
 * out of line with `old_string`'s.
 *
 * The score divides by the LONGER of the two middles, so a block with extra or
 * missing lines pays for them; for each first-line anchor only the best-scoring
 * closing anchor within twice `old_string`'s length is kept.
 */
final class BlockAnchorMatcher implements MatchStage
{
    /** The least average middle-line similarity a block may have (roadmap: >= 0.65). */
    public const THRESHOLD = 0.65;

    /**
     * Line-pair comparisons one search may spend. A file full of `{`/`}` lines
     * offers thousands of anchor pairs, and every pair costs an edit distance
     * per middle line: MEASURED before this bound, a miss over a 284 KB file of
     * 8,333 such blocks took 8.1 s. Past the budget the stage reports no match —
     * the conservative answer, since the stages before it have already failed
     * and the model then gets the closest-window hints instead.
     */
    private const MAX_LINE_COMPARISONS = 200_000;

    public function name(): string
    {
        return EditMatcher::STAGE_BLOCK_ANCHOR;
    }

    public function find(string $content, string $old, string $new): array
    {
        $needle = LineIndex::needle($old);
        $want = array_map('trim', $needle['lines']);
        $n = \count($want);
        if ($n < 3 || $want[0] === '' || $want[$n - 1] === '') {
            return [];
        }

        $index = LineIndex::of($content);
        $have = array_map('trim', $index->lines);
        $middle = \array_slice($want, 1, $n - 2);
        $budget = self::MAX_LINE_COMPARISONS;
        $candidates = [];

        foreach ($have as $i => $line) {
            if ($line !== $want[0]) {
                continue;
            }

            $best = null;
            $limit = min($index->count() - 1, $i + 2 * $n);
            for ($j = $i + 2; $j <= $limit; $j++) {
                if ($have[$j] !== $want[$n - 1]) {
                    continue;
                }
                $between = $j - $i - 1;
                // A middle this much longer or shorter cannot reach the
                // threshold even if every line it has matched perfectly.
                if (min($between, \count($middle)) < self::THRESHOLD * max($between, \count($middle))) {
                    continue;
                }
                $score = self::similarity($middle, \array_slice($have, $i + 1, $between), $budget);
                if ($budget <= 0) {
                    return [];
                }
                if ($score >= self::THRESHOLD && ($best === null || $score > $best[1])) {
                    $best = [$j, $score];
                }
            }

            if ($best === null) {
                continue;
            }

            [$offset, $length] = $index->span($i, $best[0], $needle['trailingNewline']);
            $placed = IndentShift::adapt($old, substr($content, $offset, $length), $new);
            if ($placed === null) {
                continue;
            }

            $what = sprintf(
                'old_string matched by its first and last lines, with the %d line(s) between them %d%% alike',
                $best[0] - $i - 1,
                (int) floor($best[1] * 100),
            );
            $candidates[] = new MatchCandidate(
                $offset,
                $length,
                $placed[0],
                $placed[1] === null ? $what : $what . '; ' . $placed[1],
            );
        }

        return $candidates;
    }

    /**
     * Average per-line similarity of two runs of trimmed lines, over the longer
     * run (a line with no partner scores 0). Two empty runs are identical.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function similarity(array $a, array $b, ?int &$budget = null): float
    {
        $longest = max(\count($a), \count($b));
        if ($longest === 0) {
            return 1.0;
        }

        $sum = 0.0;
        for ($k = 0; $k < $longest; $k++) {
            // Stop as soon as even perfect remaining lines cannot lift the
            // average to the threshold: the score is then only ever compared
            // against it, so its exact value below it does not matter.
            if (($sum + ($longest - $k)) / $longest < self::THRESHOLD) {
                return ($sum + ($longest - $k)) / $longest;
            }
            if ($budget !== null) {
                $budget--;
            }
            $sum += self::lineSimilarity($a[$k] ?? null, $b[$k] ?? null);
        }

        return $sum / $longest;
    }

    private static function lineSimilarity(?string $a, ?string $b): float
    {
        if ($a === null || $b === null) {
            return 0.0;
        }
        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 1.0;
        }

        return 1.0 - levenshtein($a, $b) / $max;
    }
}
