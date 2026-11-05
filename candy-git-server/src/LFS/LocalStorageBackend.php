<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\LFS;

/**
 * Local filesystem storage backend for LFS objects.
 *
 * Stores objects using the standard LFS path layout:
 *   {lfsPath}/{first 2 chars}/{next 2 chars}/{oid}
 *
 * This matches the Git LFS storage convention.
 */
final class LocalStorageBackend implements LFSStorageBackendInterface
{
    public function __construct(
        private readonly string $lfsPath,
    ) {
    }

    /**
     * Get the full path for an LFS object.
     */
    private function objectPath(string $oid): string
    {
        return $this->lfsPath . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;
    }

    public function exists(string $oid): bool
    {
        return \file_exists($this->objectPath($oid));
    }

    public function size(string $oid): int
    {
        $path = $this->objectPath($oid);
        if (!\file_exists($path)) {
            return 0;
        }
        $stat = @\stat($path);
        return $stat !== false ? (int) $stat['size'] : 0;
    }

    /**
     * @return resource
     */
    public function read(string $oid)
    {
        $path = $this->objectPath($oid);
        if (!\file_exists($path)) {
            throw new \RuntimeException("LFS object {$oid} not found");
        }
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open LFS object {$oid}");
        }
        return $handle;
    }

    /**
     * Read an object fully into memory and close the handle.
     *
     * Convenience over {@see read()}, which returns an open handle the
     * caller must close.
     *
     * @throws \RuntimeException if the object is missing or unreadable
     */
    public function readAll(string $oid): string
    {
        $handle = $this->read($oid);
        try {
            $data = \stream_get_contents($handle);
        } finally {
            \fclose($handle);
        }
        if ($data === false) {
            throw new \RuntimeException("Cannot read LFS object {$oid}");
        }
        return $data;
    }

    /**
     * Publish an object atomically: bytes land in a same-directory temp
     * file (chmod first, rename carries the mode onto the target), and the
     * final path appears only when the whole payload is flushed — a reader
     * can never observe a truncated object, and a failed write leaves no
     * residue behind. 'xb' refuses even a pre-planted file at the temp
     * name. Mode 0600: objects are secrets-adjacent payloads, and the
     * serving process is the same uid that wrote them.
     *
     * @param resource $stream
     */
    public function write(string $oid, $stream): void
    {
        $path = $this->objectPath($oid);
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            if (!@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
                throw new \RuntimeException("Cannot create LFS directory: {$dir}");
            }
        }

        $temp = $dir . '/.' . \basename($path) . '.tmp-' . \bin2hex(\random_bytes(8));
        $handle = @\fopen($temp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open LFS object for writing: {$path}");
        }

        try {
            // Settle the mode on the temp inode before a single payload byte
            // lands, so no world-readable window ever opens; rename carries
            // the mode onto the target (and tightens a loose pre-existing one).
            if (@\chmod($temp, 0600) === false) {
                throw new \RuntimeException("Cannot set LFS object mode: {$path}");
            }
            if (@\stream_copy_to_stream($stream, $handle) === false) {
                throw new \RuntimeException("Failed to write LFS object {$oid}");
            }
            \fflush($handle);
            if (@\rename($temp, $path) === false) {
                throw new \RuntimeException("Cannot publish LFS object {$oid}");
            }
        } catch (\Throwable $e) {
            \fclose($handle);
            @\unlink($temp); // no half-written object, no orphaned temp
            throw $e;
        }
        \fclose($handle);
    }

    /**
     * Remove an object. No production path calls this today (upload is
     * content-addressed and objects are immutable); it is the storage-side
     * API for prune/GC tooling, so it stays honest and idempotent.
     */
    public function delete(string $oid): void
    {
        $path = $this->objectPath($oid);
        if (\file_exists($path)) {
            @\unlink($path);
        }
    }

    public function path(string $oid): ?string
    {
        return $this->objectPath($oid);
    }
}
