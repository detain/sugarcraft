<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Atomic temp+fsync+rename publisher for raw bytes (markdown notes, generated
 * indexes, tokens) — {@see AtomicJsonFile}'s publish law with the JSON codec
 * stripped off. Extracted from candy-code's `Support/AtomicFileWriter::write()`.
 *
 * The ordering law, pinned structurally in AtomicTextFileTest:
 *  1. the payload goes into a uniquely-named temp in the TARGET's directory
 *     (rename is only atomic within one filesystem);
 *  2. the requested mode lands on the temp inode BEFORE a payload byte is
 *     written, so the published path never exists under looser bits;
 *  3. the temp is flushed and fsynced BEFORE the rename — otherwise the name
 *     can land ahead of its bytes and a crash leaves a zero-length file;
 *  4. one rename() publishes it, then the directory entry is fsynced.
 * fsync is fail-soft (refused on some network/FUSE mounts); everything else
 * throws, and the temp is unlinked on every failure this process survives.
 *
 * Stranded-partial recovery: the temp name is unique per call, so a killed
 * writer can never wedge later saves (there is no fixed `.partial` to refuse
 * over). What a SIGKILL strands is an orphan temp, reclaimed by the next
 * successful publish of the same target once it is older than
 * {@see ORPHAN_TEMP_MIN_AGE_SECONDS} (a live writer holds its temp for
 * seconds, so the floor cannot catch one in flight).
 *
 * Callers that compare-and-rewrite still need their own writer-side lock
 * ({@see LockFile}); a unique temp makes a single publish race-free without
 * serialising unrelated writers. Append-only files are not this class's job.
 */
final class AtomicTextFile
{
    /** Age an orphaned temp must reach before a later publish removes it. */
    public const ORPHAN_TEMP_MIN_AGE_SECONDS = 3600;

    /** Ceiling of plain permission bits; setuid/setgid/sticky are out of scope. */
    private const MODE_CEILING = 0777;

    /** S_IFMT / S_IFREG: only a regular file by lstat() is ever swept. */
    private const STAT_TYPE_MASK = 0o170000;
    private const STAT_REGULAR_FILE = 0o100000;

    private function __construct(
        private readonly string $path,
        private readonly ?int $fileMode = null,
    ) {
    }

    public static function at(string $path): self
    {
        return new self($path);
    }

    /**
     * Derive a store publishing with $mode bits (applied to the temp inode
     * before the payload). Unset means the umask decides.
     *
     * @throws \InvalidArgumentException When $mode is not plain permission bits.
     */
    public function withPermissions(int $mode): self
    {
        if ($mode < 0 || $mode > self::MODE_CEILING) {
            throw new \InvalidArgumentException(
                'AtomicTextFile mode must be plain permission bits (0000..0777), got '
                . \sprintf('%o', $mode) . ' for ' . $this->path,
            );
        }

        return new self($this->path, $mode);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function permissions(): ?int
    {
        return $this->fileMode;
    }

    /**
     * Atomically replace the target with $bytes (parent created 0700 when missing).
     *
     * @throws \RuntimeException On any filesystem failure; the message names the
     *                           target path and the original bytes stay intact.
     */
    public function write(string $bytes): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory "%s" for atomic write.', $dir));
        }

        $tmp = $dir . \DIRECTORY_SEPARATOR . '.' . basename($this->path) . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf(
                'Failed to open temporary file "%s" for write of "%s".',
                $tmp,
                $this->path,
            ));
        }

        try {
            if ($this->fileMode !== null && !@chmod($tmp, $this->fileMode)) {
                throw new \RuntimeException(\sprintf(
                    'Failed to set permissions 0%o on "%s" (target "%s").',
                    $this->fileMode,
                    $tmp,
                    $this->path,
                ));
            }

            $written = @fwrite($handle, $bytes);
            if ($written === false || $written !== \strlen($bytes)) {
                throw new \RuntimeException(\sprintf('Short write to temporary file "%s" of "%s".', $tmp, $this->path));
            }
            if (!fflush($handle)) {
                throw new \RuntimeException(\sprintf('Failed to flush temporary file "%s" of "%s".', $tmp, $this->path));
            }
            if (\function_exists('fsync')) {
                @fsync($handle);
            }
            fclose($handle);

            if (!@rename($tmp, $this->path)) {
                throw new \RuntimeException(\sprintf('Failed to atomically write "%s".', $this->path));
            }
        } catch (\Throwable $e) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
            throw $e instanceof \RuntimeException
                ? $e
                : new \RuntimeException(\sprintf('Failed to atomically write "%s": %s', $this->path, $e->getMessage()), 0, $e);
        }

        $this->syncDirectory($dir);
        $this->sweepOrphanTemps();
    }

    private function syncDirectory(string $dir): void
    {
        $handle = @fopen($dir, 'rb');
        if ($handle === false) {
            return;
        }
        if (\function_exists('fsync')) {
            @fsync($handle);
        }
        fclose($handle);
    }

    /**
     * Remove `.<name>.tmp.<16 hex>` files a KILLED publish of this target left
     * behind: exact prefix + 16 lowercase hex (a user's `.notes.tmp.bak` never
     * matches), regular files only, own uid only, older than the age floor.
     * Best effort and silent.
     */
    private function sweepOrphanTemps(): void
    {
        $pattern = self::globLiteral(\dirname($this->path))
            . '/' . self::globLiteral('.' . basename($this->path) . '.tmp.')
            . str_repeat('[0-9a-f]', 16);

        $found = @glob($pattern, GLOB_NOSORT);
        if ($found === false || $found === []) {
            return;
        }

        $uid = \function_exists('posix_geteuid') ? posix_geteuid() : null;
        $now = time();

        foreach ($found as $orphan) {
            $stat = @lstat($orphan);
            if ($stat === false || ($stat['mode'] & self::STAT_TYPE_MASK) !== self::STAT_REGULAR_FILE) {
                continue;
            }
            if ($uid !== null && $stat['uid'] !== $uid) {
                continue;
            }
            if ($now - (int) $stat['mtime'] < self::ORPHAN_TEMP_MIN_AGE_SECONDS) {
                continue;
            }
            @unlink($orphan);
        }
    }

    private static function globLiteral(string $text): string
    {
        return addcslashes($text, '\\*?[');
    }
}
