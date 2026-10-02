<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Concerns;

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
 * Memory is bounded by the size of the CHANGE, not of the file (audit F-T2).
 * The first version split both whole files into line arrays and built one
 * `['eq', line]` op per unchanged line of the common prefix and suffix, which
 * cost about 18x the file size: a one-line edit of a 36 MB file peaked at
 * 650 MB. The unchanged prefix and suffix are now trimmed at the byte level on
 * the original strings (compared in fixed-size chunks, line numbers counted
 * with `substr_count()`), and only the differing middle plus CONTEXT_LINES of
 * context on each side is ever split into lines. The output is byte-identical
 * to the line-array version; only what it allocates changed. A changed region
 * too large to be worth previewing is not diffed at all — see
 * {@see diffPreview()}.
 *
 * Every method is private and static — this is a self-contained algorithm, not
 * a behavioural mixin, and nothing here reads the using class's state.
 */
trait BuildsUnifiedDiff
{
    /** Lines of unchanged context shown around each hunk, matching `diff -u`'s default. */
    private const CONTEXT_LINES = 3;

    /**
     * Guard against the O(n*m) LCS table on a huge scattered `replace_all`
     * edit (common-prefix/suffix trimming only shrinks the middle region
     * for a *localized* change) - past this many cells we fall back to a
     * non-minimal but still-valid delete-all/insert-all hunk instead of
     * hanging the request.
     */
    private const MAX_LCS_CELLS = 250_000;

    /**
     * Past this many changed lines (old side + new side of the trimmed middle)
     * no preview is built. The delete-all/insert-all fallback above keeps the
     * TIME bounded but still allocates an op per line and a diff body bigger
     * than both inputs — a `replace_all` across a large file, or a Write of a
     * large new file, would rebuild most of F-T2's cost through the middle. A
     * 20,000-line diff is also past what anyone reviews in a transcript.
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
        // equal line the snapping leaves in the middle is picked up by the
        // line-level trimming below, which is what keeps the result identical
        // to trimming whole line arrays.
        $oldStart = self::lineStartBefore($old, self::commonPrefixLength($old, $new));
        $newStart = $oldStart;
        $suffixBytes = self::commonSuffixLength($old, $new, min($oldLen - $oldStart, $newLen - $oldStart));
        $cut = $suffixBytes === 0 ? false : strpos($old, "\n", $oldLen - $suffixBytes);
        $tailBytes = $cut === false ? 0 : $oldLen - ($cut + 1);
        $oldEnd = $oldLen - $tailBytes;
        $newEnd = $newLen - $tailBytes;

        // Line-level trimming of what is left, exactly as the line-array
        // version trimmed the whole files: equal leading lines first, then
        // equal trailing lines from what the prefix left over.
        $prefixLines = self::countNewlines($old, 0, $oldStart);
        while ($oldStart < $oldEnd && $newStart < $newEnd) {
            [$oLine, $oNext] = self::lineAt($old, $oldStart, $oldEnd);
            [$nLine, $nNext] = self::lineAt($new, $newStart, $newEnd);
            if ($oLine !== $nLine) {
                break;
            }
            $oldStart = $oNext;
            $newStart = $nNext;
            $prefixLines++;
        }
        while ($oldStart < $oldEnd && $newStart < $newEnd) {
            [$oLine, $oFrom] = self::lastLineIn($old, $oldStart, $oldEnd);
            [$nLine, $nFrom] = self::lastLineIn($new, $newStart, $newEnd);
            if ($oLine !== $nLine) {
                break;
            }
            $oldEnd = $oFrom;
            $newEnd = $nFrom;
        }

        $oldMidLines = self::lineCount($old, $oldStart, $oldEnd);
        $newMidLines = self::lineCount($new, $newStart, $newEnd);

        if ($oldMidLines === 0 && $newMidLines === 0) {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        }

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

        $ops = [];
        foreach ($before as $line) {
            $ops[] = ['eq', $line];
        }
        if (count($oldMid) * count($newMid) > self::MAX_LCS_CELLS) {
            // Pathological case (e.g. replace_all scattering changes across
            // a huge file): skip the O(n*m) table and emit a correct, if
            // non-minimal, delete-all/insert-all block for the middle.
            foreach ($oldMid as $line) {
                $ops[] = ['del', $line];
            }
            foreach ($newMid as $line) {
                $ops[] = ['ins', $line];
            }
        } else {
            array_push($ops, ...self::lcsOps($oldMid, $newMid));
        }
        foreach ($after as $line) {
            $ops[] = ['eq', $line];
        }

        $hunks = self::buildHunks($ops, $prefixLines - count($before) + 1);
        if ($hunks === '') {
            return ['diff' => '', 'added' => 0, 'removed' => 0, 'omitted' => false];
        }

        $added = 0;
        $removed = 0;
        foreach ($ops as [$type]) {
            if ($type === 'ins') {
                $added++;
            } elseif ($type === 'del') {
                $removed++;
            }
        }

        return [
            'diff' => "--- a/{$path}\n+++ b/{$path}\n" . $hunks,
            'added' => $added,
            'removed' => $removed,
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
     * The line starting at $from inside [$from, $to) and the offset of the
     * line after it.
     *
     * @return array{0: string, 1: int}
     */
    private static function lineAt(string $text, int $from, int $to): array
    {
        $nl = strpos($text, "\n", $from);
        if ($nl === false || $nl >= $to) {
            return [substr($text, $from, $to - $from), $to];
        }

        return [substr($text, $from, $nl - $from), $nl + 1];
    }

