<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\LFS;

/**
 * Interface for LFS storage backends.
 *
 * Implement this interface to provide custom storage for LFS objects.
 * Examples: local filesystem, S3, GCS, Azure Blob Storage, etc.
 *
 * @note 050 — THE swap point is LFSHandler's `$storageBackend`
 * constructor argument (null builds the default LocalStorageBackend over
 * the configured lfs path; LFSHandler::withStorageBackend() returns a
 * copy with every other setting forwarded). What this interface
 * REQUIRES of any implementation is only what its method signatures
 * promise: the six verbs, a readable stream resource from read(), and
 * RuntimeException for missing objects and failed writes. What
 * LocalStorageBackend additionally PROVIDES — atomic temp+fsync+rename
 * publish, 0600 object modes, 0750 shard dirs, and its own 64-hex oid
 * containment — is default-backend policy, not storage law this layer
 * checks. A custom backend that wants those guarantees implements them
 * itself; and because only the HTTP router gates oid shape before a
 * call reaches a backend, embedders' custom implementations must treat
 * the oid string as untrusted input and contain it in their own layer.
 */
interface LFSStorageBackendInterface
{
    /**
     * Check if an object exists in storage.
     */
    public function exists(string $oid): bool;

    /**
     * Get the size of an object in storage.
     */
    public function size(string $oid): int;

    /**
     * Get a readable stream for an object.
     *
     * @return resource
     * @throws \RuntimeException if object doesn't exist or can't be read
     */
    public function read(string $oid);

    /**
     * Write a file to storage from a stream.
     *
     * @param resource $stream
     * @throws \RuntimeException if write fails
     */
    public function write(string $oid, $stream): void;

    /**
     * Delete an object from storage.
     */
    public function delete(string $oid): void;

    /**
     * Get the path for an object (if applicable).
     * This is used for generating download URLs.
     */
    public function path(string $oid): ?string;
}
