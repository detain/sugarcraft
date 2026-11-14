<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Writer;

use SugarCraft\Diff\Diff;
use SugarCraft\Diff\Hunk;
use SugarCraft\Diff\Line;
use SugarCraft\Diff\LineKind;

/**
 * Renders a computed {@see Diff} in the GNU `diff -u` / `git diff --no-color`
 * text shape: two `--- ` / `+++ ` header rows (no timestamp — the git form,
 * since the engine diffs content, not files on disk) followed by
 * `@@ -a,b +c,d @@` hunks with `' '`/`-`/`+` marker columns.
 *
 * Two GNU affordances are opt-in through {@see \SugarCraft\Diff\DiffOptions}
 * so the default output stays byte-identical to the ported engine:
 * `withDevNullOnEmptySide()` labels an empty side `/dev/null` (git's
 * new/deleted-file header) and `withNoNewlineAnnotation()` annotates rows that sit
 * at an unterminated end-of-file with `\ No newline at end of file`.
 *
 * Mirrors the string assembly of
 * candy-code/src/Tools/Concerns/BuildsUnifiedDiff.php (`unifiedDiff()` +
 * `buildHunks()`' body loop).
 *
 * The path labels of the header rows are sanitised (see {@see quotePath()})
 * because they are interpolated into a line-oriented protocol; hunk row
 * bodies ship as the engine computed them — content bytes are the caller's
 * payload, not this writer's injection surface.
 */
final class UnifiedFormat
{
    /** The annotation row GNU emits after an end-of-file line without a newline. */
    public const NO_NEWLINE_ANNOTATION = "\\ No newline at end of file\n";

    private function __construct()
    {
    }

    /**
     * The full patch text — `--- +++` headers plus every hunk. '' when the
     * sides are equal, so callers can treat empty output as "no change".
     */
    public static function write(Diff $diff, string $oldPath, ?string $newPath = null): string
    {
        $options = $diff->options;
        $oldLabel = $options->devNullOnEmptySide && $diff->beforeCount === 0
            ? '/dev/null'
            : "a/{$oldPath}";
        $newLabel = $options->devNullOnEmptySide && $diff->afterCount === 0
            ? '/dev/null'
            : 'b/' . ($newPath ?? $oldPath);

        return self::writeWithLabels($diff, $oldLabel, $newLabel);
    }

    /**
     * The same full patch text with CALLER-SUPPLIED header labels — the
     * additive entry for consumers (candy-code's diff window) that hand the
     * header their own file-ish names and must not go back to string
     * building for it: the labels ship through `quotePath()` verbatim, so a
     * hostile name can never forge a `+++ ` row (sugar-diff-003). No `a/`
     * or `b/` prefix is forced and no `/dev/null` substitution happens —
     * what you pass is what is quoted and emitted. Equal sides emit '' like
     * {@see self::write()}.
     */
    public static function writeWithLabels(Diff $diff, string $oldLabel, string $newLabel): string
    {
        if ($diff->hunks === []) {
            return '';
        }

        return "--- " . self::quotePath($oldLabel) . "\n+++ " . self::quotePath($newLabel) . "\n"
            . self::hunksOnly($diff);
    }

    /** Every hunk's header and body, without the file-header rows. */
    public static function hunksOnly(Diff $diff): string
    {
        $out = '';
        foreach ($diff->hunks as $hunk) {
            $out .= self::renderHunk($diff, $hunk);
        }

        return $out;
    }

    /**
     * core.quotePath-style treatment for the path tokens (one deliberate
     * deviation from git — bytes ≥ 0x80 pass raw; the `a/`/`b/` prefix
     * riding inside the quoted string is exactly what live git does
     * — see the README Fidelity notes). Labels
     * are raw interpolation targets of a line-oriented protocol, so a path carrying a
     * control byte could otherwise forge its own `+++ ` row, drop a raw CSI
     * sequence into the terminal, or desynchronise a counter-tracking
     * {@see \SugarCraft\Diff\Reader\UnifiedScan}. Clean paths pass through
     * byte-identical (the common case, and the pinned contract); anything
     * else ships C-quoted, `\n`-style, so every emitted row is one physical
     * line no matter what a hostile filename contains. Bytes ≥ 0x80 are left
     * raw — none of them can forge a row boundary (no newline, quote or
     * backslash lives above 0x7E); the residual C1 passthrough is the one
     * declared deviation, pending the OG-4 ruling on widening the escape arm
     * (sugar-diff-006).
     */
    private static function quotePath(string $label): string
    {
        if (preg_match('/[\x00-\x1F\x7F"\\\\]/', $label) !== 1) {
            return $label;
        }

        $escaped = '';
        $length = strlen($label);
        for ($i = 0; $i < $length; $i++) {
            $byte = $label[$i];
            $escaped .= match ($byte) {
                "\x08" => '\\b',
                "\x09" => '\\t',
                "\x0A" => '\\n',
                "\x0B" => '\\v',
                "\x0C" => '\\f',
                "\x0D" => '\\r',
                '"' => '\\"',
                '\\' => '\\\\',
                default => $byte < "\x20" || $byte === "\x7F"
                    ? sprintf('\\%03o', ord($byte))
                    : $byte,
            };
        }

        return '"' . $escaped . '"';
    }

    private static function renderHunk(Diff $diff, Hunk $hunk): string
    {
        $out = $hunk->header() . "\n";
        foreach ($hunk->lines() as $line) {
            $out .= $line->toUnifiedRow();
            if (self::sitsAtUnterminatedEof($diff, $line)) {
                $out .= self::NO_NEWLINE_ANNOTATION;
            }
        }

        return $out;
    }

    /**
     * Whether this row is the last line of a side whose file ends without a
     * newline — the position the `\ No newline at end of file` annotation
     * attaches to. Emitted once even when a context row closes both sides.
     */
    private static function sitsAtUnterminatedEof(Diff $diff, Line $line): bool
    {
        if (!$diff->options->noNewlineAnnotation) {
            return false;
        }

        $closesOld = $line->kind !== LineKind::Insert
            && $diff->beforeCount > 0
            && $line->oldNumber === $diff->beforeCount
            && !$diff->beforeHasFinalNewline;

        $closesNew = $line->kind !== LineKind::Delete
            && $diff->afterCount > 0
            && $line->newNumber === $diff->afterCount
            && !$diff->afterHasFinalNewline;

        return $closesOld || $closesNew;
    }
}
