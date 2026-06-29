<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Cli;

use SugarCraft\Skate\Store;

/**
 * CLI command handler for `skate export`.
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
        foreach ($this->store->list($pattern, $dbName) as $entry) {
            if ($entry instanceof \SugarCraft\Skate\Entry) {
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
                $entries[$key] = $entry->rawValue();
                if ($ttl !== null) {
                    $entries['_ttl'] = $entries['_ttl'] ?? [];
                    $entries['_ttl'][$key] = $ttl;
                }
            }
        }

        $output = match ($format) {
            'json' => $this->exportJson($entries),
            'yaml', 'yml' => $this->exportYaml($entries),
            default => throw new \RuntimeException("Unknown export format: {$format}. Use 'json' or 'yaml'."),
        };

        return $output;
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function exportJson(array $entries): string
    {
        $flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        $json = \json_encode($entries, $flags, 512);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . \json_last_error_msg());
        }
        return $json . \PHP_EOL;
    }

    /**
     * @param array<string, mixed> $entries
     */
    private function exportYaml(array $entries): string
    {
        // Try symfony/yaml first
        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::dump($entries, 4, 0, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        }

        // Fallback to minimal YAML output
        $lines = [];
        foreach ($entries as $key => $value) {
            if (\is_array($value)) {
                // _ttl map — skip for fallback
                continue;
            }
            // Quote keys/values that need it
            $key = $this->yamlString($key);
            $val = $this->yamlString((string) $value);
            $lines[] = "{$key}: {$val}";
        }

        // Append _ttl if present
        if (isset($entries['_ttl']) && \is_array($entries['_ttl'])) {
            foreach ($entries['_ttl'] as $k => $v) {
                $lines[] = "skate_ttl_{$k}: {$v}";
            }
        }

        return \implode("\n", $lines) . \PHP_EOL;
    }

    private function yamlString(string $value): string
    {
        // Values that need quoting
        if ($value === '' || $value === '~' || $value === 'null' ||
            \preg_match('/^[0-9]+$/', $value) ||
            \str_contains($value, ':') ||
            \str_contains($value, '#') ||
            \str_starts_with($value, "'") ||
            \str_starts_with($value, '"') ||
            \str_starts_with($value, '[') ||
            \str_starts_with($value, '{') ||
            \str_starts_with($value, '&') ||
            \str_starts_with($value, '*') ||
            \str_starts_with($value, '!') ||
            \str_starts_with($value, ' ') ||
            \str_ends_with($value, ' ') ||
            \str_starts_with($value, '}') ||
            \str_starts_with($value, ']') ||
            \str_starts_with($value, ',') ||
            \str_starts_with($value, '`') ||
            \str_starts_with($value, '%') ||
            \str_starts_with($value, '?') ||
            \preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)
        ) {
            return '"' . \addcslashes($value, "\\\"\n") . '"';
        }
        return $value;
    }
}
