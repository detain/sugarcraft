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
 * delete-all/insert-all block instead of hanging; {@see self::degraded}
 * reports when that fired. In practice, with the default budget an ordinary
 * few-hundred-line file whose edits sit far apart can still degrade, and the
 * caller — not the engine — decides whether to re-run with a bigger
 * {@see DiffOptions::withMaxLcsCells()}.
 *
 * Immutable value object: computed once, read many times. {@see self::$hunks}
 * is the RETAINED row model — numbered `Line` rows for the changed regions
 * plus context only; the op sequence survives as one packed byte per row, so
 * a one-line change in a huge file keeps hunk-sized state instead of one
 * `Line` per input row (sugar-diff-021). {@see self::lines()} materializes
 * the full raw line-mode sequence on demand (memoized after the first call),
 * and {@see self::unified()} renders the whole patch from the retained hunks.
 *
 * Mirrors candy-code/src/Tools/Concerns/BuildsUnifiedDiff.php — the trait
 * this library was extracted from, with the hardcoded constants widened
 * into {@see DiffOptions}.
 */
final class Diff
{
    /** Packed op-sequence byte codes: one byte per row of the line-mode walk. */
    private const K_EQUAL = "\x00";
    private const K_DELETE = "\x01";
    private const K_INSERT = "\x02";

    /**
     * @param list<Hunk>   $hunks  context-grouped rows for the changed regions
     * @param int          $beforeCount rows on the before side
     * @param int          $afterCount rows on the after side
     * @param string       $kinds  packed line-mode op sequence, one byte per row
     * @param string       $beforeSource verbatim before side, re-walked by lines()
     * @param string       $afterSource verbatim after side, re-walked by lines()
     */
    private function __construct(
        public readonly array $hunks,
        public readonly DiffOptions $options,
        public readonly int $beforeCount,
        public readonly int $afterCount,
        public readonly bool $beforeHasFinalNewline,
        public readonly bool $afterHasFinalNewline,
        public readonly bool $degraded,
        private readonly string $kinds,
        private readonly string $beforeSource,
        private readonly string $afterSource,
    ) {}

    /** @var ?list<Line> memoized full row sequence for the lazy lines() accessor */
    private ?array $lineCache = null;

    /**
     * Diff two sides. A `string` input is counted the way `git diff` counts
     * lines — a trailing newline terminates the last line rather than adding
     * an empty one; a `list<string>` input is read as pre-split lines, each
     * one exactly one line: an element carrying an interior `"\n"` (what
     * `file($path)` returns without FILE_IGNORE_NEW_LINES) is refused
     * fail-fast, because emitting it would produce a marker-less row that
     * breaks the patch for every reader. List sides are therefore always read
     * as terminated, and {@see DiffOptions::withNoNewlineMarker()} can only
     * annotate an unterminated end on STRING sides.
     *
     * @param string|list<string> $before
     * @param string|list<string> $after
     */
    public static function compute(string|array $before, string|array $after, ?DiffOptions $options = null): self
    {
        $options ??= DiffOptions::new();

        [$beforeLines, $beforeNewline] = self::parseSide($before, 'before');
        [$afterLines, $afterNewline] = self::parseSide($after, 'after');

        [$kinds, $degraded] = self::diffLines($beforeLines, $afterLines, $options);
        $hunks = self::assemble($kinds, $beforeLines, $afterLines, $options);

        return new self(
            hunks: $hunks,
            options: $options,
            beforeCount: count($beforeLines),
            afterCount: count($afterLines),
            beforeHasFinalNewline: $beforeNewline,
            afterHasFinalNewline: $afterNewline,
            degraded: $degraded,
            kinds: $kinds,
            beforeSource: is_string($before) ? $before : self::joinSide($beforeLines),
            afterSource: is_string($after) ? $after : self::joinSide($afterLines),
        );
    }

    /** Whether the two sides are equal under the active comparison options. */
    public function isEmpty(): bool
    {
        return $this->hunks === [];
    }

    /**
     * The full line-mode op sequence, in order, with 1-based old/new line
     * numbers per side — the derived half of the model. compute() retains
     * only the hunk bodies and the packed op bytes; this walk materializes
     * one `Line` per input row on first call and returns the memoized list
     * thereafter. Consumers that render changed regions should read
     * {@see self::$hunks}; calling this on a huge diff costs what the old
     * eager model always cost.
     *
     * @return list<Line>
     */
    public function lines(): array
    {
        if ($this->lineCache === null) {
            $this->lineCache = $this->materialize();
        }

        return $this->lineCache;
    }

    /** Total added rows — counted on the packed op bytes, no materialization. */
    public function addedLines(): int
    {
        return substr_count($this->kinds, self::K_INSERT);
    }

