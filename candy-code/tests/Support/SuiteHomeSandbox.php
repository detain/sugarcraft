<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Support\SafeDelete;

/**
 * THE SUITE'S HOME SANDBOX AND ITS COURTESY PRUNE, IN ONE HONEST PLACE.
 *
 * `tests/bootstrap.php` pins the run's HOME at a per-uid sandbox under the
 * suite temp root (ISS-125) and stamps it with the creator sentinel
 * ({@see SafeDelete::stampSentinel()}). Because the sandbox is never torn
 * down, its growth is bounded by an age prune — and until round F that prune
 * was an inline walk in the bootstrap itself. The rv7 review (finding F-1)
 * proved the inline walk's defect: it unlinked ANY aged non-link file, the
 * sentinel included, so the first run older than the window deleted its own
 * licence, `carriesSentinel()` answered false forever after, and the gate
 * permanently disabled the very cleanup it guarded — an unbounded-per-uptime
 * disk leak wearing the shape of a safety.
 *
 * The logic therefore lives here, once, as a static the pin can call
 * directly (a behavioral pin, not a source scan of bootstrap), with the
 * cure the finding prescribed: the walk skips the sentinel by NAME. The
 * stamp stays write-once-by-creator — bootstrap stamps only when it
 * creates — and a courtesy never eats its own ticket at any depth, because
 * a nested stamp is somebody's licence too.
 *
 * The gate keeps its position ahead of every unlink, and its polarity
 * stays refuse-unstamped: an empty or sentinel-less (or symlinked) base is
 * returned from untouched, which is the fail-safe direction the sentinel
 * policy was designed to point.
 */
final class SuiteHomeSandbox
{
    /**
     * Age window for the courtesy prune. The rationale is the bootstrap's,
     * recorded verbatim there and honored here: one day clears yesterday's
     * runs while the longest live run (the full serial, under an hour) stays
     * hours under the blade, so a concurrent sibling's fresh fixtures are
     * never in range.
     */
    public const SECONDS_PER_DAY = 86400;

    /**
     * Empty stale REGULAR files out of a sentinel-stamped sandbox tree.
     *
     * Directories, symlinks, the tree's shape, and the creator sentinel
     * itself all survive; only non-link files older than
     * {@see SECONDS_PER_DAY} relative to $now are unlinked, each
     * best-effort with the same `@` discipline the inline walk had (a file a
     * live sibling just re-claimed is not an error worth a failed suite).
     */
    public static function pruneCourtesy(string $base, int $now): void
    {
        // is_link first: a symlinked sandbox would send the walk into the
        // link's target. And the sentinel gate sits ahead of every unlink —
        // without it this whole routine is a refusal.
        if ($base === '' || is_link($base) || !SafeDelete::carriesSentinel($base)) {
            return;
        }

        $boundary = $now - self::SECONDS_PER_DAY;
        $pruneStack = [$base];
        while ($pruneStack !== []) {
            $pruneDir = array_pop($pruneStack);
            foreach ((array) @scandir($pruneDir) as $pruneEntry) {
                if ($pruneEntry === '.' || $pruneEntry === '..') {
                    continue;
                }
                if ($pruneEntry === SafeDelete::SENTINEL) {
                    // F-1: this file is what licenses the walk. Unlinking it
                    // when it ages out flips carriesSentinel() to false
                    // forever and permanently disables this prune.
                    continue;
                }
                $prunePath = $pruneDir . '/' . $pruneEntry;
                if (is_link($prunePath)) {
                    continue;
                }
                if (is_dir($prunePath)) {
                    $pruneStack[] = $prunePath;

                    continue;
                }
                $pruneMtime = @filemtime($prunePath);
                if ($pruneMtime !== false && $pruneMtime < $boundary) {
                    @unlink($prunePath);
                }
            }
        }
    }
}
