<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

use SugarCraft\Core\Util\Width;

/**
 * The old-file/new-file line-number gutter for a unified diff — the column
 * {@see \SugarCraft\Crush\Renderer::renderDiff()} paints to the left of each
 * diff row so a reviewer can tell *which line of the file* a change lands on
 * (crush_code.md Phase 8 item 1; before this, the diff box was raw `diff -u`
 * text with add/remove colouring and nothing else).
 *
 * Pure: in, a list of already-sanitised diff rows; out, one same-length list of
 * fixed-width prefixes plus the display width they all share. It computes
 * nothing about colour — the renderer styles the prefix itself so the diff
 * body's own {@see \SugarCraft\Crush\Renderer::styleDiffLine()} bytes stay
 * byte-identical to what they were before a gutter existed.
 *
 * The formatting convention is candy-shine's
 * ({@see \SugarCraft\Shine\SyntaxHighlighter::highlight()} with `lineNumbers:
 * true`): right-aligned, padded to the widest number in the block, painted in
 * the muted/comment slot. The one deliberate divergence is the separator —
 * candy-shine uses a literal tab, which this frame cannot afford: the
 * transcript is hard-truncated to the viewport with {@see \SugarCraft\Core\Util\Width},
 * and a tab has no fixed display width, so one tab would break the
 * one-logical-line-per-row invariant renderDiff() exists to protect.
 *
 * That divergence is also why candy-shine's own gutter stays OFF for markdown
 * code fences, and it is not a loose end to tidy up later. {@see
 * \SugarCraft\Shine\Renderer} is constructed here without `lineNumbers: true`
 * deliberately: {@see \SugarCraft\Shine\SyntaxHighlighter::highlight()} joins
 * its number column to the code with a literal `"\t"`, and `Width::string("\t")`
 * is 0 while candy-sprinkles' `Style::render()` paints it as `tabWidth` spaces
 * — so flipping that flag would inject a mismeasured tab into every code-fence
 * line of every assistant reply and put the frame back into the PR #1403
 * over-wide-row failure. renderDiff() expands tabs before measuring for exactly
 * this reason; the markdown path has no such pass. Do not "finish the item" by
 * turning the flag on without giving candy-shine a measurable separator first.
 */
final class DiffGutter
{
    /**
     * Between the number columns and the diff's own `+`/`-`/` ` marker column.
     * A rule rather than blank space because the marker column is itself made
     * of punctuation: without a divider, `  12 -$x` reads as one token.
     */
    private const SEPARATOR = '│ ';

    /**
     * Largest `@@` start line this will number. A hunk claiming to begin past
     * this is not a real file — it is malformed or hostile input — and the two
     * honest ways to fail are both worse than declining: `(int)` clamps such a
     * literal to `PHP_INT_MAX`, after which the first `++` promotes the counter
     * to float and {@see format()}'s `?int` parameter rejects it with a
     * TypeError, thrown from inside {@see \SugarCraft\Crush\Chat::view()} — i.e.
     * the Program dies with the terminal still in raw mode. Clamping instead
     * would keep the frame alive but print a number that is simply wrong, which
     * is the one thing a line-number gutter must never do. So an out-of-range
     * hunk is recognised as a hunk and left unnumbered.
     */
    private const MAX_LINE_NUMBER_DIGITS = 9;

    /**
     * @param list<string> $prefixes One per input row, all {@see $width} wide.
     * @param string       $blank    A row-width prefix carrying no numbers, for
     *                               rows the renderer adds itself (the overflow
     *                               trailer) that have no line to point at.
     */
    private function __construct(
        public readonly int $width,
        public readonly array $prefixes,
        public readonly string $blank,
    ) {}

    /**
     * Build the gutter for a whole diff block.
     *
     * @param list<string> $lines
     */
    public static function forDiff(array $lines): self
    {
        $numbers = self::number($lines);

        $highest = 0;
        foreach ($numbers as [$old, $new]) {
            $highest = max($highest, $old ?? 0, $new ?? 0);
        }

        // Nothing was numberable (no hunk header, or a diff that is pure
        // headers) — there is no gutter to draw, and drawing an empty one would
        // just steal columns from the body.
        if ($highest === 0) {
            return self::none(count($lines));
        }

        $digits = strlen((string) $highest);
        $blank = self::format(null, null, $digits);

        $prefixes = [];
        foreach ($numbers as [$old, $new]) {
            $prefixes[] = self::format($old, $new, $digits);
        }

        return new self($digits * 2 + 1 + Width::string(self::SEPARATOR), $prefixes, $blank);
    }

    /**
     * A zero-width gutter: what the renderer falls back to when the viewport is
     * too narrow to spend columns on line numbers, and what {@see forDiff()}
     * returns for an unnumberable diff.
     */
    public static function none(int $rows): self
    {
        return new self(0, array_fill(0, max(0, $rows), ''), '');
    }

