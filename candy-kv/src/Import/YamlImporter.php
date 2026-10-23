<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;

/**
 * Imports key/value pairs into a Store from a YAML file.
 *
 * Expected YAML format (the flat dialect emitted by candy-kv itself —
 * see {@see \SugarCraft\Kv\YamlDialect}):
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
 * TTL and binary flags ride reserved metadata lines:
 * ```yaml
 * skate_ttl_token: 3600
 * skate_binary_document: 1
 * token: hunter2
 * document: U0dWIGJ5dGVz
 * ```
 *
 * A nested `_ttl` mapping (produced by pre-D4 exports through symfony/yaml)
 * is still accepted and merged.
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

        // Collect TTL map (skate_ttl_KEY: seconds entries), the binary flag
        // map, and the legacy nested _ttl/_binary envelope forms.
        $ttlMap = [];
        $binaryMap = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\str_starts_with($key, \SugarCraft\Kv\YamlDialect::TTL_PREFIX)) {
                $actualKey = \substr($key, \strlen(\SugarCraft\Kv\YamlDialect::TTL_PREFIX));
                if (\is_int($value) || \is_numeric($value)) {
                    $ttlMap[$actualKey] = (int) $value;
                }
                unset($data[$key]);
            } elseif (\str_starts_with($key, \SugarCraft\Kv\YamlDialect::BINARY_PREFIX)) {
                $actualKey = \substr($key, \strlen(\SugarCraft\Kv\YamlDialect::BINARY_PREFIX));
                $binaryMap[$actualKey] = (bool) ((int) $value);
                unset($data[$key]);
            } elseif ($key === \SugarCraft\Kv\YamlDialect::JSON_TTL_KEY && \is_array($value)) {
                // Pre-D4 symfony-route export: nested `_ttl: {key: secs}`.
                foreach ($value as $k => $secs) {
                    if (\is_string($k) && (\is_int($secs) || \is_numeric($secs))) {
                        $ttlMap[$k] = (int) $secs;
                    }
                }
                unset($data[$key]);
            } elseif ($key === \SugarCraft\Kv\YamlDialect::JSON_BINARY_KEY && \is_array($value)) {
                foreach ($value as $k) {
                    if (\is_string($k)) {
                        $binaryMap[$k] = true;
                    }
                }
                unset($data[$key]);
            }
        }

        // Merge with constructor options
        $ttlMap = \array_merge($this->options['ttl'] ?? [], $ttlMap);

        $import = function () use ($data, $ttlMap, $binaryMap): int {
            $count = 0;
            foreach ($data as $key => $value) {
                if ($value === null) {
                    $value = '';
                } elseif (\is_int($value) || \is_float($value)) {
                    $value = (string) $value;
                }
                if (!\is_string($key) || !\is_string($value)) {
                    continue;
                }
                $ttl = $ttlMap[$key] ?? null;
                if (($binaryMap[$key] ?? false) === true) {
                    $value = \SugarCraft\Kv\YamlDialect::decodeBinary($value, $key);
                }
                $this->store->set($key, $value, ($binaryMap[$key] ?? false) === true, $ttl);
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

            // Single-database: run all sets inside a transaction on that db,
            // via the public Store API (it routes to the same cached Database
            // the sets below use, so they all join the transaction).
            $targetDb = $uniqueDbs[0] ?? $this->store->defaultDatabase();
            return $this->store->transaction($targetDb, $import);
        }

        return $import();
    }

    /**
     * YAML parser entry point.
     *
     * Both routes implement the same flat dialect (see
     * {@see \SugarCraft\Kv\YamlDialect}): symfony/yaml when the component is
     * present (declared as a `suggest`; candy-kv's own dev install ships it),
     * otherwise the dialect's strict fallback parser — which since D4
     * understands quoted keys, double-quote escapes (backslash, \n, control
     * bytes) and plain scalars, and throws RuntimeException ('Syntax error')
     * on anything it cannot represent faithfully.
     *
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        $data = \SugarCraft\Kv\YamlDialect::parse($yaml);
        if ($data === []) {
            return [];
        }
        $flat = [];
        foreach ($data as $key => $value) {
            $flat[(string) $key] = $value;
        }

        return $flat;
    }
}
