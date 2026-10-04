<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * An owner-private (exactly 0700) directory: created under a narrowed umask so
 * it is never wider for an instant, then VERIFIED off `lstat()` — a real
 * directory, not a symlink, owned by this uid, mode exactly 0700 (Appendix O
 * §4.6; roadmap O-4a).
 *
 * Moved out of {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor}, which
 * carried the rule twice (its IPC directory and its session index), so the
 * server's state directory ({@see \SugarCraft\Crush\Server\StateDir}) holds
 * its singleton lock and discovery file to the same rule rather than a third
 * copy.
 *
 * WHY NOT {@see PrivateRetainedDir}. That class is the retained TEMP-store
 * discipline (a sweep, a `.partial` write protocol, a mode test that only
 * refuses group/world bits). This one answers the narrower question the
 * supervisor and the server ask of a directory whose files are sockets,
 * locks and tokens: is it exactly what was asked for, 0700 and this uid's.
 *
 * `lstat()`, never `stat()`/`is_dir()`: a planted symlink must read as what it
 * is and be refused, not followed. The stat cache is cleared on every call so
 * an operator's `chmod` is seen.
 */
final class PrivateDir
{
    public const MODE = 0o700;

    private const TYPE_MASK = 0o170000;

    private const TYPE_DIRECTORY = 0o040000;

    private function __construct()
    {
    }

    /**
     * $dir, created 0700 (parents too) when absent, then verified.
     *
     * $uid is the owner the directory must have; null takes this process's
     * effective uid, and on a build without ext-posix the ownership arm is
     * skipped rather than failed (the type and mode arms still hold).
     *
     * @throws \RuntimeException naming $label and $dir when the directory
     *         cannot be created or is not owner-private
     */
    public static function ensure(string $dir, string $label, ?int $uid = null): string
    {
        clearstatcache(true, $dir);
        if (@lstat($dir) === false) {
            $previous = umask(0o077);
            try {
                @mkdir($dir, self::MODE, true);
            } finally {
                umask($previous);
            }
        }

        $reason = self::refusal($dir, $label, $uid);
        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        return $dir;
    }

    /** Whether $dir is a real, owner-private (0700) directory of $uid (null: this process). */
    public static function isPrivate(string $dir, ?int $uid = null): bool
    {
        return self::refusal($dir, 'private', $uid) === null;
    }

    /**
     * Why $dir is not a private directory of $uid, one sentence, or null when
     * it is.
     */
    public static function refusal(string $dir, string $label, ?int $uid = null): ?string
    {
        clearstatcache(true, $dir);
        $stat = @lstat($dir);
        if ($stat === false) {
            return \sprintf('%s directory %s does not exist and could not be created', $label, $dir);
        }

        $mode = (int) $stat['mode'];
        if (($mode & self::TYPE_MASK) === 0o120000) {
            return \sprintf('%s directory %s is a symbolic link, not a directory', $label, $dir);
        }
        if (($mode & self::TYPE_MASK) !== self::TYPE_DIRECTORY) {
            return \sprintf('%s path %s exists and is not a directory', $label, $dir);
        }

        $uid ??= \function_exists('posix_geteuid') ? \posix_geteuid() : null;
        if ($uid !== null && (int) $stat['uid'] !== $uid) {
            return \sprintf('%s directory %s is owned by uid %d, not by this user (uid %d)', $label, $dir, (int) $stat['uid'], $uid);
        }
        if (($mode & 0o777) !== self::MODE) {
            return \sprintf('%s directory %s is mode %04o, not 0700 — fix it with: chmod 700 %s', $label, $dir, $mode & 0o7777, $dir);
        }

        return null;
    }
}
