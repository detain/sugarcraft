<?php

declare(strict_types=1);

namespace SugarCraft\Diff;

use InvalidArgumentException;
use SugarCraft\Diff\Writer\UnifiedFormat;

/**
 * A computed unified diff between a "before" and an "after" side.
 *
 * `diff -u`/`git diff --no-color`-compatible engine: common-prefix/suffix
 * trimming followed by a classic O(n*m) LCS of whatever is left in the
 * middle, grouped into context hunks with `diff -u`'s hunk-joining rule
 * (two changed regions merge whenever their context windows touch, i.e. they
 * are separated by no more than `2 * contextLines + 1` op positions). The
 * cell budget of the LCS table is capped ({@see DiffOptions::$maxLcsCells})
 * so a pathological scattered edit degrades to a valid — if non-minimal —
 * delete-all/insert-all block instead of hanging.
 *
 * Immutable value object: computed once, read many times. {@see lines()} walks
 * the raw line-mode op sequence; {@see hunks()} is the same rows grouped with
 * context; {@see unified()} renders the whole patch.
 *
 * Mirrors sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php — the trait
 * this library was extracted from (upstream-scout candidate S4), with the
 * hardcoded constants widened into {@see DiffOptions}.
 */
final class Diff
{
    /**
     * @param list<Line> $lines line-mode op sequence, in order, with 1-based
     *                          old/new line numbers per side
     * @param list<Hunk> $hunks context-grouped projection of $lines
     */
    private function __construct(
        public readonly array $lines,
        public readonly array $hunks,
        public readonly DiffOptions $options,
        public readonly int $beforeCount,
        public readonly int $afterCount,
        public readonly bool $beforeHasFinalNewline,
        public readonly bool $afterHasFinalNewline,
    ) {}

    /**
     * Diff two sides. A `string` input is counted the way `git diff` counts
     * lines — a trailing newline terminates the last line rather than adding
     * an empty one; a `list<string>` input is read as pre-split lines, each
     * assumed newline-terminated.
     *
     * @param string|list<string> $before
     * @param string|list<string> $after
     */
    public static function compute(string|array $before, string|array $after, ?DiffOptions $options = null): self
    {
        $options ??= DiffOptions::new();

        [$beforeLines, $beforeNewline] = self::parseSide($before);
        [$afterLines, $afterNewline] = self::parseSide($after);

        $ops = self::diffLines($beforeLines, $afterLines, $options);
        [$lines, $hunks] = self::assemble($ops, $options);

        return new self(
            lines: $lines,
            hunks: $hunks,
            options: $options,
            beforeCount: count($beforeLines),
            afterCount: count($afterLines),
            beforeHasFinalNewline: $beforeNewline,
            afterHasFinalNewline: $afterNewline,
        );
    }

    /** Whether the two sides are equal under the active comparison options. */
    public function isEmpty(): bool
    {
        return $this->hunks === [];
    }

