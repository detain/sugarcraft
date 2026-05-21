<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Import;

use SugarCraft\Skate\Store;

/**
 * Imports key/value pairs into a Store from a YAML file.
 *
 * Expected YAML format:
 * ```yaml
 * key1: value1
 * key2: value2
 * ```
 *
 * Per-database keys use the @ suffix:
 * ```yaml
 * token@passwords: hunter2
 * ```
 *
 * TTL can be set via special keys (skate_ttl_KEY: seconds):
 * ```yaml
 * skate_ttl_token: 3600
 * token: hunter2
 * ```
 */
final class YamlImporter
{
    /** @var array{ttl?: array<string, int>} Import options. */
    private array $options;

    public function __construct(
        private readonly Store $store,
        array $options = [],
    ) {
        $this->options = $options;
    }

    /**
     * Import entries from a YAML file path.
     *
     * @param string $path   Path to the YAML file.
     * @param bool   $atomic Whether to wrap all sets in a single transaction.
     * @return int Number of entries imported.
     */
    public function importFromFile(string $path, bool $atomic = true): int
    {
        $yaml = \file_get_contents($path);
        if ($yaml === false) {
            throw new \RuntimeException("Cannot read YAML file: {$path}");
        }
        return $this->importFromString($yaml, $atomic);
    }

    /**
     * Import entries from a YAML string.
     *
     * @param string $yaml   Raw YAML string.
     * @param bool   $atomic Whether to wrap all sets in a single transaction.
     * @return int Number of entries imported.
     */
    public function importFromString(string $yaml, bool $atomic = true): int
    {
        $data = $this->parseYaml($yaml);

        // Collect TTL map (skate_ttl_KEY: seconds entries)
        $ttlMap = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && \str_starts_with($key, 'skate_ttl_')) {
                $actualKey = \substr($key, 10);
                if (\is_int($value) || \is_numeric($value)) {
                    $ttlMap[$actualKey] = (int) $value;
                }
                unset($data[$key]);
            }
        }

        // Merge with constructor options
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

    /**
     * Minimal YAML parser for simple key: value maps.
     *
     * Handles:
     *   - Simple scalar values
     *   - Quoted strings (single and double quotes)
     *   - Nested objects (maps)
     *   - Lists (arrays) — values extracted
     *
     * Does NOT handle advanced YAML features (anchors, aliases, complex types).
     * Install symfony/yaml for full YAML 1.2 support.
     *
     * @param string $yaml
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        // Use symfony/yaml if available
        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::parse($yaml);
        }

        // Fallback parser for simple YAML
        return $this->minimalYamlParse($yaml);
    }

    /**
     * Minimal YAML parser fallback.
     *
     * @return array<string, mixed>
     */
    private function minimalYamlParse(string $yaml): array
    {
        $result = [];
        $lines = \explode("\n", $yaml);
        $currentKey = null;
        $inBlock = false;
        $blockIndent = 0;

        foreach ($lines as $line) {
            // Skip empty lines and comments
            if ($line === '' || \str_starts_with(\trim($line), '#')) {
                continue;
            }

            $trimmed = \trim($line);

            // Handle document markers
            if ($trimmed === '---' || $trimmed === '...') {
                continue;
            }

            // Determine indentation level
            $indent = \strlen($line) - \strlen(\ltrim($line));

            // Top-level key: value
            if (!$inBlock && \preg_match('/^([a-zA-Z0-9_\-.@]+):\s*(.*)$/', $trimmed, $m)) {
                $key = $m[1];
                $val = $m[2];

                // Remove quotes
                if ((\str_starts_with($val, "'") && \str_ends_with($val, "'")) ||
                    (\str_starts_with($val, '"') && \str_ends_with($val, '"'))) {
                    $val = \substr($val, 1, -1);
                }

                if ($val === '' || $val === '~' || $val === 'null') {
                    $result[$key] = '';
                } else {
                    $result[$key] = $val;
                }
                $currentKey = $key;
                $inBlock = false;
            }
        }

        return $result;
    }
}
