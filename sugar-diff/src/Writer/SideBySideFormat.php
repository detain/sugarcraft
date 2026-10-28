<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Writer;

use SugarCraft\Diff\Diff;
use SugarCraft\Diff\Line;
use SugarCraft\Diff\LineKind;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * Lays a diff out as side-by-side rows: each {@see SideBySideRow} pairs one
 * old-side line with one new-side line, so a removed/added run reads as two
 * parallel columns instead of an interleaved `-`/`+` stream.
 *
 * The pairing rule is the row walk over one changed region: removals queue in
 * order, and an addition pairs with the oldest queued removal (line-by-line,
 * the way split-diff viewers align a replacement block). Leftovers stand
 * alone — extra removals print on the left with an empty right half, extra
 * additions the other way round. Context rows pass straight through, paired
 * with themselves. Nothing ever pairs across a hunk boundary: two hunks are
 * two independent regions, and joining their runs would show a removal from
 * one hunk as replaced by an addition from another.
 *
 * Both entry points agree by construction: `fromUnified($diff->unified(...))`
 * returns the same rows as `rows($diff)`, including when the writer annotates
 * unterminated end-of-file rows — the `\ No newline` row is skipped inside
 * the run rather than treated as a boundary, mirroring {@see UnifiedScan}
 * (which gives it no line of either file). Rows the scanner cannot count
 * (file headers, `@@` headers, before-the-first-hunk prose, and the hunks
 * past {@see UnifiedScan::MAX_LINE_NUMBER_DIGITS}) are boundaries or omissions
 * respectively — the same fail-closed posture: a wrong pairing would print a
 * confidently wrong column, declining is safe.
 *
 * Pure data: the caller owns widths, truncation and colour (see
 * {@see SideBySideRow}). This is the canonical pairing the candy-code TUI
 * split renderer and the candy-code-web split DiffView are built to consume,
 * so both surfaces align the same patch the same way (GAP-UI-05).
 */
final class SideBySideFormat
{
    private function __construct()
    {
    }

    /**
     * Side-by-side rows for a computed diff: every hunk body walked with the
     * pairing rule, hunks emitted in order. Empty for an unchanged pair.
     *
     * @return list<SideBySideRow>
     */
    public static function rows(Diff $diff): array
    {
        $rows = [];
        foreach ($diff->hunks as $hunk) {
            self::appendPairRun($rows, $hunk->lines());
        }

        return $rows;
    }

    /**
     * Side-by-side rows for an already-rendered unified patch, numbered
     * through {@see UnifiedScan::lines()} — so a viewer that only ever holds
     * patch text (git output, a stored diff) gets the same layout as one that
     * holds a computed {@see Diff}.
     *
     * Which side each row belongs to is read from the scan's counters, not
     * re-derived: a row that advanced only the old counter is a removal, only
     * the new one an addition, both context. The row's marker column is then
     * dropped for the text, under exactly the scanner's own rule (an empty
     * row is context whose trailing space was stripped in transit).
     *
     * @return list<SideBySideRow>
     */
    public static function fromUnified(string $patch): array
    {
        if ($patch === '') {
            return [];
        }

        // Count rows the way the writer's parser does — a trailing newline
        // terminates, it does not add an empty final row — so the zip below
        // is row-for-row with what UnifiedScan::lines() returns.
        $rawRows = explode("\n", $patch);
        if (end($rawRows) === '') {
            array_pop($rawRows);
        }

        $rows = [];
        $run = [];
        foreach (UnifiedScan::lines($rawRows) as $index => $scan) {
            if ($scan->old === null && $scan->new === null) {
                // Unnumbered row. Only the EOF annotation is invisible to the
                // walk — it annotates the row above it, so it must not cut a
                // pairing run in half. Every other kind is a boundary.
                if (!str_starts_with($rawRows[$index], '\\')) {
                    self::appendPairRun($rows, $run);
                    $run = [];
                }
                continue;
            }

            $kind = $scan->new === null
                ? LineKind::Delete
                : ($scan->old === null ? LineKind::Insert : LineKind::Equal);

            $text = $rawRows[$index];
            $marker = $text === '' ? ' ' : $text[0];
            $body = $marker === '+' || $marker === '-' || $marker === ' '
                ? substr($text, 1)
                : $text;

            $run[] = new Line(
                kind: $kind,
                text: $body,
                oldNumber: $scan->old,
                newNumber: $scan->new,
            );
        }
        self::appendPairRun($rows, $run);

        return $rows;
    }

    /**
     * Pair one changed region onto $rows: removals queue, additions take the
     * oldest queued partner, leftovers of either side stand alone.
     *
     * @param list<SideBySideRow> $rows
     * @param list<Line>          $run
     */
    private static function appendPairRun(array &$rows, array $run): void
    {
        $pending = [];
        foreach ($run as $line) {
            if ($line->kind === LineKind::Delete) {
                $pending[] = $line;
                continue;
            }

            if ($line->kind === LineKind::Insert) {
                // An addition whose region has no removal waiting stands
                // alone on the right; otherwise it replaces the oldest one.
                $rows[] = $pending === []
                    ? new SideBySideRow(null, $line)
                    : new SideBySideRow(array_shift($pending), $line);
                continue;
            }

            // Context closes the run: whatever stayed unpaired is leftover.
            foreach ($pending as $orphan) {
                $rows[] = new SideBySideRow($orphan, null);
            }
            $pending = [];
            $rows[] = new SideBySideRow($line, $line);
        }

        foreach ($pending as $orphan) {
            $rows[] = new SideBySideRow($orphan, null);
        }
    }
}
