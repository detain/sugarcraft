<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * Default `Pane` lister — reads a directory off the live
 * filesystem via `scandir` + `lstat`. The whole rest of the app
 * accepts a `Closure(string $path): list<Entry>|null` so tests can
 * substitute a deterministic in-memory fake.
 *
 * No upstream — house pattern (finding 051). superfile walks directories
 * inline in its bubbletea update path; the injectable-lister contract here
 * is the library's own.
 *
 * CF-22 (closes audit 031): null means "cannot read" (missing path,
 * not a directory, or `scandir` refused) and is NEVER collapsed to
 * `[]` — an empty list is a fact ("this directory is empty"), while
 * a silent `[]` over a 0500 dir was the UI lying that a real listing
 * had succeeded. Callers must keep the two states apart.
 *
 * Consistency disclosure: size/mtime come from `lstat()` (the link's own
 * inode for symlinks), while `isDir` deliberately FOLLOWS the link so a
 * symlinked directory stays navigable. A symlink to a directory therefore
 * reports isDir=true with the link's own size — intentional, not a mismatch
 * to "fix" by switching to stat().
 */
final class FsLister
{
    public static function lister(): \Closure
    {
        return static function (string $path): ?array {
            if (!\is_dir($path)) {
                return null;
            }
            $names = @\scandir($path);
            if ($names === false) {
                return null;
            }
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
                // lstat() does not follow symlinks, so isLink comes straight
                // from the mode bits with no extra syscall. isDir, however,
                // must FOLLOW the link: a symlink whose target is a directory
                // has to report isDir=true, or navigate() treats it as a file
                // and refuses to enter it. Only pay the extra is_dir() syscall
                // for the (rare) symlink case; plain files/dirs stay bitmask-only.
                // S_IFMT=0170000 mask, S_IFLNK=0120000 symlink, S_IFDIR=0040000 directory
                $isLink = ($mode & 0170000) === 0120000;
                $isDir  = $isLink
                    ? \is_dir($full)
                    : ($mode & 0170000) === 0040000;
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
