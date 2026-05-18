<?php

declare(strict_types=1);

namespace SugarCraft\Dash\State;

/**
 * Atomic persistence for dashboard panel/tab state.
 *
 * Uses tmp+rename to ensure the saved file is never in a partially-written
 * state even if a crash occurs mid-write (Homestead atomic-save pattern).
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
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create persistence directory: {$dir}");
            }
        }

        $payload = json_encode([
            'version' => 1,
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        $tmp = $dir . '/.tmp_' . basename($path) . '.' . bin2hex(random_bytes(8));

        try {
            if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
                throw new \RuntimeException("Failed to write temp file: {$tmp}");
            }

            if (!rename($tmp, $path)) {
                throw new \RuntimeException("Failed to rename temp file to: {$path}");
            }
        } catch (\Throwable $e) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            throw $e;
        }
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
        if (!file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Failed to read persistence file: {$path}");
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        // Version gate — future schema changes can be migrated here.
        if (!isset($decoded['version']) || !isset($decoded['data'])) {
            throw new \RuntimeException("Invalid persistence format in: {$path}");
        }

        if ($decoded['version'] !== 1) {
            throw new \RuntimeException("Unsupported persistence version: {$decoded['version']}");
        }

        return $decoded['data'];
    }
}
