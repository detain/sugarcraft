<?php

declare(strict_types=1);

namespace SugarCraft\Code;

use Generator;

/**
 * Lazily enumerates directory entries using a Generator so that even
 * directories with thousands of files do not cause memory exhaustion.
 *
 * Mirrors ReadDirIter from Go's charmbracelet/gum.
 *
 * The former `positive-int $chunkSize` constructor knob is removed: readdir()
 * buffers at the libc layer already, and the value was declared but read
 * nowhere. The first production consumer (Tools/BuiltIn/Ls.php, SHL-2) passes
 * a PathJail-resolved directory — the gap this class used to record.
 */
final class StreamingDirectoryLister
{
    /**
     * Yield directory entries lazily.
     * Empty directories produce an empty Generator (caller handles that).
     *
     * By default every dot-prefixed entry is skipped (including `.` and `..`).
     * With $includeHidden the full directory is enumerated minus the two self
     * references — a listing tool decides visibility itself, the lister must
     * not silently swallow entries the caller asked for unfiltered.
     *
     * @param non-empty-string $path
     * @return Generator<non-negative-int, non-empty-string, mixed, void>
     *  Yields [index, absolutePath] pairs for each file/dir found.
     */
    public function list(string $path, bool $includeHidden = false)
    {
        if (!is_dir($path)) {
            return;
        }

        $handle = opendir($path);
        if ($handle === false) {
            return;
        }

        try {
            $index = 0;
            while (($entry = readdir($handle)) !== false) {
                if (!$this->visible($entry, $includeHidden)) {
                    continue;
                }

                $absolutePath = $path . \DIRECTORY_SEPARATOR . $entry;
                yield $index => $absolutePath;
                $index++;
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * List only regular files (not directories or symlinks).
     *
     * @param non-empty-string $path
     * @return Generator<non-negative-int, non-empty-string, mixed, void>
     */
    public function listFiles(string $path, bool $includeHidden = false): Generator
    {
        foreach ($this->list($path, $includeHidden) as $index => $absolutePath) {
            if (is_file($absolutePath)) {
                yield $index => $absolutePath;
            }
        }
    }

    /**
     * Count entries WITHOUT loading them into memory.
     * Uses a single-pass scan rather than building an array.
     *
     * @param non-empty-string $path
     * @return non-negative-int
     */
    public function count(string $path, bool $includeHidden = false): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $handle = opendir($path);
        if ($handle === false) {
            return 0;
        }

        try {
            $count = 0;
            while (($entry = readdir($handle)) !== false) {
                if (!$this->visible($entry, $includeHidden)) {
                    continue;
                }
                $count++;
            }
            return $count;
        } finally {
            closedir($handle);
        }
    }

    /**
     * The one visibility law both walks share: default hides every dot entry
     * (byte-compatible with the original lister); hidden-inclusive mode hides
     * only the two self references readdir() always reports.
     */
    private function visible(string $entry, bool $includeHidden): bool
    {
        if (!$includeHidden) {
            return !str_starts_with($entry, '.');
        }

        return $entry !== '.' && $entry !== '..';
    }
}
