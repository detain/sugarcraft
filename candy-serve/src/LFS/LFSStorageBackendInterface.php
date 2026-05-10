<?php

declare(strict_types=1);

namespace SugarCraft\Serve\LFS;

/**
 * Interface for LFS storage backends.
 *
 * Implement this interface to provide custom storage for LFS objects.
 * Examples: local filesystem, S3, GCS, Azure Blob Storage, etc.
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
