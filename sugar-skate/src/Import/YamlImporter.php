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
     * Two paths, and only one of them is live in a stock install:
     *
     *  - symfony/yaml, IF the application happens to have it installed. It is
     *    deliberately NOT a declared dependency of sugar-skate, so in this
     *    repo the `class_exists` branch below never fires and the fallback
     *    parser is the ONLY live path.
     *  - {@see minimalYamlParse()} — a strict flat `key: value` reader. Its
     *    real capability (see its docblock for the full contract): scalar
     *    values, quote-stripped values, `~`/`null` folded to empty. It does
     *    NOT handle nested maps (indentation is silently flattened into
     *    top-level keys), lists, quoted keys, anchors or aliases — anything
     *    that is not a flat `key: value` line throws RuntimeException
     *    ('Syntax error'). Applications needing full YAML 1.2 must require
     *    symfony/yaml themselves.
     *
     * @return array<string, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        // Opportunistic upgrade: only reached when the surrounding project
        // ships symfony/yaml (not a sugar-skate dependency — dead branch in
        // this repo's install graph, kept for host apps that do have it).
        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::parse($yaml);
        }

        // Fallback parser for simple YAML
        return $this->minimalYamlParse($yaml);
    }

    /**
     * Fallback parser: flat `key: value` lines only.
     *
     * Accepted per line (indentation-insensitive — leading whitespace is
     * trimmed, so a nested `child:` under an empty `parent:` is silently
     * flattened to another top-level key, with the parent stored as ''):
     *   key: scalar-value        quotes around the value are stripped
     *   key: / key:~/key:null    stored as empty string
     * Keys must match [a-zA-Z0-9_\-.@]+ (no quotes, no spaces, no symbols).
     * Any other non-empty, non-comment line (list item `- x`, bare scalar,
     * quoted key, block scalar continuation) throws RuntimeException.
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
            } else {
                // Line has meaningful content but doesn't match key: value pattern
                // This indicates malformed YAML (e.g., [valid yaml with unbalanced bracket)
                throw new \RuntimeException('Syntax error');
            }
        }

        return $result;
    }
}
