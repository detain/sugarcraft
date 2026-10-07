<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

use SugarCraft\Diff\Diff;

/**
 * The `diff -u`/`git diff --no-color`-compatible unified-diff builder shared by
 * every file-mutating built-in tool.
 *
 * Extracted verbatim from {@see \SugarCraft\Crush\Tools\BuiltIn\Edit}, where it
 * was born, when {@see \SugarCraft\Crush\Tools\BuiltIn\Write} landed
 * (crush_code.md Phase 8 item 12): a new file is just an edit whose "before"
 * side is empty, so it deserves the same before/after preview and the same
 * permission-gating treatment rather than a second, drifting implementation.
 *
 * The diff ENGINE is {@see Diff} from sugar-diff — this trait no longer carries
 * its own LCS/hunk-joining copy. The twin had drifted (the library emits
 * GNU-faithful hunk headers: a count of 1 is elided and a zero-count side
 * anchors at the line the hunk sits after, while the private copy printed the
 * old `-N,1`/`-0,0` shape for both), which is what folded it away rather than
 * syncing it.
 *
 * What stays here is the part the library deliberately does not own: memory
 * bounded by the size of the CHANGE, not of the file (audit F-T2). The first
 * version split both whole files into line arrays and built one op per
 * unchanged line, which cost about 18x the file size: a one-line edit of a
 * 36 MB file peaked at 650 MB. The unchanged prefix and suffix are still
 * trimmed at the byte level on the original strings (compared in fixed-size
 * chunks, line numbers counted with `substr_count()`), and only the differing
 * middle plus CONTEXT_LINES of context on each side is ever split into lines
 * and handed to the library. The library numbers its hunks relative to that
 * window, so {@see relocateHunkHeaders()} shifts them back to file-absolute
 * positions; the rendered diff is byte-identical to diffing the whole files
 * with the library (the window always carries full context around every hunk,
 * and the library's own line-level trim re-finds the same alignment inside
 * it). A changed region too large to be worth previewing is not diffed at all
 * — see {@see diffPreview()}.
 *
 * Every method is private and static — this is a self-contained policy, not a
 * behavioural mixin, and nothing here reads the using class's state.
 */
trait BuildsUnifiedDiff
{
    /** Lines of unchanged context shown around each hunk, matching `diff -u`'s default. */
    private const CONTEXT_LINES = 3;

    /**
     * Past this many changed lines (old side + new side of the trimmed middle)
     * no preview is built. The library's own cell guard keeps the TIME bounded
     * even for a scattered `replace_all` (delete-all/insert-all fallback) but
     * still allocates a diff body bigger than both inputs — a `replace_all`
     * across a large file, or a Write of a large new file, would rebuild most
     * of F-T2's cost through the middle. A 20,000-line diff is also past what
     * anyone reviews in a transcript.
     */
    private const MAX_DIFF_LINES = 20_000;

    /** The same bound in bytes, for a change made of a few enormous lines. */
    private const MAX_DIFF_BYTES = 4 * 1024 * 1024;

    /**
     * Bytes compared per step while trimming the common prefix/suffix: large
     * enough that a 36 MB file is a few hundred comparisons, small enough that
     * the two chunk copies each step makes are negligible next to the file.
     */
    private const COMPARE_CHUNK_BYTES = 65_536;

    /**
     * Build a unified diff of a write. Callers put this on
     * {@see \SugarCraft\Crush\Tools\ToolResult::diff()} verbatim -- no prefix,
     * no surrounding prose -- so a renderer can pass it straight to
     * `sugar-stash\DiffViewer::fromRawDiff()` without string-scanning
     * `content()` for a `--- a/` line.
     *
     * Returns '' both for "nothing changed" and for a preview that was omitted
     * as too large; a caller that must tell the two apart uses
     * {@see diffPreview()}.
     */
    private static function unifiedDiff(string $path, string $oldContent, string $newContent): string
    {
        return self::diffPreview($path, $oldContent, $newContent)['diff'];
    }

