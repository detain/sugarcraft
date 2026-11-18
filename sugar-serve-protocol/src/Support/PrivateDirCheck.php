<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Support;

/**
 * The read-side refusal rules for a per-user private directory: it must be a
 * real directory (not a symlink or a file), owned by the running user, and
 * mode 0700. Mirrors the refusal half of candy-code's `Support\PrivateDir`;
 * creating or repairing the directory stays with the host.
 */
final class PrivateDirCheck
{
    /** The only mode a private state directory may carry. */
    public const MODE = 0o700;

    private const TYPE_MASK = 0o170000;

    private const TYPE_DIRECTORY = 0o040000;

    private const TYPE_SYMLINK = 0o120000;

    private function __construct()
    {
    }

    /**
     * Why $dir is not a usable private directory, or null when it is one.
     * $uid defaults to the running user; tests pass another to exercise the
     * ownership rule.
     */
    public static function refusal(string $dir, string $label, ?int $uid = null): ?string
    {
        clearstatcache(true, $dir);
        $stat = @\lstat($dir);
        if ($stat === false) {
            return \sprintf('%s directory %s does not exist and could not be created', $label, $dir);
        }

        $mode = (int) $stat['mode'];
        if (($mode & self::TYPE_MASK) === self::TYPE_SYMLINK) {
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
