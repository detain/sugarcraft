<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

use SugarCraft\Kv\Store;

/**
 * CLI command handler for `candy-kv export`.
 *
 * i18n scope: runtime diagnostics here are plain-English by design — see
 * the {@see ImportCommand} class docblock and CALIBER_LEARNINGS.md.
 */
final class ExportCommand
{
    public function __construct(
        private readonly Store $store,
    ) {
    }

    /**
     * Run the export command.
     *
     * @param string $format 'json' or 'yaml'
     * @param string|null $dbName Database to export (null = default).
     * @param string|null $pattern Optional glob pattern to filter keys.
     * @return int Exit code (0 = success, 1 = failure).
     */
    public function run(string $format, ?string $dbName = null, ?string $pattern = null): int
    {
        try {
            $output = $this->exportToString($format, $dbName, $pattern);
            \fwrite(STDOUT, $output);
            return 0;
        } catch (\Throwable $e) {
            \fwrite(STDERR, "Export failed: {$e->getMessage()}\n");
            return 1;
        }
    }

    /**
     * Export entries to a string (does not write to STDOUT).
     *
     * @param string $format 'json' or 'yaml'
     * @param string|null $dbName Database to export (null = default).
     * @param string|null $pattern Optional glob pattern to filter keys.
     * @return string The formatted export output.
     * @throws \RuntimeException If the format is unknown.
     */
    public function exportToString(string $format, ?string $dbName = null, ?string $pattern = null): string
    {
        $entries = [];
        $binaryKeys = [];
        $ttlMap = [];

        foreach ($this->store->list($pattern, $dbName) as $entry) {
            if ($entry instanceof \SugarCraft\Kv\Entry) {
                $ttl = null;
                if ($entry->expiresAt !== null) {
                    $diff = $entry->expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
                    if ($diff > 0) {
                        $ttl = $diff;
                    }
                }
                $key = $entry->key;
                if ($dbName !== null) {
                    $key = "{$entry->key}@{$dbName}";
                } else {
                    $key = $entry->key;
                }

                // Fail loud on envelope collisions: exporting a key that
                // looks like a reserved metadata form would silently turn
                // into TTL/binary state on reimport.
                $reason = \SugarCraft\Kv\YamlDialect::reservedKeyReason($key);
                if ($reason !== null) {
                    throw new \RuntimeException(\ucfirst($reason) . '.');
                }

                // Binary entries export their stored base64 (an ASCII-safe
                // wire form) plus a flag; raw bytes through a text format is
                // exactly what broke JSON export (D4 M5).
                $entries[$key] = $entry->binary ? $entry->value : $entry->rawValue();
                if ($entry->binary) {
                    $binaryKeys[] = $key;
                }
                if ($ttl !== null) {
                    $ttlMap[$key] = $ttl;
                }
            }
        }

        $output = match ($format) {
            'json' => $this->exportJson($entries, $ttlMap, $binaryKeys),
            'yaml', 'yml' => $this->exportYaml($entries, $ttlMap, $binaryKeys),
            default => throw new \RuntimeException("Unknown export format: {$format}. Use 'json' or 'yaml'."),
        };

        return $output;
    }

    /**
     * @param array<string, string> $entries
     * @param array<string, int>    $ttlMap
     * @param list<string>          $binaryKeys
     */
    private function exportJson(array $entries, array $ttlMap, array $binaryKeys): string
    {
        if ($ttlMap !== []) {
            $entries['_ttl'] = $ttlMap;
        }
        if ($binaryKeys !== []) {
            $entries['_binary'] = $binaryKeys;
        }

        $flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        $json = \json_encode($entries, $flags, 512);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . \json_last_error_msg());
        }
        return $json . \PHP_EOL;
    }

    /**
     * @param array<string, string> $entries
     * @param array<string, int>    $ttlMap
     * @param list<string>          $binaryKeys
     */
    private function exportYaml(array $entries, array $ttlMap, array $binaryKeys): string
    {
        // One flat dialect on both engines: TTL rides skate_ttl_<key>,
        // binary rides skate_binary_<key> (D4 M4: the symfony route used to
        // dump a nested _ttl map the README never described).
        foreach ($ttlMap as $key => $seconds) {
            $entries[\SugarCraft\Kv\YamlDialect::TTL_PREFIX . $key] = $seconds;
        }
        foreach ($binaryKeys as $key) {
            $entries[\SugarCraft\Kv\YamlDialect::BINARY_PREFIX . $key] = 1;
        }

        return \SugarCraft\Kv\YamlDialect::build($entries);
    }
}