    /** Total removed rows — counted on the packed op bytes, no materialization. */
    public function removedLines(): int
    {
        return substr_count($this->kinds, self::K_DELETE);
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

    /** Rebuild the full numbered row sequence from the packed op bytes and the raw sides. */
    private function materialize(): array
    {
        $lines = [];
        $oldPos = 0;
        $newPos = 0;
        $oldNumber = 0;
        $newNumber = 0;
        $count = strlen($this->kinds);

        for ($i = 0; $i < $count; $i++) {
            $code = $this->kinds[$i];

            if ($code === self::K_EQUAL) {
                $oldNumber++;
                $newNumber++;
                [$text, $oldPos] = self::sourceLine($this->beforeSource, $oldPos);
                // The row stands on both sides: the after-side cursor skips
                // it too (the fold may make the bytes differ — only the old
                // side's verbatim text is ever emitted).
                $newPos += strcspn($this->afterSource, "\n", $newPos) + 1;
                $lines[] = new Line(kind: LineKind::Equal, text: $text, oldNumber: $oldNumber, newNumber: $newNumber);
                continue;
            }

            if ($code === self::K_DELETE) {
                $oldNumber++;
                [$text, $oldPos] = self::sourceLine($this->beforeSource, $oldPos);
                $lines[] = new Line(kind: LineKind::Delete, text: $text, oldNumber: $oldNumber, newNumber: null);
                continue;
            }

            $newNumber++;
            [$text, $newPos] = self::sourceLine($this->afterSource, $newPos);
            $lines[] = new Line(kind: LineKind::Insert, text: $text, oldNumber: null, newNumber: $newNumber);
        }

        return $lines;
    }

    /**
     * One verbatim line of a raw side starting at $pos, plus the byte offset
     * after it. The newline is a separator, never part of the text — the
     * same reading parseSide's explode gives.
     *
     * @return array{0:string,1:int}
     */
    private static function sourceLine(string $source, int $pos): array
    {
        $length = strcspn($source, "\n", $pos);

        return [substr($source, $pos, $length), $pos + $length + 1];
    }

    /**
     * Re-join a validated list side into raw text so lines() can re-walk it
     * from one string (list sides are always read as newline-terminated).
     *
     * @param list<string> $lines
     */
    private static function joinSide(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * Parse one side into lines plus whether its last line was
     * newline-terminated. Fail-fast on anything that is not a clean string
     * list: a half-accepted array would corrupt the LCS input silently, and
     * an element carrying an interior newline would emit a row that breaks
     * the patch format for every reader downstream.
     *
     * @param string|list<string> $side
     * @param string $name which side this is, for the refusal message
     * @return array{0:list<string>,1:bool}
     */
    private static function parseSide(string|array $side, string $name): array
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
            throw new InvalidArgumentException("diff sides must be a string or a list of strings ({$name} side)");
        }

        foreach ($side as $index => $line) {
            if (!is_string($line)) {
                throw new InvalidArgumentException(
                    "diff sides must contain only strings; element {$index} of the {$name} side is not a string",
                );
            }

            if (str_contains($line, "\n")) {
                // file()/explode() residue without FILE_IGNORE_NEW_LINES:
                // one element must mean one line. Accepting it would emit a
                // marker-less multi-line row the scan walk cannot re-count.
                throw new InvalidArgumentException(
                    "diff sides must contain only strings without an embedded newline;"
                    . " element {$index} of the {$name} side carries one",
                );
            }
        }

        return [$side, true];
    }