    /**
     * The last line inside [$from, $to) (a slice that ends at a line boundary
     * or at the end of the text) and the offset it starts at.
     *
     * @return array{0: string, 1: int}
     */
    private static function lastLineIn(string $text, int $from, int $to): array
    {
        $end = $text[$to - 1] === "\n" ? $to - 1 : $to;
        $start = max($from, self::lineStartBefore($text, $end));

        return [substr($text, $start, $end - $start), $start];
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

    /**
     * Classic O(n*m) longest-common-subsequence backtrack, turned into a
     * sequence of equal/delete/insert ops.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:'eq'|'del'|'ins',1:string}>
     */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['eq', $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['del', $a[$i]];
                $i++;
            } else {
                $ops[] = ['ins', $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['ins', $b[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * Group diff ops into `@@ -oldStart,oldLen +newStart,newLen @@` hunks,
     * merging two changed regions into one hunk whenever their context
     * windows would touch or overlap -- i.e. whenever they are separated by
     * no more than 2*CONTEXT_LINES + 1 index positions -- matching `diff
     * -u`'s hunk-joining behaviour.
     *
     * $firstLine is the line number of $ops[0] on both sides: the ops start
     * inside the common prefix, which has the same length in both files, so
     * one offset serves the old and the new numbering.
     *
     * @param list<array{0:'eq'|'del'|'ins',1:string}> $ops
     */
    private static function buildHunks(array $ops, int $firstLine = 1): string
    {
        $n = count($ops);
        $oldLine = $firstLine;
        $newLine = $firstLine;
        $annotated = [];
        $changedIdx = [];
        foreach ($ops as $idx => $op) {
            [$type] = $op;
            $annotated[$idx] = [$op[0], $op[1], $oldLine, $newLine];
            if ($type === 'eq') {
                $oldLine++;
                $newLine++;
            } elseif ($type === 'del') {
                $oldLine++;
                $changedIdx[] = $idx;
            } else {
                $newLine++;
                $changedIdx[] = $idx;
            }
        }

        if ($changedIdx === []) {
            return '';
        }

        $groups = [];
        $groupStart = $changedIdx[0];
        $groupEnd = $changedIdx[0];
        for ($k = 1, $count = count($changedIdx); $k < $count; $k++) {
            if ($changedIdx[$k] - $groupEnd <= self::CONTEXT_LINES * 2 + 1) {
                $groupEnd = $changedIdx[$k];
            } else {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = $changedIdx[$k];
                $groupEnd = $changedIdx[$k];
            }
        }
        $groups[] = [$groupStart, $groupEnd];

        $out = '';
        foreach ($groups as [$groupStart, $groupEnd]) {
            $start = max(0, $groupStart - self::CONTEXT_LINES);
            $end = min($n - 1, $groupEnd + self::CONTEXT_LINES);

            $oldStart = $annotated[$start][2];
            $newStart = $annotated[$start][3];
            $oldLen = 0;
            $newLen = 0;
            $body = '';
            for ($idx = $start; $idx <= $end; $idx++) {
                [$type, $line] = $annotated[$idx];
                if ($type === 'eq') {
                    $body .= " {$line}\n";
                    $oldLen++;
                    $newLen++;
                } elseif ($type === 'del') {
                    $body .= "-{$line}\n";
                    $oldLen++;
                } else {
                    $body .= "+{$line}\n";
                    $newLen++;
                }
            }

            // `diff -u` reports a start line of 0 when a hunk's old- or
            // new-side is empty (pure insertion/deletion at a boundary),
            // since there's no real line number to anchor an empty range to.
            $headerOldStart = $oldLen === 0 ? 0 : $oldStart;
            $headerNewStart = $newLen === 0 ? 0 : $newStart;

            $out .= "@@ -{$headerOldStart},{$oldLen} +{$headerNewStart},{$newLen} @@\n{$body}";
        }

        return $out;
    }
}
