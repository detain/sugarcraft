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
     * Storage-side self-validation (097): the route gate and the batch
     * validator already refuse off-shape oids, but this class derives
     * filesystem paths straight from the caller's string — containment
     * must not rest entirely on upstream gates for embedders. The shape
     * is the protocol's own (SHA-256 lowercase hex — twin of
     * LFSHandler::isValidOid; the import would cycle handler→backend→
     * handler, so the pattern is stated once here and pinned in tests).
     */
    private const OID_PATTERN = '/^[0-9a-f]{64}$/';

    public static function isValidOid(string $oid): bool
    {
        return \preg_match(self::OID_PATTERN, $oid) === 1;
    }

    /**
     * Get the full path for an LFS object. THE single choke point every
     * oid-addressed method derives through, so one guard covers the lot
     * (097): slashes, dots, and any other traversal bait die here with a
     * throw rather than escaping the bucket.
     *
     * @throws \InvalidArgumentException on an off-shape oid
     */
    private function objectPath(string $oid): string
    {
        if (!self::isValidOid($oid)) {
            throw new \InvalidArgumentException("Invalid LFS object id: {$oid}");
        }

        return $this->lfsPath . '/' . \substr($oid, 0, 2) . '/' . \substr($oid, 2, 2) . '/' . $oid;
    }

    public function exists(string $oid): bool
    {
        // 101: one stat is the whole question — no file_exists warm-up.
        return @\stat($this->objectPath($oid)) !== false;
    }

    public function size(string $oid): int
    {
        // 101: the old shape paid file_exists + stat; the @stat answer IS
        // the existence answer, so trust its ordering and probe once.
        $stat = @\stat($this->objectPath($oid));

        return $stat !== false ? (int) $stat['size'] : 0;
    }

    /**
     * @return resource
     */
    public function read(string $oid)
    {
        // 101: fopen is the optimistic oracle — the success path never
        // probes first; only a failure pays a second, diagnostic stat to
        // tell "missing" (the not-found contract callers catch) from
        // "there but unopenable" (a server-side fault callers surface).
        $path = $this->objectPath($oid);
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            if (@\stat($path) !== false) {
                throw new \RuntimeException("Cannot open LFS object {$oid}");
            }

            throw new \RuntimeException("LFS object {$oid} not found");
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
        $this->publish($oid, static function ($handle) use ($oid, $stream): void {
            try {
                if (@\stream_copy_to_stream($stream, $handle) === false) {
                    throw new \RuntimeException("Failed to write LFS object {$oid}");
                }
            } catch (\Throwable $e) {
                // The interface contract says RuntimeException on write
                // failure — a stream that dies mid-copy (or was never a
                // readable resource) must not surface as a raw TypeError.
                throw new \RuntimeException("Failed to write LFS object {$oid}", 0, $e);
            }
        });
    }

    /**
     * Publish straight from an in-memory payload (049). The upload
     * router already holds the complete, hash-VERIFIED bytes; before
     * this seam it round-tripped them through php://temp merely to
     * satisfy the stream API, materializing the object one extra time
     * per upload for a default backend that only ever wants the bytes.
     * Same atomic 0600 publish as write(), sliced at the lib's 64 KiB
     * law so no oversized temporary ever rides the copy.
     */
    public function writeBytes(string $oid, string $bytes): void
    {
        $this->publish($oid, static function ($handle) use ($oid, $bytes): void {
            $offset = 0;
            $length = \strlen($bytes);
            while ($offset < $length) {
                $written = @\fwrite($handle, \substr($bytes, $offset, \SugarCraft\GitServer\Support\HttpWire::CHUNK_BYTES));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Failed to write LFS object {$oid}");
                }
                $offset += $written;
            }
        });
    }

    /**
     * The shared atomic publish: shard dir 0750, private temp inode
     * before a payload byte lands, fsync, rename onto the target, and
     * removal of both handle and temp on any failure — no half-written
     * object, no orphaned temp, and (041) no name that outruns its
     * bytes. One shape for both write paths (049).
     *
     * @param callable(resource): void $sink
     */
    private function publish(string $oid, callable $sink): void
    {
        $path = $this->objectPath($oid);
        $dir = \dirname($path);

        if (!\is_dir($dir)) {
            // 038: shard directories land 0750 with an explicit chmod,
            // not a umask-roll — LFS objects are repo content and the
            // world must not be able to `cp` them off a shared box.
            try {
                \SugarCraft\GitServer\Support\AtomicWriter::ensureDir($dir, 0750);
            } catch (\RuntimeException) {
                if (!\is_dir($dir)) {
                    throw new \RuntimeException("Cannot create LFS directory: {$dir}");
                }
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
            $sink($handle);
            \fflush($handle);
            // 041: the payload must be on stable storage BEFORE the name
            // is. rename only guarantees the directory entry — after a
            // power loss between rename and the deferred data flush the
            // object would exist with content that never hit the media,
            // and a content-addressed store serves that poisoned oid
            // forever. Same fsync-before-rename discipline as
            // AtomicWriter::write and the UserStore exchange. A failed
            // fsync lands in the catch below: temp swept, nothing
            // published, the client simply retries the upload.
            if (!@\fsync($handle)) {
                throw new \RuntimeException("Cannot fsync LFS object {$oid}");
            }
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
        // 101: unlink on a missing path is a silent false — the
        // file_exists pre-probe bought nothing but a second stat.
        @\unlink($this->objectPath($oid));
    }

    public function path(string $oid): ?string
    {
        return $this->objectPath($oid);
    }
}
