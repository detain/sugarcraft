<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * The owner-only directory discipline every RETAINED temp store shares: a
 * per-user directory under `sys_get_temp_dir()` that is created `0700`, refused
 * outright when what stands at its name is not a directory this effective uid
 * owns exclusively, and written into `0600` through a write-then-`rename`.
 *
 * WHY THIS IS ITS OWN CLASS. It used to live inside {@see HookContextFiles},
 * whose retained hook overflow was the first store to need it, and
 * {@see \SugarCraft\Crush\Agents\SuspendedDelegations} already borrowed its
 * verdict from there. Roadmap 2.8's {@see ToolOutputSpill} is a third store with
 * the same threat model — a world-writable parent another local user can plant
 * a symlink, a foreign directory or a loose mode into — and a third copy of the
 * `lstat()` arms would be the fork that drifts. The rules live here once; each
 * store passes only its own LABEL, so every refusal still names the store whose
 * path was refused ("hook overflow directory … is a symbolic link").
 *
 * The reasoning behind each arm is unchanged from where it was first argued:
 *
 *  - `lstat()`, never `stat()`/`is_dir()`: those follow the final component and
 *    would describe a planted link as the tight directory it points at.
 *  - Re-inspected on every call, not remembered: PHP's stat cache would
 *    otherwise outlive an operator's `chmod 700`.
 *  - Owner checked BEFORE mode, so a tight-but-foreign directory is refused by
 *    the ownership arm rather than vacuously by the mode arm.
 *  - The uid comparison is SKIPPED (not failed) on a build without
 *    `posix_geteuid()`; the type and mode arms still hold.
 *  - Files go through a `.partial` that is `lstat()`-ed before a byte is
 *    addressed to it, written under `umask(077)` (so they are 0600 for their
 *    whole life, never chmod-ed after) and renamed into place, so no consumer
 *    observes a torn file and no planted link at the intermediate name is
 *    truncated through.
 */
final class PrivateRetainedDir
{
    /**
     * Exception code naming a SECURITY refusal (as opposed to a full or
     * read-only disk, which carries code 0). {@see HookContextFiles} re-exports
     * the same value under its historical name.
     */
    public const REFUSAL_UNSAFE_DIRECTORY = 7;

    /** Mode a retained directory is created with: this uid in, nobody else. */
    public const DIRECTORY_MODE = 0o700;

    /**
     * Group and world, read/write/execute: the umask around every create and the
     * mask of the accept-path mode refusal.
     */
    public const FOREIGN_ACCESS_BITS = 0o077;

    /** Suffix of the intermediate a write lands in before its rename. */
    public const PARTIAL_SUFFIX = '.partial';

    private function __construct()
    {
    }

    /**
     * The per-user directory name for $stem under the system temp directory,
     * for the given effective uid (null on a build with no posix extension,
     * which shares one `-noposix` scope).
     */
    public static function pathFor(string $stem, ?int $uid): string
    {
        return sys_get_temp_dir() . '/' . $stem . '-' . ($uid === null ? 'noposix' : (string) $uid);
    }

    /** {@see pathFor()} for this process's effective uid. */
    public static function forCurrentUser(string $stem): string
    {
        return self::pathFor($stem, \function_exists('posix_geteuid') ? \posix_geteuid() : null);
    }

    /**
     * $dir, created `0700` when absent, or an exception when what stands there
     * may not be written through.
     *
     * $label names the store in every message ("hook overflow", "tool output
     * spill"), so an operator reading a refusal knows whose path it was.
     *
     * @throws \RuntimeException code {@see REFUSAL_UNSAFE_DIRECTORY} for a
     *         symlink, a non-directory, a foreign owner or a loose mode; code 0
     *         when nothing is there and nothing could be created.
     */
    public static function verified(string $dir, string $label): string
    {
        clearstatcache(true, $dir);
        $stat = @lstat($dir);

        if ($stat === false) {
            $previous = umask(self::FOREIGN_ACCESS_BITS);

            try {
                @mkdir($dir, self::DIRECTORY_MODE, true);
            } finally {
                umask($previous);
            }

            clearstatcache(true, $dir);
            $stat = @lstat($dir);
        }

        if ($stat === false) {
            throw new \RuntimeException('unable to create ' . $label . ' directory: ' . $dir);
        }

        $reason = self::refusalReason($dir, $stat, $label);

        if ($reason !== null) {
            throw new \RuntimeException($reason, self::REFUSAL_UNSAFE_DIRECTORY);
        }

        return $dir;
    }

    /**
     * Whether $dir, observed WITHOUT creating anything, is a directory this
     * process may trust: the read-side twin of {@see verified()}, for a caller
     * deciding whether a path under it may be READ (the spill allow-list), where
     * creating the directory as a side effect of the question would be wrong.
     */
    public static function isTrusted(string $dir): bool
    {
        clearstatcache(true, $dir);
        $stat = @lstat($dir);

        return $stat !== false && self::refusalReason($dir, $stat, 'retained') === null;
    }

