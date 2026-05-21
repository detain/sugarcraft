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
     */
    public function hasChangedSince(int $mtime): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        clearstatcache();
        $currentMtime = @filemtime($this->path);

        return $currentMtime !== false && $currentMtime > $mtime;
    }

    /**
     * Watch a file for changes, yielding true each time it is modified.
     *
     * Uses filemtime polling — works cross-platform without extensions.
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
        if ($lastMtime === false) {
            return;
        }

        while (true) {
            usleep($intervalMs * 1000);
            clearstatcache();
            $currentMtime = @filemtime($path);

            if ($currentMtime !== false && $currentMtime !== $lastMtime) {
                $lastMtime = $currentMtime;
                yield true;
            }
        }
    }
}
