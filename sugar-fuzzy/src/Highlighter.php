<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy;

/**
 * Highlights matched character runs in a candidate string using a styler callable.
 *
 * Given a MatchResult and a styler function, returns the candidate string with
 * matched runs wrapped in the styler output. Presentation-neutral — no terminal coupling.
 */
final class Highlighter
{
    /**
     * Highlight matched runs in a candidate string.
     *
     * The styler is any callable — plain callables like `'strtoupper'` and
     * first-class callables are as valid as closures (finding sugar-fuzzy-023:
     * the previous `\Closure`-only typing forced the sole real consumer to
     * smuggle runs through a NUL-sentinel string and re-parse them).
     *
     * @param MatchResult                $result The match result containing matched indices
     * @param callable(string):string    $styler Callable that wraps matched text
     * @return string The candidate with matched runs styled
     */
    public function highlight(MatchResult $result, callable $styler): string
    {
        $parts = [];

        foreach ($this->segments($result) as $segment) {
            $parts[] = $segment['matched'] ? $styler($segment['text']) : $segment['text'];
        }

        return implode('', $parts);
    }

    /**
     * Split the candidate into presentation-neutral matched/unmatched segments.
     *
     * Returns `list<array{text: string, matched: bool}>` in reading order:
     * consecutive matched code points collapse into one segment; unmatched
     * gaps become their own. Index normalization (drop out-of-range, dedupe,
     * sort) is identical to {@see self::highlight()}, so `segments()` is the
     * single source of truth for the run structure — consumers that need a
     * different presentation (or want to feed a list view row by row) can use
     * it directly instead of styling and re-parsing.
     *
     * Single forward pass: each segment of the ORIGINAL haystack is sliced
     * exactly once and concatenated once (finding sugar-fuzzy-019: the old
     * reverse-order applyRuns() re-copied the whole string per run, quadratic
     * in run count — 11.5x measured on 1000 chars/100 runs).
     *
     * @return list<array{text: string, matched: bool}>
     */
    public function segments(MatchResult $result): array
    {
        $candidate = $result->haystack;
        $len = mb_strlen($candidate, 'UTF-8');

        if ($result->isEmpty()) {
            return $len === 0 ? [] : [['text' => $candidate, 'matched' => false]];
        }

        $indices = $result->indices();

        // Normalize: MatchResult is publicly constructible, so external callers
        // may pass unsorted, duplicate, or OUT-OF-RANGE indices. Drop indices
        // outside [0, len) first — a negative or past-end index would otherwise
        // make mb_substr slice the wrong bytes and corrupt/duplicate output —
        // then dedupe and sort to satisfy groupIntoRuns()'s ascending invariant.
        $indices = array_values(array_filter(
            $indices,
            static fn(int $idx): bool => $idx >= 0 && $idx < $len,
        ));
        $indices = array_values(array_unique($indices));
        sort($indices);

        if ($indices === []) {
            return $len === 0 ? [] : [['text' => $candidate, 'matched' => false]];
        }

        $segments = [];
        $cursor = 0;

        foreach ($this->groupIntoRuns($indices) as $run) {
            if ($run['start'] > $cursor) {
                $segments[] = ['text' => mb_substr($candidate, $cursor, $run['start'] - $cursor, 'UTF-8'), 'matched' => false];
            }

            $segments[] = ['text' => mb_substr($candidate, $run['start'], $run['end'] - $run['start'] + 1, 'UTF-8'), 'matched' => true];
            $cursor = $run['end'] + 1;
        }

        if ($cursor < $len) {
            $segments[] = ['text' => mb_substr($candidate, $cursor, null, 'UTF-8'), 'matched' => false];
        }

        return $segments;
    }

    /**
     * Group consecutive indices into runs.
     *
     * @param list<int> $indices Sorted list of matched character indices (precondition: ascending, unique — enforced by caller)
     * @return list<array{start: int, end: int}> List of [start, end] inclusive ranges
     */
    private function groupIntoRuns(array $indices): array
    {
        if ($indices === []) {
            return [];
        }

        $runs = [];
        $runStart = $indices[0];
        $runEnd = $indices[0];
        $indexCount = count($indices);

        for ($i = 1; $i < $indexCount; $i++) {
            if ($indices[$i] === $runEnd + 1) {
                // Consecutive - extend the run
                $runEnd = $indices[$i];
            } else {
                // Gap - save current run and start new one
                $runs[] = ['start' => $runStart, 'end' => $runEnd];
                $runStart = $indices[$i];
                $runEnd = $indices[$i];
            }
        }

        // Don't forget the last run
        $runs[] = ['start' => $runStart, 'end' => $runEnd];

        return $runs;
    }
}