    /**
     * Line-level diff: common-prefix/common-suffix trimming (cheap, and exact
     * for the localized edits this engine exists for) followed by an LCS
     * number-and-annotate pass. Returns the packed op-byte sequence (text is
     * never carried through the ops — numbering and hunk grouping happen in
     * {@see self::assemble()}, and the lazy rows come from the raw sides)
     * plus whether the middle was too large for the LCS table and took the
     * delete-all/insert-all fallback.
     *
     * @param list<string> $old
     * @param list<string> $new
     * @return array{0:string,1:bool}
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

        // Only a MIDDLE with rows on both sides is quadratic work; with one
        // side empty the table is linear, there is nothing to fall back to,
        // and a zero budget must not label untouched input "degraded".
        $degraded = $oldMid !== []
            && $newMid !== []
            && (count($oldMid) + 1) * (count($newMid) + 1) > $options->maxLcsCells;

        // Pathological case (a scattered replace across a huge file): skip the
        // O(n*m) table and emit a correct, if non-minimal, delete-all/insert-all
        // block for the middle. The bound is the table lcsKinds actually
        // allocates — (n+1)*(m+1) cells, the padding row and column included —
        // not n*m.
        $middle = $degraded
            ? str_repeat(self::K_DELETE, count($oldMid)) . str_repeat(self::K_INSERT, count($newMid))
            : self::lcsKinds($oldMid, $newMid, $options);

        return [
            str_repeat(self::K_EQUAL, $prefix) . $middle . str_repeat(self::K_EQUAL, $suffix),
            $degraded,
        ];
    }

    /**
     * Classic O(n*m) longest-common-subsequence backtrack, turned into packed
     * equal/delete/insert op bytes. Ties break toward deleting the old side
     * first (`>=`), exactly as the ported implementation does, so
     * insertion-after-context shapes stay byte-identical.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return string one op byte per emitted row
     */
    private static function lcsKinds(array $a, array $b, DiffOptions $options): string
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 && $m === 0) {
            return '';
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

        $kinds = '';
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($aKeys[$i] === $bKeys[$j]) {
                $kinds .= self::K_EQUAL;
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $kinds .= self::K_DELETE;
                $i++;
            } else {
                $kinds .= self::K_INSERT;
                $j++;
            }
        }

        return $kinds . str_repeat(self::K_DELETE, $n - $i) . str_repeat(self::K_INSERT, $m - $j);
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
            // Same failure class as the whitespace leg: mb_strtolower maps
            // every byte it cannot decode to '?', so two lines differing only
            // in WHICH invalid byte they carry (b"\xFF" vs b"\xFE") would fold
            // to one fingerprint and a real change would vanish. Fold with
            // Unicode casing only when the subject is valid UTF-8; otherwise
            // fall back to the byte-wise ASCII fold, which leaves every
            // undecodable byte intact and distinct.
            $folded = mb_check_encoding($folded, 'UTF-8')
                ? mb_strtolower($folded, 'UTF-8')
                : strtolower($folded);
        }

        return $folded;
    }

    /**
     * Group the changed op runs into hunks and materialize their rows: two
     * changed regions join whenever their context windows touch — separated by
     * no more than `2 * contextLines + 1` positions — matching `diff -u`'s
     * hunk merging. Each window's anchor is the walk's own counter state at
     * its first row (what the old full-size $oldAnchors/$newAnchors arrays
     * held), derived on the fly — only the group starts are ever read, and
     * the groups are walked in order.
     *
     * @param string       $kinds packed op bytes, one per row
     * @param list<string> $beforeLines
     * @param list<string> $afterLines
     * @return list<Hunk>
     */
    private static function assemble(string $kinds, array $beforeLines, array $afterLines, DiffOptions $options): array
    {
        $n = strlen($kinds);
        $changed = [];
        for ($i = 0; $i < $n; $i++) {
            if ($kinds[$i] !== self::K_EQUAL) {
                $changed[] = $i;
            }
        }

        if ($changed === []) {
            return [];
        }

        $context = $options->contextLines;
        $groups = [];
        $groupStart = $changed[0];
        $groupEnd = $changed[0];
        for ($k = 1, $count = count($changed); $k < $count; $k++) {
            if ($changed[$k] - $groupEnd <= $context * 2 + 1) {
                $groupEnd = $changed[$k];
            } else {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = $changed[$k];
                $groupEnd = $changed[$k];
            }
        }
        $groups[] = [$groupStart, $groupEnd];

        $hunks = [];
        $walk = 0;
        $oldIdx = 0;
        $newIdx = 0;

        foreach ($groups as [$groupStart, $groupEnd]) {
            $start = max(0, $groupStart - $context);
            $end = min($n - 1, $groupEnd + $context);

            while ($walk < $start) {
                if ($kinds[$walk] !== self::K_INSERT) {
                    $oldIdx++;
                }
                if ($kinds[$walk] !== self::K_DELETE) {
                    $newIdx++;
                }
                $walk++;
            }
            $oldAnchor = $oldIdx + 1;
            $newAnchor = $newIdx + 1;

            $oldLen = 0;
            $newLen = 0;
            $body = [];
            while ($walk <= $end) {
                $code = $kinds[$walk];
                if ($code === self::K_DELETE) {
                    $body[] = new Line(
                        kind: LineKind::Delete,
                        text: $beforeLines[$oldIdx],
                        oldNumber: $oldIdx + 1,
                        newNumber: null,
                    );
                    $oldIdx++;
                    $oldLen++;
                } elseif ($code === self::K_INSERT) {
                    $body[] = new Line(
                        kind: LineKind::Insert,
                        text: $afterLines[$newIdx],
                        oldNumber: null,
                        newNumber: $newIdx + 1,
                    );
                    $newIdx++;
                    $newLen++;
                } else {
                    $body[] = new Line(
                        kind: LineKind::Equal,
                        text: $beforeLines[$oldIdx],
                        oldNumber: $oldIdx + 1,
                        newNumber: $newIdx + 1,
                    );
                    $oldIdx++;
                    $newIdx++;
                    $oldLen++;
                    $newLen++;
                }
                $walk++;
            }

            // A zero-length side anchors at the line the hunk sits AFTER
            // (line 0 before the first line), the GNU `diff -U0` rule —
            // verified against the /usr/bin/diff oracle 2026-10-07 and pinned
            // by tests/GnuHunkHeaderParityTest.php. The anchors above hold the
            // counter STATE at the first row, i.e. one past the preceding line
            // when the hunk opens on an insertion, hence the -1.
            $hunks[] = new Hunk(
                oldStart: $oldLen === 0 ? max(0, $oldAnchor - 1) : $oldAnchor,
                oldLines: $oldLen,
                newStart: $newLen === 0 ? max(0, $newAnchor - 1) : $newAnchor,
                newLines: $newLen,
                lines: $body,
            );
        }

        return $hunks;
    }
}
