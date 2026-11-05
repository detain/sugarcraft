<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Reader;

/**
 * Walks an already-rendered unified diff and tells a viewer which line of
 * which file each row points at — the region/hunk model any renderer (or
 * patch tool) can use to number rows without owning the counting rules.
 * The candy-code gutter mirrors these rules today but keeps its own copy
 * ({@see ScannedLine}); delegating that copy here is the remaining seam.
 *
 * Counters come from each `@@ -a,b +c,d @@` header and advance the way
 * `diff -u` counts: context advances both sides, `-` advances old, `+`
 * advances new. The DECLARED counts are honoured, not just the starts: a
 * hunk closes when its `b`/`d` budgets run out, and a row that would
 * consume an exhausted side ends the hunk and is reported outside it
 * (fail-closed — guessing where the text resynchronised would renumber
 * every row below the corruption with confident wrong numbers, the exact
 * failure the gutter's docblock warns about). This is what keeps a
 * trailing `-- ` signature, an `Only in A:` note, or a second file's
 * header from being counted as context of the hunk that ended above them.
 *
 * A row before the first hunk header — and any file header, which is how a
 * second file starts inside a multi-file diff — resets the walk, otherwise
 * the leading `-`/`+` of a header would count as an edit and desynchronise
 * everything below it. File headers recognised: `diff ` (git's
 * `diff --git ` and recursive GNU `diff -ru ` output — a plain single-pair
 * `diff -u` prints no such line, so those files are keyed off the `--- `/
 * `+++ ` pair), `index `, and out-of-hunk `--- `/`+++ `.
 *
 * `--- `/`+++ ` are ambiguous — they are also exactly what a deleted
 * `-- users table` (SQL/Lua/Haskell line comment) or an added `++ i` row
 * reads as once the marker column is prepended. Inside an open hunk the
 * content reading wins; that is safe because every real header starts a
 * fresh file, which GNU/git place after the previous hunk's declared rows.
 *
 * Within an open hunk every row must carry a marker: `' '`, `''` (a context
 * row whose single trailing space was stripped in transit, common enough
 * that treating it as anything else desynchronises the hunk), `'-'`, `'+'`,
 * or `'\'`. Any other leading character is corruption — the hunk ends and
 * the row is reported outside it, rather than being silently numbered as
 * context.
 *
 * A hunk claiming to start past {@see MAX_LINE_NUMBER_DIGITS} is recognised
 * but left unnumbered. The cap is deliberately conservative: on 64-bit PHP a
 * 10-to-18-digit literal casts exactly and `(int)` only saturates to
 * PHP_INT_MAX at 19 digits — and a saturated counter then promotes to float
 * at the first `++` and dies downstream in any later formatting. Declining
 * beats printing a number the walk cannot vouch for, and stopping the walk
 * beats bleeding the previous hunk's counters through. The cap gates the two START fields only; the declared
 * COUNTS are safe without it because they are decremented toward zero and
 * never incremented, so even a clamped PHP_INT_MAX budget can only mean
 * "open until the next header" — it cannot print a number.
 *
 * Row input is `string` or `list<string>` with NO newline refusal: unlike a
 * `Diff::compute()` side (whose `parseSide()` rejects an embedded `"\n"`), a
 * passed row may carry a newline and is reported verbatim as that row's
 * text — a `--- a\n+++ forged` element reads as one ordinary row. This walk
 * has no re-emit path, so a host that prints scanned text owns sanitising
 * its own channel.
 *
 * Mirrors candy-code/src/Tui/DiffGutter.php `number()` / `isFileHeader()` /
 * `numberable()` (which predate the count tracking above — see the
 * delegation seam note on {@see ScannedLine}).
 */
final class UnifiedScan
{
    /**
     * Largest `@@` start line this will number; bigger hunks are left
     * unnumbered. A digit CAP on the value with leading zeros stripped
     * (`strlen(ltrim($raw, '0'))`) — not a threshold: `000000001` is the
     * number 1 and is numbered normally.
     */
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
        $oldLeft = 0;
        $newLeft = 0;
        $out = [];

