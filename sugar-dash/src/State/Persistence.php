<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

use SugarCraft\Core\Util\AtomicJsonFile;

/**
 * Atomic persistence for dashboard panel/tab state.
 *
 * Delegates the durable-state save/load to candy-core's AtomicJsonFile
 * (tmp+flock+rename SSOT) so a reader never observes a partially-written file,
 * while keeping this class's `{version, data}` wrapper as the on-disk shape.
 */
final class Persistence
{
    /**
     * Save state atomically: write to temp file then rename to target.
     *
     * @param string $path Absolute path to the persistence file.
     * @param array<string, mixed> $data Data to persist.
     * @throws \RuntimeException If the write or rename fails.
     */
    public function save(string $path, array $data): void
    {
        AtomicJsonFile::new($path)->write([
            'version' => 1,
            'data' => $data,
        ]);
    }

    /**
     * Load persisted state from disk.
     *
     * @param string $path Absolute path to the persistence file.
     * @return array<string, mixed>|null The data array, or null if the file does not exist.
     * @throws \RuntimeException If the file exists but is not valid JSON.
     */
    public function load(string $path): ?array
    {
        $file = AtomicJsonFile::new($path);
        if (!$file->exists()) {
            return null;
        }

        $decoded = $file->read();

        // Version gate — future schema changes can be migrated here.
        if (!isset($decoded['version']) || !isset($decoded['data'])) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }

        if ($decoded['version'] !== 1) {
            throw new \RuntimeException("Unsupported persistence version: {$decoded['version']}");
        }

        /** @var array<string, mixed> */
        return $decoded['data'];
    }
}