    /** Total added rows across every hunk. */
    public function addedLines(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Insert ? 1 : 0),
            0,
        );
    }

    /** Total removed rows across every hunk. */
    public function removedLines(): int
    {
        return array_reduce(
            $this->lines,
            static fn (int $carry, Line $line): int => $carry + ($line->kind === LineKind::Delete ? 1 : 0),
            0,
        );
    }

    /**
     * The full `diff -u` text for this change: two header rows plus every
     * hunk. Returns '' when the sides are equal, exactly like the ported
     * `unifiedDiff()` — a caller can trust "no output" to mean "no change".
     */
    public function unified(string $oldPath, ?string $newPath = null): string
    {
        return UnifiedFormat::write($this, $oldPath, $newPath);
    }

    /** Every hunk's `@@` header and body without the `--- +++` file headers. */
    public function hunkText(): string
    {
        return UnifiedFormat::hunksOnly($this);
    }

    /**
     * Parse one side into lines plus whether its last line was
     * newline-terminated. Fail-fast on anything that is not a string list:
     * a half-accepted array would corrupt the LCS input silently.
     *
     * @param string|list<string> $side
     * @return array{0:list<string>,1:bool}
     */
    private static function parseSide(string|array $side): array
    {
        if (is_string($side)) {
            if ($side === '') {
                return [[], true];
            }

            $lines = explode("\n", $side);
            if (end($lines) === '') {
                array_pop($lines);

                return [$lines, true];
            }

            return [$lines, false];
        }

        if (!array_is_list($side)) {
            throw new InvalidArgumentException('diff sides must be a string or a list of strings');
        }

        foreach ($side as $line) {
            if (!is_string($line)) {
                throw new InvalidArgumentException('diff sides must contain only strings');
            }
        }

        return [$side, true];
    }

    /**
     * Line-level diff: common-prefix/common-suffix trimming (cheap, and exact
     * for the localized edits this engine exists for) followed by an LCS
     * number-and-annotate pass. Returns the ops as fully numbered
     * {@see Line} rows plus the hunk grouping derived from them.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return list<array{0:LineKind,1:string}>
     */
    private static function diffLines(array $old, array $new, DiffOptions $options): array
    {
        $oldCount = count($old);
        $newCount = count($new);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && self::linesMatch($old[$prefix], $new[$prefix], $options)) {
            $prefix++;
        }

        $suffix = 0;
        $maxSuffix = min($oldCount - $prefix, $newCount - $prefix);
        while (
            $suffix < $maxSuffix
            && self::linesMatch($old[$oldCount - 1 - $suffix], $new[$newCount - 1 - $suffix], $options)
        ) {
            $suffix++;
        }

        $oldMid = array_slice($old, $prefix, $oldCount - $prefix - $suffix);
        $newMid = array_slice($new, $prefix, $newCount - $prefix - $suffix);

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = [LineKind::Equal, $old[$i]];
        }

        if (count($oldMid) * count($newMid) > $options->maxLcsCells) {
            // Pathological case (a scattered replace across a huge file):
            // skip the O(n*m) table and emit a correct, if non-minimal,
            // delete-all/insert-all block for the middle.
            foreach ($oldMid as $line) {
                $ops[] = [LineKind::Delete, $line];
            }
            foreach ($newMid as $line) {
                $ops[] = [LineKind::Insert, $line];
            }
        } else {
            foreach (self::lcsOps($oldMid, $newMid, $options) as [$kind, $text]) {
                $ops[] = [$kind, $text];
            }
        }

        for ($i = 0; $i < $suffix; $i++) {
            $ops[] = [LineKind::Equal, $old[$oldCount - $suffix + $i]];
        }

        return $ops;
    }

    /**
     * Classic O(n*m) longest-common-subsequence backtrack, turned into a
     * sequence of equal/delete/insert ops. Ties break toward deleting the old
     * side first (`>=`), exactly as the ported implementation does, so
     * insertion-after-context shapes stay byte-identical.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:LineKind,1:string}>
     */
    private static function lcsOps(array $a, array $b, DiffOptions $options): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return [];
        }

        $aKeys = self::fingerprints($a, $options);
        $bKeys = self::fingerprints($b, $options);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $aKeys[$i] === $bKeys[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($aKeys[$i] === $bKeys[$j]) {
                $ops[] = [LineKind::Equal, $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = [LineKind::Delete, $a[$i]];
                $i++;
            } else {
                $ops[] = [LineKind::Insert, $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = [LineKind::Delete, $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = [LineKind::Insert, $b[$j]];
            $j++;
        }

        return $ops;
    }

    /**
     * Compare two lines under the active options: exact byte equality by
     * default; whitespace-blind and/or case-folded when asked. The fold only
     * ever gates equality — emitted text stays verbatim.
     */
    private static function linesMatch(string $old, string $new, DiffOptions $options): bool
    {
        if (!$options->ignoreWhitespace && !$options->ignoreCase) {
            return $old === $new;
        }

        return self::fingerprint($old, $options) === self::fingerprint($new, $options);
    }

    /** @param list<string> $lines @return list<string> */
    private static function fingerprints(array $lines, DiffOptions $options): array
    {
        if (!$options->ignoreWhitespace && !$options->ignoreCase) {
            return $lines;
        }

        return array_map(static fn (string $line): string => self::fingerprint($line, $options), $lines);
    }

    private static function fingerprint(string $line, DiffOptions $options): string
    {
        $folded = $line;

        if ($options->ignoreWhitespace) {
            // preg_replace with /u returns NULL on a subject that is not valid
            // UTF-8 (a binary-diff line, a Latin-1 file read as UTF-8). Casting
            // that NULL to '' would fold EVERY malformed line to the same
            // fingerprint and silently judge distinct content Equal — a diff
            // that hides real changes. Fail over to the byte-wise pattern,
            // which cannot fail: it folds ASCII whitespace and leaves the
            // malformed bytes intact, so distinct lines stay distinct.
            $stripped = preg_replace('/\s+/u', '', $folded);
            $folded = $stripped ?? (string) preg_replace('/\s+/', '', $folded);
        }

        if ($options->ignoreCase) {
            $folded = mb_strtolower($folded, 'UTF-8');
        }

        return $folded;
    }

    /**
     * Number every op row and group the changed ones into hunks: two changed
     * regions join whenever their context windows touch — separated by no more
     * than `2 * contextLines + 1` positions — matching `diff -u`'s hunk
     * merging.
     *
     * @param list<array{0:LineKind,1:string}> $ops
     * @return array{0:list<Line>,1:list<Hunk>}
     */
    private static function assemble(array $ops, DiffOptions $options): array
    {
        $oldLine = 1;
        $newLine = 1;
        $lines = [];
        $oldAnchors = [];
        $newAnchors = [];
        $changedIdx = [];

        foreach ($ops as $idx => [$kind, $text]) {
            // The anchors are the counter STATE at this row — a hunk opening
            // on an insertion still needs the old-side line its context sits
            // after, exactly like the ported annotation tuple did.
            $oldAnchors[$idx] = $oldLine;
            $newAnchors[$idx] = $newLine;
            $lines[] = new Line(
                kind: $kind,
                text: $text,
                oldNumber: $kind === LineKind::Insert ? null : $oldLine,
                newNumber: $kind === LineKind::Delete ? null : $newLine,
            );
            if ($kind === LineKind::Equal) {
                $oldLine++;
                $newLine++;
            } elseif ($kind === LineKind::Delete) {
                $oldLine++;
                $changedIdx[] = $idx;
            } else {
                $newLine++;
                $changedIdx[] = $idx;
            }
        }

        if ($changedIdx === []) {
            return [$lines, []];
        }

        $context = $options->contextLines;
        $n = count($ops);
        $groups = [];
        $groupStart = $changedIdx[0];
        $groupEnd = $changedIdx[0];
        for ($k = 1, $count = count($changedIdx); $k < $count; $k++) {
            if ($changedIdx[$k] - $groupEnd <= $context * 2 + 1) {
                $groupEnd = $changedIdx[$k];
            } else {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = $changedIdx[$k];
                $groupEnd = $changedIdx[$k];
            }
        }
        $groups[] = [$groupStart, $groupEnd];

        $hunks = [];
        foreach ($groups as [$groupStart, $groupEnd]) {
            $start = max(0, $groupStart - $context);
            $end = min($n - 1, $groupEnd + $context);

            $oldLen = 0;
            $newLen = 0;
            $body = [];
            $oldAnchor = $oldAnchors[$start];
            $newAnchor = $newAnchors[$start];

            for ($idx = $start; $idx <= $end; $idx++) {
                $body[] = $lines[$idx];
                if ($lines[$idx]->kind !== LineKind::Insert) {
                    $oldLen++;
                }
                if ($lines[$idx]->kind !== LineKind::Delete) {
                    $newLen++;
                }
            }

            // `diff -u` anchors an empty side at line 0 — there is no real
            // line number to point an empty range at.
            $hunks[] = new Hunk(
                oldStart: $oldLen === 0 ? 0 : $oldAnchor,
                oldLines: $oldLen,
                newStart: $newLen === 0 ? 0 : $newAnchor,
                newLines: $newLen,
                lines: $body,
            );
        }

        return [$lines, $hunks];
    }
}