        foreach ($rows as $line) {
            if (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m) === 1) {
                // Matched with an unbounded \d+ on purpose: the header has to
                // be RECOGNISED even when its numbers are absurd, or the rows
                // after it get counted as context. Only the numbering opts
                // out. See MAX_LINE_NUMBER_DIGITS.
                $numberable = self::numberable($m[1]) && self::numberable($m[3]);
                $inHunk = $numberable;
                $old = $numberable ? (int) $m[1] : 0;
                $new = $numberable ? (int) $m[3] : 0;
                // An elided count is the number 1: GNU omits `,1` from
                // headers, so `@@ -1 +1 @@` declares one line per side.
                // (`preg_match` leaves a skipped interior group as '', which
                // `?? '1'` would NOT catch — hence the explicit empty test.)
                $oldLeft = $numberable ? self::declaredCount($m[2] ?? '') : 0;
                $newLeft = $numberable ? self::declaredCount($m[4] ?? '') : 0;
                $out[] = new ScannedLine(null, null, false, ScanRowKind::HunkHeader);
                continue;
            }

            if (self::isFileHeader($line, $inHunk)) {
                $inHunk = false;
                $out[] = new ScannedLine(null, null, true, ScanRowKind::FileHeader);
                continue;
            }

            // "\ No newline at end of file" annotates the line above it and
            // occupies no line of either file — checked BEFORE the
            // outside-hunk bail, because GNU's final marker lands exactly
            // where the last declared row spent the budget: just after the
            // hunk. A content row can never begin with `\` (the marker
            // column is `' '`, `-` or `+`), so this shape stays unclaimed.
            if (str_starts_with($line, '\\')) {
                $out[] = new ScannedLine(null, null, false, ScanRowKind::NoNewlineAnnotation);
                continue;
            }

            if (!$inHunk) {
                $out[] = new ScannedLine(null, null, false, ScanRowKind::OutsideHunk);
                continue;
            }

            $marker = $line === '' ? ' ' : $line[0];

            if ($marker === '+') {
                if ($newLeft <= 0) {
                    $inHunk = false; // the hunk's declared new side is spent
                    $out[] = new ScannedLine(null, null, false, ScanRowKind::OutsideHunk);
                    continue;
                }

                $out[] = new ScannedLine(null, $new, false, ScanRowKind::Insert);
                $new++;
                $newLeft--;
                $inHunk = !self::hunkSpent($oldLeft, $newLeft);
                continue;
            }

            if ($marker === '-') {
                if ($oldLeft <= 0) {
                    $inHunk = false; // the hunk's declared old side is spent
                    $out[] = new ScannedLine(null, null, false, ScanRowKind::OutsideHunk);
                    continue;
                }

                $out[] = new ScannedLine($old, null, false, ScanRowKind::Delete);
                $old++;
                $oldLeft--;
                $inHunk = !self::hunkSpent($oldLeft, $newLeft);
                continue;
            }

            if ($marker !== ' ') {
                // Any other leading character cannot be a unified row.
                // Ending the hunk here keeps every later real header
                // working and refuses to number the corruption.
                $inHunk = false;
                $out[] = new ScannedLine(null, null, false, ScanRowKind::OutsideHunk);
                continue;
            }

            // ' ' is context (an empty row reads as stripped context — see
            // the class docblock); it advances both sides at once, so BOTH
            // budgets must still have room.
            if ($oldLeft <= 0 || $newLeft <= 0) {
                $inHunk = false;
                $out[] = new ScannedLine(null, null, false, ScanRowKind::OutsideHunk);
                continue;
            }

            $out[] = new ScannedLine($old, $new, false, ScanRowKind::Context);
            $old++;
            $new++;
            $oldLeft--;
            $newLeft--;
            $inHunk = !self::hunkSpent($oldLeft, $newLeft);
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
     * scans row-for-row with what was written. Public so renderers pairing
     * rows against {@see self::lines()} output split text exactly once.
     *
     * @return list<string>
     */
    public static function rowsOf(string $text): array
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

    /** Both declared budgets consumed: the hunk is complete. */
    private static function hunkSpent(int $oldLeft, int $newLeft): bool
    {
        return $oldLeft <= 0 && $newLeft <= 0;
    }

    /** A header's `,N` count field: absent (elided) means 1. */
    private static function declaredCount(string $raw): int
    {
        return $raw === '' ? 1 : (int) $raw;
    }

    /**
     * Whether this row starts a new file rather than carrying diff content.
     *
     * `diff `/`index ` are unambiguous — a content row can never start with
     * them, because every in-hunk row carries a marker column first.
     * `--- `/`+++ ` are not: inside an open hunk they are content
     * ({@see UnifiedScan} class docblock); outside one they are headers.
     * That is a deliberate asymmetry: an unmarked `diff `/`index ` row seen
     * mid-hunk means the stream lost its markers, and re-arming the walk
     * there beats consuming the next file's header as hunk content.
     */
    private static function isFileHeader(string $line, bool $inHunk): bool
    {
        if (str_starts_with($line, 'diff ') || str_starts_with($line, 'index ')) {
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
