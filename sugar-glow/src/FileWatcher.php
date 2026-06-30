<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

/**
 * File watching utility for auto-reload on change.
 *
 * Mirrors charmbracelet/glow's file watching behaviour.
 */
final class FileWatcher
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Check if the file has been modified since the given mtime.
     * Uses "!==" so that a file restored to its exact prior mtime is detected
     * as a change (covers git checkout restoring an older timestamp).
     */
    public function hasChangedSince(int $mtime): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        clearstatcache();
        $currentMtime = @filemtime($this->path);

        return $currentMtime !== false && $currentMtime !== $mtime;
    }

    /**
     * Watch a file for changes, yielding true each time it is modified.
     *
     * Uses (mtime, size) tuple polling to catch same-second edits that
     * mtime alone would miss on filesystems with 1-second granularity.
     *
     * @blocking This generator runs an infinite blocking loop with usleep().
     *           Must be consumed in a worker process or with async event loop integration.
     *           Never foreach directly outside a coroutine dispatcher or the event loop will block.
     * @see \SugarCraft\Glow\FileWatcher::watch() — CALIBER_LEARNINGS.md pattern:glow
     *
     * @param string $path Path to watch
     * @param int $intervalMs Polling interval in milliseconds
     * @return \Generator<bool> Yields true on each change
     */
    public static function watch(string $path, int $intervalMs = 500): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $lastMtime = @filemtime($path);
        $lastSize  = @filesize($path);
        if ($lastMtime === false) {
            return;
        }

        while (true) {
            usleep($intervalMs * 1000);
            clearstatcache();
            $currentMtime = @filemtime($path);
            $currentSize  = @filesize($path);

            if ($currentMtime !== false && ($currentMtime !== $lastMtime || $currentSize !== $lastSize)) {
                $lastMtime = $currentMtime;
                $lastSize  = $currentSize;
                yield true;
            }
        }
    }
}
