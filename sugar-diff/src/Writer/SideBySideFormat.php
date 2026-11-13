<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Writer;

use SugarCraft\Diff\Diff;
use SugarCraft\Diff\Line;
use SugarCraft\Diff\LineKind;
use SugarCraft\Diff\Reader\ScanRowKind;
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
 * the run rather than treated as a boundary. Which side a scanned row
 * belongs to is taken from the scanner's row {@see \SugarCraft\Diff\Reader\ScanRowKind},
 * never re-inferred from counter nullability, so scanner and pairing share one
 * rule set: rows the scanner cannot attribute to a hunk (file headers, `@@`
 * headers, prose, corruption, and the hunks past
 * {@see \SugarCraft\Diff\Reader\UnifiedScan::MAX_LINE_NUMBER_DIGITS}) are
 * boundaries or omissions — the same fail-closed posture. A wrong pairing
 * would print a confidently wrong column; declining is safe.
 *
 * Pure data: the caller owns widths, truncation and colour (see
 * {@see SideBySideRow}). The candy-code TUI split-diff renderer consumes this
 * pairing directly; the candy-code-web split DiffView pairs rows in its own
 * separate TypeScript code, so the two surfaces ALIGN BY CONVENTION, not by
 * a shared implementation — changing this rule should come with a look at
 * that viewer.
 *
 * As in {@see \SugarCraft\Diff\Reader\UnifiedScan}, list input to
 * `fromUnified()` is taken as given: a row element carrying an embedded
 * `"\n"` is not refused (only `Diff::compute()` sides are), and it lands
 * verbatim in a `SideBySideRow` text. Pairing here never re-emits — the host
 * printing the two columns owns sanitising its channel.
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
     * Side-by-side rows for an already-rendered unified patch — raw text or
     * its rows — numbered through {@see UnifiedScan::lines()} and classified
     * by its row kind, so a viewer that only ever holds patch text (git
     * output, a stored diff) gets the same layout as one that holds a
     * computed {@see Diff}. The scan and the raw rows stay row-for-row
     * (both split via {@see UnifiedScan::rowsOf()} for strings) — that zip
     * is the invariant this method relies on; never re-split the text by
     * other means here.
     *
     * The marker column is dropped for the text, under exactly the
     * scanner's own rule (an empty row is context whose trailing space was
     * stripped in transit, so there is nothing to drop).
     *
     * @param string|list<string> $patch
     * @return list<SideBySideRow>
     */
    public static function fromUnified(string|array $patch): array
    {
        $rawRows = is_string($patch) ? UnifiedScan::rowsOf($patch) : $patch;

        $rows = [];
        $run = [];
        foreach (UnifiedScan::lines($rawRows) as $index => $scan) {
            if ($scan->kind === ScanRowKind::NoNewlineAnnotation) {
                // Invisible to the walk: it annotates the row above it and
                // must not cut a pairing run in half.
                continue;
            }

            if (!$scan->kind->isContentRow()) {
                // Every other non-content kind is a boundary.
                self::appendPairRun($rows, $run);
                $run = [];
                continue;
            }

            $kind = match ($scan->kind) {
                ScanRowKind::Insert => LineKind::Insert,
                ScanRowKind::Delete => LineKind::Delete,
                default => LineKind::Equal,
            };

            $text = $rawRows[$index];
            $run[] = new Line(
                kind: $kind,
                text: $text === '' ? '' : substr($text, 1),
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
        $next = 0;
        foreach ($run as $line) {
            if ($line->kind === LineKind::Delete) {
                $pending[] = $line;
                continue;
            }

            if ($line->kind === LineKind::Insert) {
                // An addition whose region has no unpaired removal waiting
                // stands alone on the right; otherwise it replaces the oldest
                // one. The queue is consumed by cursor, never array_shift:
                // shifting re-indexed every remaining row on each pairing, so
                // one k-row changed region cost O(k^2) writes (#103 — 365 ms
                // at k=20 000, seconds at 100 000).
                $rows[] = $next < count($pending)
                    ? new SideBySideRow($pending[$next++], $line)
                    : new SideBySideRow(null, $line);
                continue;
            }

            // Context closes the run: whatever stayed unpaired is leftover.
            foreach (array_slice($pending, $next) as $orphan) {
                $rows[] = new SideBySideRow($orphan, null);
            }
            $pending = [];
            $next = 0;
            $rows[] = new SideBySideRow($line, $line);
        }

        foreach (array_slice($pending, $next) as $orphan) {
            $rows[] = new SideBySideRow($orphan, null);
        }
    }
}
