<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * Default `Pane` lister — reads a directory off the live
 * filesystem via `scandir` + `lstat`. The whole rest of the app
 * accepts a `Closure(string $path): list<Entry>` so tests can
 * substitute a deterministic in-memory fake.
 */
final class FsLister
{
    public static function lister(): \Closure
    {
        return static function (string $path): array {
            if (!\is_dir($path)) {
                return [];
            }
            $names = @\scandir($path) ?: [];
            $out = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = rtrim($path, '/') . '/' . $name;
                $stat = @lstat($full);
                if ($stat === false) {
                    continue;
                }
                $mode = $stat['mode'];
                // Use bitmask from mode bits — avoids 2 extra syscalls per entry
                // S_IFMT=0170000 mask, S_IFLNK=0120000 symlink, S_IFDIR=0040000 directory
                $isLink = ($mode & 0170000) === 0120000;
                $isDir  = ($mode & 0170000) === 0040000;
                $out[] = new Entry(
                    name:     $name,
                    isDir:    $isDir,
                    size:     (int) $stat['size'],
                    mtime:    (int) $stat['mtime'],
                    isLink:   $isLink,
                    isHidden: str_starts_with($name, '.'),
                );
            }
            return $out;
        };
    }
}
