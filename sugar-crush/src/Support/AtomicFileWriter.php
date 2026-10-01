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

        // Unique per call so two concurrent publishes never share a temp;
        // same-directory placement keeps rename() atomic on the same fs.
        $tmp = $dir . '/.' . basename($path) . '.tmp.' . bin2hex(random_bytes(8));

        try {
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
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

                $written = fwrite($handle, $contents);
                if ($written === false || $written !== strlen($contents)) {
                    throw new \RuntimeException(sprintf('Short write to temporary file "%s" of "%s".', $tmp, $path));
                }
                if (!fflush($handle)) {
                    throw new \RuntimeException(sprintf('Failed to flush temporary file "%s" of "%s".', $tmp, $path));
                }
            } finally {
                fclose($handle);
            }

            if (!@rename($tmp, $path)) {
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
    }
}
