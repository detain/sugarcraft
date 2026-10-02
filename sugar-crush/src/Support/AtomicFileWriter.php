<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Write-then-rename publisher for non-JSON payloads.
 *
 * The package's canonical atomic writer for structured JSON is
 * {@see \SugarCraft\Core\Util\AtomicJsonFile} — but the memory system persists
 * plain text (a memory note's markdown, a generated markdown index), where the
 * array-in/JSON-out shape does not fit. This is that same discipline stripped
 * of the encoding: full payload into a uniquely-named temp in the target
 * directory, then one `rename()` onto the target. A crash mid-write can only
 * ever leave an orphan temp behind, never a half-written live file — the
 * defect the atomicity audit (M3) found when a reader could catch a truncated
 * index or note mid-`file_put_contents`.
 *
 * Append-only files (the team inboxes' `FILE_APPEND | LOCK_EX` lines) are NOT
 * this class's territory: a locked single-line append is already the atomic
 * unit there, and rewrite-on-publish would fight concurrent senders.
 *
 * WHY NOT A METHOD ON AtomicJsonFile: that class owns the JSON contract
 * (its `read()` decodes, its `write()` encodes); widening the core class to
 * raw bytes for two payload-shaped callers would weaken its parse-don't-
 * validate boundary for everyone. The temp-name convention and permission
 * derivation mirror it so a directory containing both kinds of writes looks
 * uniform on disk.
 *
 * The publish is atomic for readers either way, but callers that COMPARE AND
 * REWRITE (the inbox eviction compaction) still need a writer-side lock among
 * themselves — see {@see TimedFileLock}; this class deliberately provides
 * none, because a uniquely-named temp makes single publishes race-free
 * without serialising unrelated writers.
 *
 * TWO ENTRY POINTS, ONE TEMP-THEN-RENAME CORE. {@see write()} publishes a file
 * this package owns (fresh inode, caller-chosen mode, parents made 0700).
 * {@see replace()} rewrites a file the USER owns — the Edit/Write tools'
 * target — and so keeps what makes that file itself: its mode, best-effort
 * owner, symlink and hard links (audit F-T7). Both run through
 * {@see publish()}, so there is one implementation of the temp discipline.
 */
final class AtomicFileWriter
{
    /**
     * Atomically replace $path with $contents.
     *
     * @param string      $path     Destination file path (parent created 0700
     *                              recursively when missing).
     * @param string      $contents Exact bytes to publish.
     * @param int|null    $mode     Explicit permission bits for the published
     *                              file (0600-style); null leaves the umask to
     *                              decide, matching how plain writes behaved.
     *                              Set it whenever the payload is sensitive.
     *
     * @throws \InvalidArgumentException When $mode carries bits above 0777.
     * @throws \RuntimeException         When any step of the write or publish
     *                                   fails — the message names the target
     *                                   path. The temp is unlinked on failure.
     */
    public static function write(string $path, string $contents, ?int $mode = null): void
    {
        if ($mode !== null && ($mode & ~0777) !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'AtomicFileWriter mode 0%o carries permission bits above 0777.',
                $mode,
            ));
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s" for atomic write.', $dir));
        }

        self::publish($path, $contents, $mode, null, false, null, false);
    }

    /**
     * Replace the bytes of a file the user owns — a workspace source file the
     * Edit/Write tools rewrite — without ever exposing a truncated version of
     * it, and without changing what the file IS (audit F-T7).
     *
     * {@see write()} publishes a file this package owns, so a fresh inode at a
     * caller-chosen mode is exactly right there. A user's file is different:
     * its mode, owner and group, its symlink, and its other hard links are all
     * part of it, and a rename would silently replace each one with whatever
     * the temp happened to get. So this method:
     *
     *  - writes THROUGH a symlink: the link is resolved and the temp lands
     *    beside the link's TARGET, so the link survives and its target is
     *    updated, as the in-place write it replaces did. A dangling link is
     *    created through, in place, for the same reason;
     *  - copies the target's permission bits (07777) and, best effort, its
     *    uid/gid onto the temp BEFORE the payload, so the published inode
     *    matches the old one and the bytes are never visible under looser
     *    bits than the original's;
     *  - leaves a NEW file at the umask default (0666 & ~umask), the mode
     *    file_put_contents() gave it — the temp is opened with fopen(), not
     *    tempnam(), whose 0600 would otherwise leak onto every created file;
     *  - fsyncs the temp before the rename, because a rename that reaches the
     *    disk before the data does is the classic zero-length-after-crash
     *    file on a delayed-allocation filesystem;
     *  - falls back to an IN-PLACE write (see {@see writeInPlace()}) when a
     *    rename cannot keep the file's identity or cannot happen at all: a
     *    hard link with nlink > 1 (a rename would split it from its other
     *    names), or a temp that cannot be created or renamed beside it — a
     *    directory the user may not write while the file itself is writable,
     *    or a sticky directory holding another user's file. Those cases wrote
     *    in place before this method existed; refusing them now would turn a
     *    working edit into an error. The fallback is taken only when the temp
     *    stage failed BEFORE any byte of the payload was written, so it is
     *    never a retry over a half-done publish;
     *  - refuses an existing file that is not writable. rename() needs only the
     *    DIRECTORY's write bit, so without this a 0444 file — which the
     *    in-place write always failed on — would be silently replaced.
     *
     * OWNERSHIP IS BEST EFFORT, AND SILENTLY SO. chown() to another uid needs
     * CAP_CHOWN, and chgrp() needs membership of the target group; a normal
     * user editing a file someone else owns (but may write, through its group
     * or other bits) cannot have both. That edit is not refused — it worked in
     * place before, and the copied permission bits still govern who else may
     * read and write the new inode — but the published file is then owned by
     * the writer. A failed chown/chgrp is therefore not an error. chown runs
     * before chmod because a successful chown clears setuid/setgid.
     *
     * @param \Closure(resource, string): void|null $writer TEST SEAM. Writes
     *        the payload to the open handle and must throw on any shortfall;
     *        null is the production fwrite(). A seam that throws part-way
     *        through is how the tests prove a failed write leaves the
     *        original bytes intact.
     *
     * @throws \RuntimeException When the file cannot be replaced — the message
     *                           names $path, the original bytes are untouched
     *                           (on every route but the in-place fallback,
     *                           which documents its own window) and no temp
     *                           is left behind.
     */
    public static function replace(string $path, string $contents, ?\Closure $writer = null): void
    {
        clearstatcache(true, $path);

        $target = $path;
        if (is_link($path)) {
            $real = realpath($path);
            if ($real === false) {
                // A dangling link: there is no target to rename beside, and
                // the in-place open creates through the link exactly as
                // file_put_contents() did. Nothing exists yet, so nothing can
                // be lost by not going through a temp.
                self::writeInPlace($path, $contents, $writer);

                return;
            }
            $target = $real;
        }

        $stat = @stat($target);
        $metadata = null;
        if ($stat !== false) {
            if (!is_writable($target)) {
                throw new \RuntimeException(sprintf('Failed to write "%s": file is not writable.', $path));
            }
            if ($stat['nlink'] > 1) {
                self::writeInPlace($target, $contents, $writer);

                return;
            }
            $metadata = ['mode' => $stat['mode'] & 07777, 'uid' => $stat['uid'], 'gid' => $stat['gid']];
        }

        if (!self::publish($target, $contents, $metadata['mode'] ?? null, $metadata, true, $writer, true)) {
            self::writeInPlace($target, $contents, $writer);
        }
    }

    /**
     * The temp-then-rename core shared by {@see write()} and {@see replace()}.
     *
     * @param array{mode:int,uid:int,gid:int}|null $owner Ownership to copy onto
     *        the temp before the payload; null for a fresh file.
     * @param bool $soft When true, a temp that cannot be OPENED or RENAMED is
     *        reported by returning false instead of throwing — both failures
     *        leave the target untouched, so the caller may fall back. A failure
     *        while the payload is being written always throws: the temp is
     *        then unlinked, and falling back to an in-place write there would
     *        put the same shortfall (ENOSPC, a quota) on the live file.
     */
    private static function publish(
        string $path,
        string $contents,
        ?int $mode,
        ?array $owner,
        bool $sync,
        ?\Closure $writer,
        bool $soft,
    ): bool {
        // Unique per call so two concurrent publishes never share a temp;
        // same-directory placement keeps rename() atomic on the same fs.
        $tmp = \dirname($path) . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

        try {
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                if ($soft) {
                    return false;
                }
                // Every temp-naming message also names the TARGET: a caller (or
                // a pinned test) reads failures by the path it asked to write,
                // never by an inode suffix it has never seen.
                throw new \RuntimeException(sprintf(
                    'Failed to open temporary file "%s" for write of "%s".',
                    $tmp,
                    $path,
                ));
            }

            try {
                if ($owner !== null) {
                    $tmpStat = fstat($handle);
                    if ($tmpStat !== false && $tmpStat['uid'] !== $owner['uid']) {
                        @chown($tmp, $owner['uid']);
                    }
                    if ($tmpStat !== false && $tmpStat['gid'] !== $owner['gid']) {
                        @chgrp($tmp, $owner['gid']);
                    }
                }

                // Mode lands on the temp BEFORE the payload and BEFORE the
                // rename — the published bytes are never briefly exposed under
                // a looser mode than requested (AtomicJsonFile's ordering law).
                if ($mode !== null) {
                    if (@chmod($tmp, $mode) === false) {
                        throw new \RuntimeException(sprintf(
                            'Failed to set permissions 0%o on "%s" (target "%s").',
                            $mode,
                            $tmp,
                            $path,
                        ));
                    }
                }

                if ($writer !== null) {
                    $writer($handle, $contents);
                } else {
                    $written = fwrite($handle, $contents);
                    if ($written === false || $written !== strlen($contents)) {
                        throw new \RuntimeException(sprintf('Short write to temporary file "%s" of "%s".', $tmp, $path));
                    }
                }
                if (!fflush($handle)) {
                    throw new \RuntimeException(sprintf('Failed to flush temporary file "%s" of "%s".', $tmp, $path));
                }
                if ($sync && !fsync($handle)) {
                    throw new \RuntimeException(sprintf('Failed to sync temporary file "%s" of "%s".', $tmp, $path));
                }
            } finally {
                fclose($handle);
            }

            if (!@rename($tmp, $path)) {
                if ($soft) {
                    @unlink($tmp);

                    return false;
                }
                throw new \RuntimeException(sprintf('Failed to atomically write "%s".', $path));
            }
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }

            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException(sprintf('Failed to atomically write "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        return true;
    }

    /**
     * Overwrite $path's bytes on its own inode — the fallback {@see replace()}
     * takes when a rename would break the file's identity or cannot happen.
     *
     * 'c' opens without truncating, the payload is written from offset 0, and
     * only then is the file cut to the payload's length. file_put_contents()
     * truncates FIRST, so a kill or ENOSPC right after the open left a 0-byte
     * file; here a failure part-way leaves the new prefix over the old tail —
     * still damage, which is why this is the fallback and not the default, but
     * never an empty file and never fewer bytes than were already written.
     *
     * @param \Closure(resource, string): void|null $writer See {@see replace()}.
     */
    private static function writeInPlace(string $path, string $contents, ?\Closure $writer): void
    {
        $handle = @fopen($path, 'cb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Failed to open "%s" for write.', $path));
        }

        try {
            if ($writer !== null) {
                $writer($handle, $contents);
            } else {
                $written = fwrite($handle, $contents);
                if ($written === false || $written !== strlen($contents)) {
                    throw new \RuntimeException(sprintf('Short write to "%s".', $path));
                }
            }
            if (!fflush($handle) || !ftruncate($handle, strlen($contents)) || !fsync($handle)) {
                throw new \RuntimeException(sprintf('Failed to finish writing "%s".', $path));
            }
        } catch (\Throwable $e) {
            if ($e instanceof \RuntimeException) {
                throw $e;
            }

            throw new \RuntimeException(sprintf('Failed to write "%s": %s', $path, $e->getMessage()), 0, $e);
        } finally {
            fclose($handle);
        }
    }
}
