<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Import;

use SugarCraft\Skate\Store;

/**
 * Imports key/value pairs into a Store from a JSON file.
 *
 * Expected JSON format:
 * {
 *   "key1": "value1",
 *   "key2": "value2"
 * }
 * or for per-database entries:
 * {
 *   "key1@db1": "value1",
 *   "key2@db2": "value2"
 * }
 *
 * Supports an optional top-level "_ttl" map:
 * {
 *   "_ttl": { "key1": 3600 },
 *   "key1": "value1"
 * }
 */
final class JsonImporter
{
    /**
     * @param array{ttl?: array<string, int>} $options Import options.
     *                   ttl: Map of key → seconds until expiry.
     */
    public function __construct(
        private readonly Store $store,
        private readonly array $options = [],
    ) {
    }

    /**
     * Import entries from a JSON file path.
     *
     * @param string $path   Path to the JSON file.
     * @param bool   $atomic Whether to wrap all sets in a single transaction.
     * @return int Number of entries imported.
     */
    public function importFromFile(string $path, bool $atomic = true): int
    {
        $json = \file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read JSON file: {$path}");
        }
        return $this->importFromString($json, $atomic);
    }

    /**
     * Import entries from a JSON string.
     *
     * @param string $json   Raw JSON string.
     * @param bool   $atomic Whether to wrap all sets in a single transaction.
     * @return int Number of entries imported.
     */
    public function importFromString(string $json, bool $atomic = true): int
    {
        /** @var array<string, mixed> $data */
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        // Extract TTL map if present
        $ttlMap = [];
        if (isset($data['_ttl']) && \is_array($data['_ttl'])) {
            /** @var array<string, int> $ttlMap */
            $ttlMap = $data['_ttl'];
            unset($data['_ttl']);
        }

        // Merge constructor options with file-level TTL
        $ttlMap = \array_merge($this->options['ttl'] ?? [], $ttlMap);

        $import = function () use ($data, $ttlMap): int {
            $count = 0;
            foreach ($data as $key => $value) {
                if (!\is_string($key) || !\is_string($value)) {
                    continue;
                }
                $ttl = $ttlMap[$key] ?? null;
                $this->store->set($key, $value, false, $ttl);
                $count++;
            }
            return $count;
        };

        if ($atomic) {
            // Collect the databases actually used by the parsed keys so we can
            // route each to its own transaction rather than blindly wrapping
            // everything on the default database (which would NOT cover keys
            // that carry @db suffixes — those land on entirely different dbs).
            $dbNames = [];
            foreach ($data as $key => $value) {
                if (!\is_string($key) || !\is_string($value)) {
                    continue;
                }
                $at = \strrpos($key, '@');
                $dbNames[] = $at === false ? $this->store->defaultDatabase() : \substr($key, $at + 1);
            }

            $uniqueDbs = \array_unique($dbNames);

            // Multi-database atomic import is not supported — cross-db
            // SQLite transactions cannot be atomic across separate .db files.
            if (\count($uniqueDbs) > 1) {
                throw new \RuntimeException(
                    'Atomic import is not supported across multiple databases. ' .
                    'Use atomic=false for multi-database imports, or import each ' .
                    'database separately.'
                );
            }

            // Single-database: run all sets inside a transaction on that db.
            $targetDb = $uniqueDbs[0] ?? $this->store->defaultDatabase();
            $reflection = new \ReflectionClass($this->store);
            $method = $reflection->getMethod('database');
            $method->setAccessible(true);
            /** @var \SugarCraft\Skate\Database $db */
            $db = $method->invoke($this->store, $targetDb);
            return $db->transaction($import);
        }

        return $import();
    }
}
