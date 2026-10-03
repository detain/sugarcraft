<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * The text of an Edit that matched too often or not at all (audit 0.11).
 *
 * "not unique (3 matches)" and "not found" were the whole of what a model got
 * back, so the usual next move was a blind retry or a full re-Read. These say
 * WHERE: the line of every match on ambiguity, and on a miss, in order, whether
 * `new_string` is already in the file (the edit may have landed on an earlier
 * attempt), whether `old_string` carries the `N: ` line-number prefixes Read
 * adds, and up to three of the closest windows of the file, numbered the way
 * Read numbers them.
 *
 * BOUNDED, because an error result is replayed into every later request of the
 * turn: at most {@see MAX_WINDOWS} windows of at most {@see MAX_WINDOW_LINES}
 * lines, each line at most {@see MAX_LINE_BYTES} bytes, and at most
 * {@see MAX_LISTED_MATCHES} match lines named. The similarity search is bounded
 * too ({@see MAX_CANDIDATES}, {@see MAX_FUZZY_SCAN_LINES}), so a miss against a
 * large file costs a bounded amount of work rather than a quadratic one.
 */
final class EditFailureHints
{
    public const MAX_WINDOWS = 3;
    public const MAX_WINDOW_LINES = 12;
    public const MAX_LINE_BYTES = 200;
    public const MAX_LISTED_MATCHES = 20;

    /** The least similarity, 0..1, a window needs to be offered. */
    public const MIN_SIMILARITY = 0.6;

    private const MAX_CANDIDATES = 300;
    private const MAX_FUZZY_SCAN_LINES = 50000;
    private const MAX_COMPARED_LINE_BYTES = 300;

    /** Anchor lines this common ("}", "") would make every position a candidate. */
    private const MAX_ANCHOR_OCCURRENCES = 50;
    private const MIN_ANCHOR_BYTES = 4;

    /**
     * The refusal for an `old_string` found $count (> 1) times without
     * `replace_all`, naming the line each occurrence starts on.
     */
    public static function ambiguous(string $content, string $old, int $count): string
    {
        $lines = [];
        $offset = 0;
        while (count($lines) < self::MAX_LISTED_MATCHES && ($at = strpos($content, $old, $offset)) !== false) {
            $lines[] = self::lineAt($content, $at);
            $offset = $at + strlen($old);
        }
        $listed = implode(', ', $lines);
        if ($count > count($lines)) {
            $listed .= sprintf(' and %d more', $count - count($lines));
        }

        return "Error: old_string is not unique ({$count} matches, starting at lines {$listed}); "
            . 'include more surrounding lines so it matches once, or set replace_all to change every '
            . 'occurrence; file left unchanged';
    }

    /**
     * The refusal for an `old_string` no {@see EditMatcher} stage found.
     */
    public static function notFound(string $content, string $old, string $new, string $path): string
    {
        $hints = [];

        if ($new !== '' && ($at = strpos($content, $new)) !== false) {
            $hints[] = sprintf(
                'new_string is already in the file (starting at line %d), so this edit may already have '
                . 'been applied; Read that region before retrying.',
                self::lineAt($content, $at),
            );
        }

        $prefixHint = self::lineNumberPrefixHint($content, $old);
        if ($prefixHint !== null) {
            $hints[] = $prefixHint;
        }

        $windows = self::closestWindows($content, $old);
        foreach ($windows as $window) {
            $hints[] = $window;
        }
        if ($windows === [] && $prefixHint === null) {
            $hints[] = 'Nothing in the file is close to old_string; Read the file again to get its current text.';
        }

        return "Error: old_string not found in {$path}; file left unchanged."
            . ($hints === [] ? '' : "\n" . implode("\n\n", $hints));
    }

    /**
     * The 1-based line $offset falls on.
     */
    public static function lineAt(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    /**
     * A hint when every non-blank line of $old opens with Read's `N: ` prefix —
     * text copied out of a Read result rather than out of the file. Says so,
     * and when the text WITHOUT the prefixes is in the file, says where.
     */
    private static function lineNumberPrefixHint(string $content, string $old): ?string
    {
        $lines = explode("\n", $old);
        $numbered = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^\d+: /', $line) !== 1) {
                return null;
            }
            $numbered++;
        }
        if ($numbered === 0) {
            return null;
        }

        $hint = 'old_string starts its lines with "N: " line numbers. Read adds those to what it shows; '
            . 'they are not in the file, so leave them out.';
        $stripped = (string) preg_replace('/^\d+: /m', '', $old);
        $at = $stripped === '' ? false : strpos($content, $stripped);
        if ($at !== false) {
            $hint .= sprintf(' Without them it matches at line %d.', self::lineAt($content, $at));
        }

