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
 * new/deleted-file header) and `withNoNewlineMarker()` annotates rows that sit
 * at an unterminated end-of-file with `\ No newline at end of file`.
 *
 * Mirrors the string assembly of
 * sugar-crush/src/Tools/Concerns/BuildsUnifiedDiff.php (`unifiedDiff()` +
 * `buildHunks()`' body loop).
 */
final class UnifiedFormat
{
    /** The annotation row GNU emits after an end-of-file line without a newline. */
    public const NO_NEWLINE_ROW = "\\ No newline at end of file\n";

    private function __construct()
    {
    }

    /**
     * The full patch text — `--- +++` headers plus every hunk. '' when the
     * sides are equal, so callers can treat empty output as "no change".
     */
    public static function write(Diff $diff, string $oldPath, ?string $newPath = null): string
    {
        if ($diff->hunks === []) {
            return '';
        }

        $options = $diff->options;
        $oldLabel = $options->devNullOnEmptySide && $diff->beforeCount === 0
            ? '/dev/null'
            : "a/{$oldPath}";
        $newLabel = $options->devNullOnEmptySide && $diff->afterCount === 0
            ? '/dev/null'
            : 'b/' . ($newPath ?? $oldPath);

        return "--- {$oldLabel}\n+++ {$newLabel}\n" . self::hunksOnly($diff);
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

    private static function renderHunk(Diff $diff, Hunk $hunk): string
    {
        $out = $hunk->header() . "\n";
        foreach ($hunk->lines() as $line) {
            $out .= $line->toUnifiedRow();
            if (self::sitsAtUnterminatedEof($diff, $line)) {
                $out .= self::NO_NEWLINE_ROW;
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
        if (!$diff->options->noNewlineMarker) {
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