    /**
     * The diff, plus what a caller needs to describe a change whose diff was
     * omitted. When `omitted` is true, `diff` is '' and `added`/`removed` count
     * the lines of the trimmed changed region — what the delete-all/insert-all
     * fallback would have shown — so a caller can still report the size of the
     * change it made.
     *
     * @return array{diff: string, added: int, removed: int, omitted: bool}
     */
    private static function diffPreview(string $path, string $old, string $new): array
    {
        $oldLen = strlen($old);
        $newLen = strlen($new);

        // Byte-level trimming, snapped to line boundaries. A cut is only ever
        // placed just after a "\n" that lies INSIDE the common region, so the
        // lines on the far side of it are byte-identical in both files; any
        // equal line the snapping leaves inside the middle is re-trimmed by
        // the library's own line-level pass, which is what keeps the result
        // identical to diffing the whole files.
        $oldStart = self::lineStartBefore($old, self::commonPrefixLength($old, $new));
        $newStart = $oldStart;
        $suffixBytes = self::commonSuffixLength($old, $new, min($oldLen - $oldStart, $newLen - $oldStart));
        $cut = $suffixBytes === 0 ? false : strpos($old, "\n", $oldLen - $suffixBytes);
        $tailBytes = $cut === false ? 0 : $oldLen - ($cut + 1);
        $oldEnd = $oldLen - $tailBytes;
        $newEnd = $newLen - $tailBytes;

        $oldMidLines = self::lineCount($old, $oldStart, $oldEnd);
        $newMidLines = self::lineCount($new, $newStart, $newEnd);

        if (
            $oldMidLines + $newMidLines > self::MAX_DIFF_LINES
            || ($oldEnd - $oldStart) + ($newEnd - $newStart) > self::MAX_DIFF_BYTES
        ) {
            return ['diff' => '', 'added' => $newMidLines, 'removed' => $oldMidLines, 'omitted' => true];
        }

        $oldMid = self::splitLines(substr($old, $oldStart, $oldEnd - $oldStart));
        $newMid = self::splitLines(substr($new, $newStart, $newEnd - $newStart));

        // Only the context a hunk can actually show is materialised: the
        // CONTEXT_LINES lines just before and just after the changed middle.
        // The prefix side is the same bytes in both files, so old's copy is
        // used; the suffix side may differ only in a final "\n" that
        // splitLines() does not count, so old's lines are again the lines.
        $contextFrom = self::lineStartsBack($old, $oldStart, self::CONTEXT_LINES);
        $before = self::splitLines(substr($old, $contextFrom, $oldStart - $contextFrom));
        $contextTo = self::lineEndsForward($old, $oldEnd, self::CONTEXT_LINES);
        $after = self::splitLines(substr($old, $oldEnd, $contextTo - $oldEnd));

        // The window is a list, so the library trusts it as pre-split lines
        // (a final unterminated line in the real file can never sit inside a
        // window that ends at a line boundary, so no EOF marker is owed).
        $diff = Diff::compute(
            array_merge($before, $oldMid, $after),
            array_merge($before, $newMid, $after),
        );
        $hunks = self::relocateHunkHeaders($diff->hunkText(), self::countNewlines($old, 0, $contextFrom));
        if ($hunks === '') {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        }

        return [
            'diff' => "--- a/{$path}\n+++ b/{$path}\n" . $hunks,
            'added' => $diff->addedLines(),
            'removed' => $diff->removedLines(),
            'omitted' => false,
        ];
    }

    /**
     * The sentence a tool appends to its result when {@see diffPreview()}
     * omitted the diff, so the model is told why the change has no preview
     * rather than seeing a write that silently lost it.
     *
     * @param array{diff: string, added: int, removed: int, omitted: bool} $preview
     */
    private static function omittedDiffNote(array $preview): string
    {
        return sprintf(
            ' (changed region: +%d -%d lines; diff preview omitted, over the %s-line / %s-byte preview bound)',
            $preview['added'],
            $preview['removed'],
            number_format(self::MAX_DIFF_LINES),
            number_format(self::MAX_DIFF_BYTES),
        );
    }

