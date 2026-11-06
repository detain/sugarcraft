<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Core\Util\Width;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * The old-file/new-file line-number gutter for a unified diff — the column
 * {@see \SugarCraft\Code\Renderer::renderDiff()} paints to the left of each
 * diff row so a reviewer can tell *which line of the file* a change lands on
 * (Phase 8 item 1; before this, the diff box was raw `diff -u`
 * text with add/remove colouring and nothing else).
 *
 * Pure: in, a list of already-sanitised diff rows; out, one same-length list of
 * fixed-width prefixes plus the display width they all share. It computes
 * nothing about colour — the renderer styles the prefix itself so the diff
 * body's own {@see \SugarCraft\Code\Renderer::styleDiffLine()} bytes stay
 * byte-identical to what they were before a gutter existed.
 *
 * The scan half rides the sugar-diff library: hunk recognition, per-side row
 * budgets, file-header adjudication and the digit cap all live in
 * {@see UnifiedScan} (D1a fold — this class used to keep its own copy of those
 * rules, which is exactly how a twin and its scanner drift). What stays
 * app-side is only the gutter formatting: the fixed-width prefix columns and
 * the separator this frame can afford.
 *
 * The formatting convention is sugar-markdown's
 * ({@see \SugarCraft\Markdown\SyntaxHighlighter::highlight()} with `lineNumbers:
 * true`): right-aligned, padded to the widest number in the block, painted in
 * the muted/comment slot. The one deliberate divergence is the separator —
 * sugar-markdown uses a literal tab, which this frame cannot afford: the
 * transcript is hard-truncated to the viewport with {@see \SugarCraft\Core\Util\Width},
 * and a tab has no fixed display width, so one tab would break the
 * one-logical-line-per-row invariant renderDiff() exists to protect.
 *
 * That divergence is also why sugar-markdown's own gutter stays OFF for markdown
 * code fences, and it is not a loose end to tidy up later. {@see
 * \SugarCraft\Markdown\Renderer} is constructed here without `lineNumbers: true`
 * deliberately: {@see \SugarCraft\Markdown\SyntaxHighlighter::highlight()} joins
 * its number column to the code with a literal `"\t"`, and the two halves of the
 * frame never agree about it: `Width::string("\t")` measures a fixed 4 while
 * sugar-style' `Style::render()` paints it as `tabWidth` spaces — so
 * flipping that flag would inject a mismeasured tab into every code-fence
 * line of every assistant reply and put the frame back into the PR #1403
 * over-wide-row failure. renderDiff() expands tabs before measuring for exactly
 * this reason; the markdown path has no such pass. Do not "finish the item" by
 * turning the flag on without giving sugar-markdown a measurable separator first.
 */
final class DiffGutter
{
    /**
     * Between the number columns and the diff's own `+`/`-`/` ` marker column.
     * A rule rather than blank space because the marker column is itself made
     * of punctuation: without a divider, `  12 -$x` reads as one token.
     */
    public const SEPARATOR = '│ ';

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
        $rows = UnifiedScan::lines($lines);

        $highest = 0;
        foreach ($rows as $row) {
            $highest = max($highest, $row->old ?? 0, $row->new ?? 0);
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
        foreach ($rows as $row) {
            $prefixes[] = self::format($row->old, $row->new, $digits);
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
     * rule sugar-diff's {@see UnifiedScan} documents (`diff `/`index ` are
     * unambiguous; `--- `/`+++ ` count only while no hunk is open, because a
     * deleted SQL comment arrives here as `--- users table`).
     *
     * Exposed because {@see \SugarCraft\Code\Renderer::styleDiffLine()} has to
     * answer the same `--- `/`+++ ` question to pick a colour, and it sees one
     * row at a time with no idea whether a hunk is open. Left to itself it
     * paints a deleted `-- users table` row as a bold file header instead of a
     * red removal — the same ambiguity the scan rules fix for the numbers.
     * Sharing the verdict is what keeps the gutter and the colour from
     * disagreeing about what a row *is*, which would be worse than both being
     * wrong together.
     *
     * @param list<string> $lines
     * @return list<bool> One per input row.
     */
    public static function fileHeaders(array $lines): array
    {
        return UnifiedScan::fileHeaders($lines);
    }

    /**
     * Whether this row is a hunk header at all, numberable or not — the one
     * pattern lives in {@see UnifiedScan::isHunkHeader()}, so the gutter and
     * the scan can never disagree about where a hunk starts.
     */
    public static function isHunkHeader(string $line): bool
    {
        return UnifiedScan::isHunkHeader($line);
    }

    /**
     * Whether this row is a hunk header whose numbers may be trusted — the
     * precondition the split layout needs before it derives two line-number
     * columns from {@see \SugarCraft\Diff\Writer\SideBySideFormat}.
     *
     * Probed through the shared scan: a one-line body sample of each side is
     * numbered only when that side's start field is inside the scanner's digit
     * cap AND the header declared budget for it, so an oversized header closes
     * the hunk in the scanner and both probes come back null (see
     * {@see UnifiedScan::MAX_LINE_NUMBER_DIGITS} for the float-promotion
     * reason). A valid-but-empty `@@ -0,0 +0,0 @@` declares no rows for either
     * side and so probes as unnumberable; no real producer emits one, and the
     * split then falls back to the unified paint, which would itself have
     * drawn nothing but the header.
     */
    public static function isNumberableHunk(string $line): bool
    {
        foreach (['+', '-'] as $marker) {
            $sample = UnifiedScan::lines([$line, $marker . ' probe'])[1] ?? null;
            if ($sample !== null && ($sample->old !== null || $sample->new !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display width of ONE side's number column in the split layout: the old
     * file numbers ride the left column, the new file numbers the right, so
     * each half pays $digits plus the separator instead of the unified
     * gutter's two number columns. 0 digits means no numbers at all — then no
     * column either, the same opt-out {@see none()} gives the unified paint.
     */
    public static function sideWidth(int $digits): int
    {
        return $digits <= 0 ? 0 : $digits + Width::string(self::SEPARATOR);
    }

    /**
     * The number column for one side of a split row: a right-aligned line
     * number followed by the separator, or blank cells where the side has no
     * line (a pure insertion has no old line, a pure deletion no new one).
     * Mirrors {@see format()} half-for-half so both paints pad identically.
     */
    public static function sidePrefix(?int $number, int $digits): string
    {
        if ($digits <= 0) {
            return '';
        }

        return ($number === null
            ? str_repeat(' ', $digits)
            : str_pad((string) $number, $digits, ' ', STR_PAD_LEFT)) . self::SEPARATOR;
    }

    private static function format(?int $old, ?int $new, int $digits): string
    {
        return str_pad((string) $old, $digits, ' ', STR_PAD_LEFT)
            . ' '
            . str_pad((string) $new, $digits, ' ', STR_PAD_LEFT)
            . self::SEPARATOR;
    }
}
