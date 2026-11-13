<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Format;

/**
 * Atomic file placement shared by every cassette writer (finding 018).
 *
 * `file_put_contents()` truncates the destination FIRST and then streams
 * bytes into it, so a crash, disk-full, or ctrl-C mid-write leaves a
 * half-written cassette where a good one used to live — and the mode of the
 * new file is set at CREATE time from the ambient umask, meaning a
 * group-readable window while a recording full of typed secrets is being
 * appended byte by byte. The fix is the same shape `TapeToGif` already uses
 * for GIFs (S11 precedent): write the bytes to an exclusively reserved
 * temp file in the DESTINATION directory, restrict it to 0600 before any
 * content lands, then `rename()` it into place. rename() is atomic within
 * one filesystem, replaces the directory ENTRY instead of writing *through*
 * it (a planted symlink at the destination cannot be followed), and a
 * crashed writer leaves only a stray `.candyvcr-*` temp — never a mangled
 * destination.
 *
 * The `.candyvcr-*` naming is deliberate: leftover temps from crashed runs
 * are discoverable by one glob, and the destination's extension is kept on
 * the temp so format inference (gz streams, image writers) behaves the
 * same as if the bytes had gone straight to the destination.
 */
final class AtomicFileWriter
{
    use RestrictsCassettePermissions;

    private const TEMP_PREFIX = '.candyvcr-';

    private function __construct()
    {
    }

    /**
     * Write bytes into place atomically: temp, 0600, content, rename.
     *
     * @param string $path    Destination path.
     * @param string $contents Full bytes to land.
     * @param string $subject Noun woven into error messages ('cassette', 'gzip cassette', ...).
     * @throws \RuntimeException on any failure step; the temp is always removed.
     */
    public static function write(string $path, string $contents, string $subject = 'file'): void
    {
        $tmp = self::reserve($path, $subject);

        try {
            self::restrictToOwner($tmp);
            if (@file_put_contents($tmp, $contents) === false) {
                throw new \RuntimeException("cannot write {$subject} bytes to temporary file for {$path}");
            }
            self::commit($tmp, $path, $subject);
        } catch (\Throwable $e) {
            self::discard($tmp);
            throw $e;
        }
    }

    /**
     * Write gzip bytes into place atomically (finding 018: the `.cas.gz`
     * path previously `gzopen()`ed the destination directly, so a stream
     * that died mid-deflate left a corrupt archive where the last good
     * recording lived).
     *
     * The old streaming writer split `$contents` on newlines and skipped
     * empty lines — inert for well-formed JSONL but a latent silent byte
     * edit if any payload ever contained a blank line. The whole buffer
     * now goes through `gzwrite()` in one call: the bytes are already fully
     * materialised by `encode()`, so the split preserved nothing anyway.
     *
     * @param string $path    Destination path (must end `.gz` in practice).
     * @param string $contents Uncompressed bytes.
     * @param int $compressionLevel 1..9, woven into the zlib mode string.
     * @throws \RuntimeException on any failure step; the temp is always removed.
     */
    public static function writeGzipped(string $path, string $contents, int $compressionLevel, string $subject = 'gzip cassette'): void
    {
        $tmp = self::reserve($path, $subject);

        try {
            self::restrictToOwner($tmp);
            $gz = @gzopen($tmp, 'wb' . $compressionLevel);
            if ($gz === false) {
                throw new \RuntimeException("cannot open gzip stream for temporary file of {$path}");
            }
            try {
                if (@gzwrite($gz, $contents) === false) {
                    throw new \RuntimeException("cannot write {$subject} bytes to temporary file for {$path}");
                }
            } finally {
                gzclose($gz);
            }
            self::commit($tmp, $path, $subject);
        } catch (\Throwable $e) {
            self::discard($tmp);
            throw $e;
        }
    }

    /**
     * Reserve an exclusive temp path in the destination directory and
     * return it — for callers whose bytes are produced by a third party
     * (the GIF encoders) rather than a string in hand. The name keeps the
     * destination's extension because encoders infer their muxer from it.
     *
     * `x` mode is O_CREAT|O_EXCL|O_WRONLY: it fails if the name already
     * exists. Because PHP's `x` probe follows DANGLING symlinks (measured
     * during VCR-S18: a planted link into a non-existent target makes
     * fopen('x') create and write the TARGET), the exclusive create is
     * preceded by an explicit is_link() refusal — same door order as the
     * migrate backup.
     *
     * @param string $path    Destination path.
     * @param string $subject Noun woven into error messages ('GIF', ...).
     * @param ?string $forceSuffix Extension to force on the temp (without
     *                             dot), overriding the destination's own —
     *                             GIF encoders need a `.gif` name even when
     *                             the final output path has no extension.
     * @throws \RuntimeException if the directory cannot host a fresh file.
     */
    public static function reserve(string $path, string $subject = 'file', ?string $forceSuffix = null): string
    {
        $dir = dirname($path);
        $ext = $forceSuffix ?? strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $suffix = $ext === '' ? '' : '.' . $ext;
        $tmp = $dir . DIRECTORY_SEPARATOR . self::TEMP_PREFIX . bin2hex(random_bytes(8)) . $suffix;

        if (@is_link($tmp)) {
            throw new \RuntimeException("temporary name for {$subject} write is already a symlink: {$tmp}");
        }
        $handle = @fopen($tmp, 'x');
        if ($handle === false) {
            throw new \RuntimeException("Cannot create a temporary file for atomic {$subject} write in: {$dir}");
        }
        fclose($handle);

        return $tmp;
    }

    /**
     * Replace the destination ENTRY with the finished temp file, atomically
     * within one filesystem.
     *
     * @throws \RuntimeException if rename fails (destination then untouched).
     */
    public static function commit(string $tmp, string $dest, string $subject = 'file'): void
    {
        if (!@rename($tmp, $dest)) {
            throw new \RuntimeException("Failed to move {$subject} into place: {$dest}");
        }
    }

    /** Remove a reserved temp after a failed attempt; a vanished temp is benign. */
    public static function discard(string $tmp): void
    {
        @unlink($tmp);
    }
}