        return $hint;
    }

    /**
     * Up to {@see MAX_WINDOWS} non-overlapping windows of the file, as many
     * lines long as $old, scoring at least {@see MIN_SIMILARITY}, best first,
     * each rendered as a heading plus `N: text` lines.
     *
     * Similarity is per line, ignoring leading and trailing whitespace (an
     * indentation-only difference is the uniform-indent stage's business, and
     * when that stage could not apply, the window is exactly what the model
     * needs to see): the summed Levenshtein distance over the summed length of
     * the longer side of each line pair, subtracted from one.
     *
     * Candidate windows come from anchors rather than from every position: a
     * line of $old that occurs verbatim in the file (and not so often that it
     * anchors everywhere), or failing any of those, a line of the file within
     * the similarity bar of one of the first three non-blank lines of $old.
     *
     * @return list<string>
     */
    private static function closestWindows(string $content, string $old): array
    {
        $oldLines = explode("\n", $old);
        if (count($oldLines) > 1 && end($oldLines) === '') {
            array_pop($oldLines);
        }
        $fileLines = explode("\n", $content);
        $fileCount = count($fileLines);
        $width = min(count($oldLines), $fileCount);
        if ($width === 0) {
            return [];
        }
        $oldLines = array_slice($oldLines, 0, $width);
        $oldTrim = array_map(self::compared(...), $oldLines);

        $starts = self::exactAnchors($fileLines, $oldTrim, $width);
        if ($starts === []) {
            $starts = self::fuzzyAnchors($fileLines, $oldTrim, $width);
        }

        $scored = [];
        foreach (array_keys($starts) as $start) {
            $score = self::windowSimilarity($fileLines, $start, $oldTrim);
            if ($score >= self::MIN_SIMILARITY) {
                $scored[] = [$start, $score];
            }
        }
        usort($scored, static fn(array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        $chosen = [];
        foreach ($scored as [$start, $score]) {
            foreach ($chosen as [$taken]) {
                if ($start < $taken + $width && $taken < $start + $width) {
                    continue 2;
                }
            }
            $chosen[] = [$start, $score];
            if (count($chosen) === self::MAX_WINDOWS) {
                break;
            }
        }

        $out = [];
        foreach ($chosen as [$start, $score]) {
            $out[] = self::renderWindow($fileLines, $start, $width, $score);
        }

        return $out;
    }

    /**
     * @param list<string> $fileLines
     * @param list<string> $oldTrim
     * @return array<int, true>
     */
    private static function exactAnchors(array $fileLines, array $oldTrim, int $width): array
    {
        $wanted = [];
        foreach ($oldTrim as $j => $line) {
            if (strlen($line) >= self::MIN_ANCHOR_BYTES) {
                $wanted[$line][] = $j;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $hits = [];
        foreach ($fileLines as $i => $line) {
            $trimmed = self::compared($line);
            if (isset($wanted[$trimmed])) {
                $hits[$trimmed][] = $i;
            }
        }

        $starts = [];
        $last = count($fileLines) - $width;
        foreach ($hits as $trimmed => $positions) {
            if (count($positions) > self::MAX_ANCHOR_OCCURRENCES) {
                continue;
            }
            foreach ($positions as $i) {
                foreach ($wanted[$trimmed] as $j) {
                    $start = $i - $j;
                    if ($start >= 0 && $start <= $last) {
                        $starts[$start] = true;
                        if (count($starts) >= self::MAX_CANDIDATES) {
                            return $starts;
                        }
                    }
                }
            }
        }

        return $starts;
    }

    /**
     * @param list<string> $fileLines
     * @param list<string> $oldTrim
     * @return array<int, true>
     */
    private static function fuzzyAnchors(array $fileLines, array $oldTrim, int $width): array
    {
        $probes = [];
        foreach ($oldTrim as $j => $line) {
            if (strlen($line) >= self::MIN_ANCHOR_BYTES) {
                $probes[$j] = $line;
                if (count($probes) === 3) {
                    break;
                }
            }
        }
        if ($probes === []) {
            return [];
        }

        // Every qualifying start is scored and only the best kept: in a file of
        // near-identical lines the FIRST few hundred are not the closest ones.
        $scores = [];
        $last = count($fileLines) - $width;
        $scan = min(count($fileLines), self::MAX_FUZZY_SCAN_LINES);
        for ($i = 0; $i < $scan; $i++) {
            $line = self::compared($fileLines[$i]);
            $length = strlen($line);
            foreach ($probes as $j => $probe) {
                $longer = max($length, strlen($probe));
                if ($length === 0 || min($length, strlen($probe)) * 2 < $longer) {
                    continue;
                }
                $ratio = 1 - levenshtein($probe, $line) / $longer;
                $start = $i - $j;
                if ($ratio < self::MIN_SIMILARITY || $start < 0 || $start > $last) {
                    continue;
                }
                if ($ratio > ($scores[$start] ?? -1.0)) {
                    $scores[$start] = $ratio;
                }
            }
        }
        arsort($scores);

        return array_fill_keys(array_slice(array_keys($scores), 0, self::MAX_CANDIDATES), true);
    }

    /**
     * @param list<string> $fileLines
     * @param list<string> $oldTrim
     */
    private static function windowSimilarity(array $fileLines, int $start, array $oldTrim): float
    {
        $distance = 0;
        $length = 0;
        foreach ($oldTrim as $j => $want) {
            $have = self::compared($fileLines[$start + $j] ?? '');
            $distance += levenshtein($want, $have);
            $length += max(strlen($want), strlen($have));
        }

        return $length === 0 ? 0.0 : 1 - $distance / $length;
    }

    /**
     * @param list<string> $fileLines
     */
    private static function renderWindow(array $fileLines, int $start, int $width, float $score): string
    {
        $shown = min($width, self::MAX_WINDOW_LINES);
        $out = sprintf(
            'Did you mean lines %d-%d (%d%% similar, ignoring indentation)?',
            $start + 1,
            $start + $width,
            (int) floor($score * 100),
        );
        for ($k = 0; $k < $shown; $k++) {
            $line = $fileLines[$start + $k];
            if (strlen($line) > self::MAX_LINE_BYTES) {
                $line = mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8') . '…';
            }
            $out .= "\n" . ($start + $k + 1) . ': ' . $line;
        }
        if ($width > $shown) {
            $out .= sprintf("\n… %d more lines", $width - $shown);
        }

        return $out;
    }

    /**
     * A line as similarity sees it: trimmed, and clipped so one long line
     * cannot make a comparison quadratic in the file's widest line.
     */
    private static function compared(string $line): string
    {
        $line = trim($line);

        return strlen($line) > self::MAX_COMPARED_LINE_BYTES
            ? substr($line, 0, self::MAX_COMPARED_LINE_BYTES)
            : $line;
    }
}
