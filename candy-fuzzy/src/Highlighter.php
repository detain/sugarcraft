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
     * @param MatchResult          $result The match result containing matched indices
     * @param callable(string):string $styler Callable that wraps matched text
     * @return string The candidate with matched runs styled
     */
    public function highlight(MatchResult $result, callable $styler): string
    {
        if ($result->isEmpty()) {
            return $result->haystack;
        }

        $candidate = $result->haystack;
        $indices = $result->indices();

        if ($indices === []) {
            return $candidate;
        }

        // Group consecutive indices into runs
        $runs = $this->groupIntoRuns($indices);

        // Build result by applying styler to each run
        return $this->applyRuns($candidate, $runs, $styler);
    }

    /**
     * Group consecutive indices into runs.
     *
     * @param list<int> $indices Sorted list of matched character indices
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

        for ($i = 1; $i < count($indices); $i++) {
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

    /**
     * Apply styler to each run in the candidate string.
     *
     * @param string                      $candidate The haystack string
     * @param list<array{start: int, end: int}> $runs     List of [start, end] inclusive ranges
     * @param callable(string):string    $styler  Callable that wraps matched text
     * @return string The candidate with matched runs styled
     */
    private function applyRuns(string $candidate, array $runs, callable $styler): string
    {
        // Build the result by iterating through runs in reverse order
        // (to preserve string positions when inserting)
        $result = $candidate;

        for ($i = count($runs) - 1; $i >= 0; $i--) {
            $run = $runs[$i];
            $matched = mb_substr($candidate, $run['start'], $run['end'] - $run['start'] + 1, 'UTF-8');
            $styled = $styler($matched);
            $result = mb_substr($result, 0, $run['start'], 'UTF-8')
                . $styled
                . mb_substr($result, $run['end'] + 1, mb_strlen($result, 'UTF-8') - $run['end'] - 1, 'UTF-8');
        }

        return $result;
    }
}