    /**
     * Why $dir (one `lstat()` observation) may not be written through, or null.
     *
     * @param array<int|string, int> $stat
     */
    private static function refusalReason(string $dir, array $stat, string $label): ?string
    {
        $mode = (int) $stat['mode'];
        $type = $mode & 0o170000;

        if ($type === 0o120000) {
            return $label . ' directory ' . $dir . ' is a symbolic link rather than a directory, '
                . 'so this store will not write through it';
        }

        if ($type !== 0o040000) {
            return $label . ' path ' . $dir . ' exists and is not a directory';
        }

        $uid = \function_exists('posix_geteuid') ? \posix_geteuid() : null;

        if ($uid !== null && (int) $stat['uid'] !== $uid) {
            return sprintf(
                '%s directory %s is owned by uid %d and this process is uid %d, so it is not '
                    . 'a directory this user can be sure of',
                $label,
                $dir,
                (int) $stat['uid'],
                $uid,
            );
        }

        if (($mode & self::FOREIGN_ACCESS_BITS) !== 0) {
            return sprintf(
                '%s directory %s is mode %04o, which lets other users on this box reach the output '
                    . 'retained in it. Fix it with: chmod 700 %s',
                $label,
                $dir,
                $mode & 0o7777,
                $dir,
            );
        }

        return null;
    }

    /**
     * Write $text to `$dir/$name`, privately (0600) and atomically, and return
     * the final path. $dir must already have passed {@see verified()}.
     *
     * The intermediate is `lstat()`-ed BEFORE the umask block and outside the
     * try: anything already at that name is a collision or a plant, it is not
     * ours, and the failure path must not unlink it. `file_put_contents` would
     * otherwise open it `O_TRUNC` and follow a link planted there. An `x`-mode
     * open is the textbook spelling, and it is a member of
     * {@see \SugarCraft\Crush\Tests\Support\ReadPathCensusTest}'s sink alphabet
     * — the check-then-write window it would close sits inside a directory only
     * this uid can create entries in, which {@see verified()} is the door to.
     *
     * @throws \RuntimeException code 0 on any create/write/rename failure.
     */
    public static function write(string $dir, string $name, string $text, string $label): string
    {
        $file = $dir . '/' . $name;
        $partial = $file . self::PARTIAL_SUFFIX;

        if (@lstat($partial) !== false) {
            throw new \RuntimeException('refusing to overwrite a pre-existing ' . $label . ' partial: ' . $partial);
        }

        $previous = umask(self::FOREIGN_ACCESS_BITS);

        try {
            if (@file_put_contents($partial, $text) !== strlen($text)) {
                throw new \RuntimeException('unable to write ' . $label . ' partial: ' . $partial);
            }
        } catch (\RuntimeException $failure) {
            // The `.partial` holds the same bytes and nothing names it, so it is
            // the one artifact a failed write must never leave behind.
            @unlink($partial);

            throw $failure;
        } finally {
            umask($previous);
        }

        if (!@rename($partial, $file)) {
            @unlink($partial);

            throw new \RuntimeException('unable to finalise ' . $label . ' file: ' . $file);
        }

        return $file;
    }

    /**
     * Delete regular files older than $maxAgeSeconds directly inside $dir and
     * inside each of its immediate sub-directories, then remove any of those
     * sub-directories left empty. Returns the number of files removed.
     *
     * Bounded to ONE level of nesting and never follows a link: `lstat()` types
     * every entry, a symlink is skipped rather than resolved, and a
     * sub-directory is only entered when it is itself a real directory. A
     * `.partial` is aged like any file — a writer that died mid-write leaves one,
     * and nothing else ever names it.
     *
     * $dir is re-verified first; a directory this store would refuse to write
     * through is not one it will delete inside either.
     */
    public static function sweep(string $dir, int $maxAgeSeconds, string $label, ?int $now = null): int
    {
        try {
            self::verified($dir, $label);
        } catch (\RuntimeException) {
            return 0;
        }

        $cutoff = ($now ?? time()) - $maxAgeSeconds;
        $removed = 0;

        foreach (self::entries($dir) as $entry) {
            $path = $dir . '/' . $entry;
            $stat = @lstat($path);
            if ($stat === false) {
                continue;
            }

            $type = ((int) $stat['mode']) & 0o170000;

            if ($type === 0o100000) {
                if ((int) $stat['mtime'] < $cutoff && @unlink($path)) {
                    $removed++;
                }

                continue;
            }

            if ($type !== 0o040000) {
                continue;
            }

            $remaining = 0;
            foreach (self::entries($path) as $inner) {
                $innerPath = $path . '/' . $inner;
                $innerStat = @lstat($innerPath);
                if (
                    $innerStat !== false
                    && (((int) $innerStat['mode']) & 0o170000) === 0o100000
                    && (int) $innerStat['mtime'] < $cutoff
                    && @unlink($innerPath)
                ) {
                    $removed++;

                    continue;
                }

                $remaining++;
            }

            if ($remaining === 0) {
                @rmdir($path);
            }
        }

        return $removed;
    }

    /**
     * The entry names of $dir, `.`/`..` excluded; empty when it cannot be listed.
     *
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
    }
}