    /**
     * Shift both start numbers of every `@@` header by the number of lines
     * that precede the window, turning the library's window-relative line
     * numbers into file-absolute ones. Only headers begin a line with "@@":
     * context, added, removed and no-newline rows all carry a one-character
     * prefix, so the anchored pattern can never rewrite a body row. A
     * zero-count side's GNU anchor shifts identically — its clamp to 0 is
     * only reachable when the hunk is the window's first line with no
     * context, which forces the delta to 0 as well.
     */
    private static function relocateHunkHeaders(string $hunks, int $delta): string
    {
        if ($delta === 0 || $hunks === '') {
            return $hunks;
        }

        return (string) preg_replace_callback(
            '/^@@ -(\d+)((?:,\d+)?)( \+)(\d+)((?:,\d+)?)/m',
            static fn (array $m): string => '@@ -'
                . ((int) $m[1] + $delta) . $m[2] . $m[3]
                . ((int) $m[4] + $delta) . $m[5],
            $hunks,
        );
    }

    /**
     * Split text into lines the way `git diff` counts them: a trailing
     * newline is the line terminator, not an extra empty final line.
     *
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** Length of the common byte prefix of $a and $b. */
    private static function commonPrefixLength(string $a, string $b): int
    {
        $limit = min(strlen($a), strlen($b));
        for ($i = 0; $i < $limit; $i += self::COMPARE_CHUNK_BYTES) {
            $n = min(self::COMPARE_CHUNK_BYTES, $limit - $i);
            $chunkA = substr($a, $i, $n);
            $chunkB = substr($b, $i, $n);
            if ($chunkA !== $chunkB) {
                // XOR is NUL exactly where the bytes agree.
                return $i + strspn($chunkA ^ $chunkB, "\0");
            }
        }

        return $limit;
    }

    /** Length of the common byte suffix of $a and $b, at most $max. */
    private static function commonSuffixLength(string $a, string $b, int $max): int
    {
        $lenA = strlen($a);
        $lenB = strlen($b);
        for ($i = 0; $i < $max; $i += self::COMPARE_CHUNK_BYTES) {
            $n = min(self::COMPARE_CHUNK_BYTES, $max - $i);
            $chunkA = substr($a, $lenA - $i - $n, $n);
            $chunkB = substr($b, $lenB - $i - $n, $n);
            if ($chunkA !== $chunkB) {
                return $i + strspn(strrev($chunkA ^ $chunkB), "\0");
            }
        }

        return $max;
    }

    /** Offset just after the last "\n" before $limit, or 0 when there is none. */
    private static function lineStartBefore(string $text, int $limit): int
    {
        if ($limit === 0) {
            return 0;
        }
        // A negative strrpos() offset admits a match starting at or before
        // strlen + offset, so this finds a "\n" at an index below $limit.
        $nl = strrpos($text, "\n", $limit - 1 - strlen($text));

        return $nl === false ? 0 : $nl + 1;
    }

    /** "\n" bytes in [$from, $to), without the substr() copy. */
    private static function countNewlines(string $text, int $from, int $to): int
    {
        return $to > $from ? substr_count($text, "\n", $from, $to - $from) : 0;
    }

    /**
     * Lines in [$from, $to), counted the way {@see splitLines()} would count
     * that slice: a final line without a "\n" still counts.
     */
    private static function lineCount(string $text, int $from, int $to): int
    {
        if ($to <= $from) {
            return 0;
        }

        return self::countNewlines($text, $from, $to) + ($text[$to - 1] === "\n" ? 0 : 1);
    }

    /**
     * Offset of the start of the line $lines lines before the line starting at
     * $offset (or 0, when the text has fewer lines than that before $offset).
     */
    private static function lineStartsBack(string $text, int $offset, int $lines): int
    {
        for ($k = 0; $k < $lines && $offset > 0; $k++) {
            // $offset - 1 is the "\n" ending the previous line; its own start
            // is just after the "\n" before that.
            $offset = self::lineStartBefore($text, $offset - 1);
        }

        return $offset;
    }

    /** Offset just past the $lines lines that follow $offset (or the end of the text). */
    private static function lineEndsForward(string $text, int $offset, int $lines): int
    {
        $len = strlen($text);
        for ($k = 0; $k < $lines && $offset < $len; $k++) {
            $nl = strpos($text, "\n", $offset);
            $offset = $nl === false ? $len : $nl + 1;
        }

        return $offset;
    }
}
