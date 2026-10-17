<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * One-time move of a pre-rename data/config directory to its new location.
 * Returns a human-readable notice when a move happened, null otherwise.
 * Never moves when the new path already exists (the user may have both; we don't merge).
 *
 * WHY THIS EXISTS. plan_rename.md step 0.5: when an app's state directory
 * changes name (the `~/.sugar-crush` -> `~/.candy-code` family), the app must // @rename-keep
 * find its users' existing state on first launch after the upgrade. // @rename-keep
 * rename(2) within one filesystem is atomic and keeps the directory's owner
 * and mode, so the private 0700 dirs survive the move and every downstream
 * ownership/perms check still passes — which is why whole-directory moves are
 * the sanctioned migration shape. The missing-new-path precondition is the
 * other half of that safety: merging two trees could silently shadow files, so
 * when both exist we refuse and let the user decide.
 *
 * @see LegacyEnv the env-var sibling of this shim
 */
final class LegacyPaths
{
    /**
     * @return string|null "moved …" notice on success, "could not move …"
     *                     notice when a move was attempted but failed (the old
     *                     dir is left in place), null when nothing applies.
     */
    public static function migrate(string $oldPath, string $newPath): ?string
    {
        if (!is_dir($oldPath) || file_exists($newPath)) {
            return null;
        }

        @mkdir(dirname($newPath), 0700, true);

        error_clear_last();
        if (@rename($oldPath, $newPath)) {
            return "moved {$oldPath} -> {$newPath}";
        }

        $last = error_get_last();

        return "could not move {$oldPath} -> {$newPath}: " . ($last['message'] ?? 'unknown error');
    }
}
