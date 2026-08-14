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
     * Build a unified diff of a write. Callers put this on
     * {@see \SugarCraft\Crush\Tools\ToolResult::diff()} verbatim -- no prefix,
     * no surrounding prose -- so a renderer can pass it straight to
     * `sugar-stash\DiffViewer::fromRawDiff()` without string-scanning
     * `content()` for a `--- a/` line.
     */
    private static function unifiedDiff(string $path, string $oldContent, string $newContent): string
    {
        $ops = self::diffLines(self::splitLines($oldContent), self::splitLines($newContent));
        $hunks = self::buildHunks($ops);

        if ($hunks === '') {
            return '';
        }

        return "--- a/{$path}\n+++ b/{$path}\n" . $hunks;
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

    /**
     * Line-level diff via common-prefix/common-suffix trimming (cheap, and
     * exact for the overwhelming majority of Edit calls, which touch one
     * localized region) followed by an LCS-based diff of whatever's left in
     * the middle.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0:'eq'|'del'|'ins',1:string}>
     */
    private static function diffLines(array $old, array $new): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        $maxSuffix = min($oldCount - $prefix, $newCount - $prefix);
        while (
            $suffix < $maxSuffix
            && $old[$oldCount - 1 - $suffix] === $new[$newCount - 1 - $suffix]
        ) {
            $suffix++;
        }

        $oldMid = array_slice($old, $prefix, $oldCount - $prefix - $suffix);
        $newMid = array_slice($new, $prefix, $newCount - $prefix - $suffix);

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['eq', $old[$i]];
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

        for ($i = 0; $i < $suffix; $i++) {
            $ops[] = ['eq', $old[$oldCount - $suffix + $i]];
        }

        return $ops;
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
     * @param list<array{0:'eq'|'del'|'ins',1:string}> $ops
     */
    private static function buildHunks(array $ops): string
    {
        $n = count($ops);
        $oldLine = 1;
        $newLine = 1;
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
