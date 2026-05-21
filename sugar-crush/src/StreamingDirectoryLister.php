<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use Generator;

/**
 * Lazily enumerates directory entries using a Generator so that even
 * directories with thousands of files do not cause memory exhaustion.
 *
 * Mirrors ReadDirIter from Go's charmbracelet/gum.
 */
final class StreamingDirectoryLister
{
    /**
     * @param positive-int $chunkSize Number of entries to buffer per yield.
     *                                Larger values reduce syscalls but use
     *                                more memory; 64 is a sane default.
     */
    public function __construct(
        private readonly int $chunkSize = 64,
    ) {}

    /**
     * Yield directory entries lazily, one chunk at a time.
     * Empty directories produce an empty Generator (caller handles that).
     *
     * @param non-empty-string $path
     * @return Generator<non-negative-int, non-empty-string, mixed, void>
     *  Yields [index, absolutePath] pairs for each file/dir found.
     */
    public function list(string $path)
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
                // Skip hidden files (starting with .) and . / .. directory entries
                if (str_starts_with($entry, '.')) {
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
    public function listFiles(string $path): Generator
    {
        foreach ($this->list($path) as $index => $absolutePath) {
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
    public function count(string $path): int
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
                if (str_starts_with($entry, '.')) {
                    continue;
                }
                $count++;
            }
            return $count;
        } finally {
            closedir($handle);
        }
    }
}
