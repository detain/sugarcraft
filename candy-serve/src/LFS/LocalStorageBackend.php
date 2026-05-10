<?php

declare(strict_types=1);

namespace SugarCraft\Serve\LFS;

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

        $handle = @\fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open LFS object for writing: {$path}");
        }

        // Copy stream to file
        if (\stream_copy_to_stream($stream, $handle) === false) {
            \fclose($handle);
            throw new \RuntimeException("Failed to write LFS object {$oid}");
        }

        \fclose($handle);
    }

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