    /**
     * Which rows are file headers rather than diff content, under exactly the
     * rule {@see isFileHeader()} documents.
     *
     * Exposed because {@see \SugarCraft\Crush\Renderer::styleDiffLine()} has to
     * answer the same `--- `/`+++ ` question to pick a colour, and it sees one
     * row at a time with no idea whether a hunk is open. Left to itself it
     * paints a deleted `-- users table` row as a bold file header instead of a
     * red removal — the same ambiguity this class fixed for the numbers. Sharing
     * the verdict is what keeps the gutter and the colour from disagreeing about
     * what a row *is*, which would be worse than both being wrong together.
     *
     * @param list<string> $lines
     * @return list<bool> One per input row.
     */
    public static function fileHeaders(array $lines): array
    {
        return array_map(
            static fn (array $row): bool => $row[2],
            self::number($lines),
        );
    }

    private static function format(?int $old, ?int $new, int $digits): string
    {
        return str_pad((string) $old, $digits, ' ', STR_PAD_LEFT)
            . ' '
            . str_pad((string) $new, $digits, ' ', STR_PAD_LEFT)
            . self::SEPARATOR;
    }

    /**
     * Walk the diff assigning each row its old-file and new-file line number,
     * either of which is null when that side has no line there (`+` rows have
     * no old line, `-` rows have no new line, headers have neither).
     *
     * Counters come from the `@@ -a,b +c,d @@` header and advance per row the
     * way `diff -u` counts: context advances both, `-` advances old, `+`
     * advances new. Rows before the first hunk header — and any file header
     * ({@see isFileHeader()}), which is how a second file starts inside a
     * multi-file diff — reset the walk, otherwise the leading `-`/`+` of a file
     * header would be counted as a deletion and an insertion and every number
     * after it would be off by one.
     *
     * The third element of each row records whether the row was read as a file
     * header, so {@see fileHeaders()} can hand the renderer the same verdict
     * this numbering used rather than re-deciding it from the row in isolation.
     *
     * @param list<string> $lines
     * @return list<array{0:?int,1:?int,2:bool}>
     */
    private static function number(array $lines): array
    {
        $inHunk = false;
        $old = 0;
        $new = 0;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m) === 1) {
                // Matched with an unbounded \d+ on purpose: the header has to
                // be RECOGNISED even when its numbers are absurd, or the rows
                // after it get counted as context and a previous hunk's
                // numbering silently continues through them. Only the
                // numbering opts out. See MAX_LINE_NUMBER_DIGITS.
                $inHunk = self::numberable($m[1]) && self::numberable($m[2]);
                $old = $inHunk ? (int) $m[1] : 0;
                $new = $inHunk ? (int) $m[2] : 0;
                $out[] = [null, null, false];
                continue;
            }

            if (self::isFileHeader($line, $inHunk)) {
                $inHunk = false;
                $out[] = [null, null, true];
                continue;
            }

            if (!$inHunk) {
                $out[] = [null, null, false];
                continue;
            }

            // "\ No newline at end of file" annotates the line above it and
            // occupies no line of either file.
            if (str_starts_with($line, '\\')) {
                $out[] = [null, null, false];
                continue;
            }

            $marker = $line === '' ? ' ' : $line[0];
            if ($marker === '+') {
                $out[] = [null, $new, false];
                $new++;
            } elseif ($marker === '-') {
                $out[] = [$old, null, false];
                $old++;
            } else {
                // ' ' is context; an empty row is a context line whose single
                // trailing space was stripped in transit, which is common
                // enough that treating it as anything else desynchronises the
                // rest of the hunk.
                $out[] = [$old, $new, false];
                $old++;
                $new++;
            }
        }

        return $out;
    }

    /**
     * Whether this row starts a new file rather than carrying diff content.
     *
     * `diff --git `/`index ` are unambiguous — nothing else emits them.
     * `--- `/`+++ ` are not: they are also exactly what a deleted or added
     * source line reads as once the diff marker is prepended, because `--` is
     * the line-comment token in SQL, Lua, Haskell and Ada (`-- users table` in
     * a deleted row arrives here as `--- users table`), and `++ ` opens plenty
     * of added C/Java/PHP lines. Read as a header, such a row closed the hunk
     * and every remaining row of it lost its numbers — number *loss*, not wrong
     * numbers, but still the gutter failing at its one job.
     *
     * Inside a hunk the content reading is therefore the right one. That is
     * safe for multi-file diffs because git emits `diff --git `/`index ` ahead
     * of the real `--- ` of every file, and both of those close the hunk
     * unconditionally, so a genuine file header is never seen with $inHunk
     * still true.
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
