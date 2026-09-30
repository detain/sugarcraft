<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Reader;

/**
 * Walks an already-rendered unified diff and tells a viewer which line of
 * which file each row points at — the region/hunk MODEL the sugar-crush
 * gutter consumes, lifted out so any renderer (or patch tool) can number rows
 * without owning the counting rules.
 *
 * Counters come from each `@@ -a,b +c,d @@` header and advance the way
 * `diff -u` counts: context advances both sides, `-` advances old, `+`
 * advances new. Rows before the first hunk header — and any file header,
 * which is how a second file starts inside a multi-file diff — reset the
 * walk, otherwise the leading `-`/`+` of a header would count as an edit and
 * desynchronise everything below it.
 *
 * `--- `/`+++ ` are ambiguous — they are also exactly what a deleted
 * `-- users table` (SQL/Lua/Haskell line comment) or an added `++ i` row
 * reads as once the marker column is prepended. Inside an open hunk the
 * content reading wins; that is safe because git emits `diff --git `/
 * `index ` ahead of every real `--- `, and those close the hunk
 * unconditionally.
 *
 * A hunk claiming to start past {@see MAX_LINE_NUMBER_DIGITS} is recognised
 * but left unnumbered: `(int)` would clamp such a literal to PHP_INT_MAX, the
 * first `++` would promote the counter to float, and any later formatting of
 * the number would die downstream. Declining beats printing a wrong number,
 * and stopping the walk beats bleeding the previous hunk's counters through.
 *
 * Mirrors sugar-crush/src/Tui/DiffGutter.php `number()` / `isFileHeader()` /
 * `numberable()`.
 */
final class UnifiedScan
{
    /** Largest `@@` start line this will number; bigger hunks are left unnumbered. */
    public const MAX_LINE_NUMBER_DIGITS = 9;

    private function __construct()
    {
    }

    /**
     * @param string|list<string> $diff raw unified-diff text or its rows
     * @return list<ScannedLine> one per input row
     */
    public static function lines(string|array $diff): array
    {
        $rows = is_string($diff)
            ? self::rowsOf($diff)
            : $diff;

        $inHunk = false;
        $old = 0;
        $new = 0;
        $out = [];

        foreach ($rows as $line) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                // Matched with an unbounded \d+ on purpose: the header has to
                // be RECOGNISED even when its numbers are absurd, or the rows
                // after it get counted as context. Only the numbering opts
                // out. See MAX_LINE_NUMBER_DIGITS.
                $inHunk = self::numberable($m[1]) && self::numberable($m[2]);
                $old = $inHunk ? (int) $m[1] : 0;
                $new = $inHunk ? (int) $m[2] : 0;
                $out[] = new ScannedLine(null, null, false);
                continue;
            }

            if (self::isFileHeader($line, $inHunk)) {
                $inHunk = false;
                $out[] = new ScannedLine(null, null, true);
                continue;
            }

            if (!$inHunk) {
                $out[] = new ScannedLine(null, null, false);
                continue;
            }

            // "\ No newline at end of file" annotates the line above it and
            // occupies no line of either file.
            if (str_starts_with($line, '\\')) {
                $out[] = new ScannedLine(null, null, false);
                continue;
            }

            $marker = $line === '' ? ' ' : $line[0];
            if ($marker === '+') {
                $out[] = new ScannedLine(null, $new, false);
                $new++;
                continue;
            }

            if ($marker === '-') {
                $out[] = new ScannedLine($old, null, false);
                $old++;
                continue;
            }

            // ' ' is context; an empty row is a context line whose single
            // trailing space was stripped in transit, common enough that
            // treating it as anything else desynchronises the hunk.
            $out[] = new ScannedLine($old, $new, false);
            $old++;
            $new++;
        }

        return $out;
    }

    /**
     * Which rows are file headers rather than diff content, under exactly the
     * rule {@see isFileHeader()} documents.
     *
     * @param string|list<string> $diff
     * @return list<bool>
     */
    public static function fileHeaders(string|array $diff): array
    {
        return array_map(
            static fn (ScannedLine $row): bool => $row->fileHeader,
            self::lines($diff),
        );
    }

    /**
     * Count lines the way the writer's parser does — a trailing newline
     * terminates, it does not add an empty final row — so a raw diff string
     * scans row-for-row with what was written.
     *
     * @return list<string>
     */
    private static function rowsOf(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $rows = explode("\n", $text);
        if (end($rows) === '') {
            array_pop($rows);
        }

        return $rows;
    }

    /**
     * Whether this row starts a new file rather than carrying diff content.
     *
     * `diff --git `/`index ` are unambiguous — nothing else emits them.
     * `--- `/`+++ ` are not: inside an open hunk they are content
     * ({@see UnifiedScan} class docblock); outside one they are headers.
     */
    private static function isFileHeader(string $line, bool $inHunk): bool
    {
        if (str_starts_with($line, 'diff --git ') || str_starts_with($line, 'index ')) {
            return true;
        }

        return !$inHunk
            && (str_starts_with($line, '--- ') || str_starts_with($line, '+++ '));
    }

    /** Whether a `@@` header's start line is small enough to count from safely. */
    private static function numberable(string $raw): bool
    {
        return strlen(ltrim($raw, '0')) <= self::MAX_LINE_NUMBER_DIGITS;
    }
}
