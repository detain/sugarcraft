<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Support;

/**
 * The library's single atomic-write discipline (042): content lands on an
 * exclusive temp inode in the TARGET DIRECTORY, gets its mode settled
 * before payload bytes, is fsynced, and only then is renamed over the
 * destination. A crash or a full disk can therefore only ever leave the
 * OLD bytes or the orphaned temp visible — never a truncated file that
 * silently un-publishes a visibility marker or zeroes a config.
 *
 * The shape is the one the user store already pioneered (UserStore's
 * `users.json.<hex>.tmp` exchange): xb-open, chmod, write, fflush+fsync,
 * rename, unlink-on-failure. Callers that must keep a LIVE LOCK on the
 * target inode (the pidfile's flock role, 090) cannot use rename
 * publication — replacing the inode would orphan the lock — and carry
 * their own in-place write instead.
 *
 * Residue policy (107): every temp name minted here follows the
 * `.<basename>.<hex>.tmp` signature so a boot sweep can recognise
 * strays a SIGKILL left behind without touching unrelated files.
 */
final class AtomicWriter
{
    /** Default age before a stray temp of ours is swept (107: one hour). */
    public const STALE_TEMP_AGE_SECONDS = 3600;

    /**
     * Our temp-name signature: hidden dot-prefix, any basename, hex
     * entropy, .tmp tail — plus the LFS sibling shape `.oid.tmp-<hex>`
     * and the older user-store shape `users.json.<hex>.tmp` (no dot
     * prefix). The sweep matches ONLY these families, so an operator's
     * own scratch files in the same directory are never candidates.
     */
    private const TEMP_SIGNATURE = '/^(?:[A-Za-z0-9._@+-]+\.(?:[0-9a-f]{8,}|tmp-[0-9a-f]{8,})\.tmp|\.tmp-[0-9a-f]{8,}|[A-Za-z0-9._@+-]+\.tmp-[0-9a-f]{8,})$/';

    private function __construct()
    {
    }

    /**
     * Publish $contents at $path atomically.
     *
     * @param int $mode Explicit octal mode for the final file (never
     *                  umask-derived: a credential-bearing write must not
     *                  world-open on a 0022 deploy box).
     * @throws \RuntimeException On any failure; the temp is removed and
     *                           the destination keeps its previous bytes.
     */
    public static function write(string $path, string $contents, int $mode = 0600): void
    {
        $dir      = \dirname($path);
        $base     = \basename($path);
        $temp     = $dir . '/.' . $base . '.' . \bin2hex(\random_bytes(6)) . '.tmp';
        $handle   = @\fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("atomic-write: cannot open temp {$temp}");
        }

        $published = false;
        try {
            // Mode before payload: no world-readable window on the inode
            // that will become the target.
            if (@\chmod($temp, $mode) === false) {
                throw new \RuntimeException("atomic-write: cannot set mode on {$temp}");
            }
            $remaining = $contents;
            while ($remaining !== '') {
                $n = \fwrite($handle, $remaining);
                if ($n === false) {
                    throw new \RuntimeException("atomic-write: write failed for {$path}");
                }
                $remaining = \substr($remaining, $n);
            }
            if (!@\fflush($handle)) {
                throw new \RuntimeException("atomic-write: flush failed for {$path}");
            }
            if (!@\fsync($handle)) {
                throw new \RuntimeException("atomic-write: fsync failed for {$path}");
            }
            \fclose($handle);
            $handle    = null;
            if (@\rename($temp, $path) === false) {
                throw new \RuntimeException("atomic-write: rename failed for {$path}");
            }
            $published = true;
        } finally {
            if ($handle !== null) {
                \fclose($handle);
            }
            if (!$published) {
                @\unlink($temp); // no half-written residue, no orphaned secret
            }
        }
    }

    /**
     * Create (or mode-settle) a directory with an EXPLICIT mode —
     * mkdir's mode argument is umask-eroded, so the chmod after creation
     * is what actually makes the requested bits true (038: the init jail
     * must not land 0755 on a permissive-umask box).
     */
    public static function ensureDir(string $path, int $mode): void
    {
        if (!\is_dir($path) && !@\mkdir($path, $mode, true) && !\is_dir($path)) {
            throw new \RuntimeException("cannot create directory {$path}");
        }
        @\chmod($path, $mode);
    }

    /**
     * Sweep stray temps we minted (107): files matching our temp
     * signature whose mtime is older than $olderThanSeconds and whose
     * owner is the running user. $maxDepth > 0 descends subdirectories
     * (the LFS shard tree) but only through plain directories, and the
     * walk is capped — it is a hygiene sweep, not a catalogue.
     *
     * @return int count of removed files
     */
    public static function sweepStaleTemps(string $dir, int $olderThanSeconds = self::STALE_TEMP_AGE_SECONDS, int $maxDepth = 0): int
    {
        $removed = 0;
        $entries = @\scandir($dir);
        if ($entries === false) {
            return 0;
        }
        $cutoff  = \time() - $olderThanSeconds;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if ($maxDepth > 0 && \is_dir($path) && !\is_link($path)) {
                $removed += self::sweepStaleTemps($path, $olderThanSeconds, $maxDepth - 1);
                continue;
            }
            if (\preg_match(self::TEMP_SIGNATURE, $name) !== 1) {
                continue;
            }
            if (!\is_file($path)) {
                continue;
            }
            if (\function_exists('fileowner') && @\fileowner($path) !== \getmyuid()) {
                continue; // another uid's lookalike is not ours to delete
            }
            $mtime = @\filemtime($path);
            if ($mtime === false || $mtime > $cutoff) {
                continue; // fresh temps belong to an in-flight exchange
            }
            if (@\unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
