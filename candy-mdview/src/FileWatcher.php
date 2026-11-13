<?php

declare(strict_types=1);

namespace SugarCraft\MdView;

/**
 * File watching utility for auto-reload on change.
 *
 * Mirrors charmbracelet/glow's file watching behaviour.
 *
 * Poll contract: the pager's reload path is the ONE watcher — a fixed
 * 0.5 s `ReloadTickMsg` (MdViewModel::RELOAD_POLL_SECONDS) re-armed per
 * handled tick drives a single-shot (mtime, size) probe. No generator, no
 * sleep inside the loop, and nothing else to cancel: dropping the tick
 * chain (quit, or an un-armed watch) stops the sweeping at the next
 * boundary. An earlier test-only exponential-backoff generator (watch())
 * existed beside this pump; it was deleted rather than wired, because the
 * session's sweep cadence belongs to the model and a second mechanism to
 * drift out of sync with the README would only deepen that split.
 */
final class FileWatcher
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Check if the file's mtime differs from the given baseline.
     *
     * Strict "!==": any differing timestamp reports a change, but a file
     * restored to its EXACT prior mtime is invisible here — a git checkout
     * that rewinds the timestamp to the value we already recorded returns
     * false. That restore case is covered by {@see pollTuple()}, which
     * compares the full (mtime, size) fingerprint instead of mtime alone.
     */
    public function hasChangedSince(int $mtime): bool
    {
        // Scope the cache drop to THIS path (finding 091): a global
        // clearstatcache() in a hot poll would invalidate every other
        // cached stat the process holds.
        clearstatcache(true, $this->path);
        if (is_file($this->path) === false) {
            return false;
        }

        $currentMtime = @filemtime($this->path);

        return $currentMtime !== false && $currentMtime !== $mtime;
    }

    /**
     * Current (mtime, size) fingerprint of a file, or null when the path is
     * not a readable regular file. Baseline for {@see pollTuple()}.
     *
     * The pager's auto-reload sweep takes one snapshot per tick without
     * sleeping — a single-shot probe, so the pump contract has nothing to
     * bound here. The stat cache drop is scoped to this path (finding 091).
     *
     * @return array{0:int, 1:int}|null
     */
    public static function snapshot(string $path): ?array
    {
        clearstatcache(true, $path);
        if (is_file($path) === false) {
            return null;
        }

        $mtime = @filemtime($path);
        $size  = @filesize($path);

        return $mtime === false ? null : [$mtime, $size === false ? 0 : $size];
    }

    /**
     * One non-blocking change check against a (mtime, size) baseline.
     *
     * Returns the NEW fingerprint when the file moved off the baseline (or
     * appeared where it was absent — baseline [0, 0]), null when unchanged
     * or still missing. This tuple law is the pager's whole change story:
     * the sweep that finds a differing tuple reloads, and a rewrite that
     * preserves BOTH second-granularity mtime and byte size stays invisible
     * until the next differing change or an explicit force-reload.
     *
     * @param int $lastMtime baseline mtime (0 = "file assumed absent")
     * @param int $lastSize  baseline size in bytes (0 pairs with mtime 0)
     * @return array{0:int, 1:int}|null
     */
    public static function pollTuple(string $path, int $lastMtime, int $lastSize): ?array
    {
        $current = self::snapshot($path);
        if ($current === null) {
            return null;
        }

        return ($current[0] !== $lastMtime || $current[1] !== $lastSize) ? $current : null;
    }
}
