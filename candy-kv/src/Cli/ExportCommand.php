<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Cli;

use SugarCraft\Kv\Entry;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\YamlDialect;

/**
 * CLI command handler for `candy-kv export`.
 *
 * i18n scope: every runtime diagnostic here is plain English by design,
 * and there is no catalogued success line — the export payload on stdout
 * IS the success surface. See the {@see ImportCommand} class docblock and
 * CALIBER_LEARNINGS.md for the full split (including the one catalogued
 * success emitter over there).
 */
final class ExportCommand
{
    /**
     * @param Store    $store source store
     * @param resource $stdout sink for the export payload (tests pass a
     *                   memory stream; real runs keep STDOUT)
     * @param resource $stderr sink for every diagnostic (candy-kv-081: the
     *                   bare constants leaked usage/failure lines onto the
     *                   runner's real STDOUT/STDERR — the sinks are now
     *                   injectable)
     */
    public function __construct(
        private readonly Store $store,
        private readonly mixed $stdout = \STDOUT,
        private readonly mixed $stderr = \STDERR,
    ) {
        if (!\is_resource($this->stdout) || !\is_resource($this->stderr)) {
            throw new \InvalidArgumentException('ExportCommand sinks must be writable stream resources.');
        }
    }

    /**
     * Run the export command.
     *
     * @param string $format 'json', 'yaml' or 'yml'
     * @param string|null $dbName Database to export (null = default).
     * @param string|null $pattern Optional glob pattern to filter keys.
     * @return int Exit code (0 = success, 1 = failure, 2 = bad usage).
     */
    public function run(string $format, ?string $dbName = null, ?string $pattern = null): int
    {
        // Usage-class error first: an unknown format is caller intent, not a store failure.
        if (!\in_array($format, ['json', 'yaml', 'yml'], true)) {
            // Sanitize-on-egress: $format is argv-chosen and may carry terminal escapes.
            \fwrite($this->stderr, "Unknown export format: " . Store::sanitizeForTty($format) . ". Use 'json', 'yaml' or 'yml'.\n");
            return 2;
        }
        try {
            $output = $this->exportToString($format, $dbName, $pattern);
            \fwrite($this->stdout, $output);
            return 0;
        } catch (\Throwable $e) {
            // Sanitize-on-egress: the reserved-key refusal message names store
            // keys verbatim (poisoned stores choose them) — sanitize at the sink.
            \fwrite($this->stderr, "Export failed: " . Store::sanitizeForTty($e->getMessage()) . "\n");
            return 1;
        }
    }

    /**
     * Export entries to a string (does not write to STDOUT).
     *
     * @param string $format 'json', 'yaml' or 'yml'
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
            // list()'s default mode yields Entry; the string-row guard keeps
            // the walk honest if a caller ever swaps modes underneath us.
            if (!$entry instanceof Entry) {
                continue;
            }

            $ttl = null;
            if ($entry->expiresAt !== null) {
                $diff = $entry->expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
                if ($diff > 0) {
                    $ttl = $diff;
                }
            }
            // Exporting one named database qualifies its keys, so the dump
            // can be reimported into a store whose default database is
            // different (the @ suffix is how Store routes on write). A
            // stored key carrying a literal @ must ride the document in the
            // escaped spelling the key law defines: exported bare, "p@q"
            // reimports as key "p" in a phantom database "q" with a clean
            // exit. Only the ENTRY part is escaped — the suffix separator
            // stays raw, because the rightmost unescaped @ is the one the
            // importer must split on.
            // Stored keys carrying a backslash cannot survive the qualified
            // spelling: `bs\` + separator reads back as an escaped `\@` and
            // the entry reimports renamed into the default database (kv-007).
            // New stores cannot even hold such keys (Store::splitKey refuses
            // them at every routing door); this is the legacy-store door.
            if ($dbName !== null && (\str_ends_with($entry->key, '\\') || \str_contains($entry->key, '\\@'))) {
                throw new \RuntimeException(
                    "Key \"{$entry->key}\" in database \"{$dbName}\" cannot round-trip through the qualified export spelling (backslash grammar, kv-007)."
                );
            }
            $entryKey = \str_replace('@', '\\@', $entry->key);
            $key = $dbName !== null ? "{$entryKey}@{$dbName}" : $entryKey;

            // Fail loud on envelope collisions: exporting a key that looks
            // like a reserved metadata form for THIS format would silently
            // turn into TTL/binary state on reimport. The check is
            // format-scoped — JSON reserves _ttl/_binary, YAML reserves the
            // skate_* line prefixes — because refusing the other format's
            // namespace blocked entries that round-trip perfectly well.
            $reason = YamlDialect::reservedKeyReason($key, $format);
            if ($reason !== null) {
                throw new \RuntimeException(\ucfirst($reason) . '.');
            }

            // Binary entries export their stored base64 (an ASCII-safe
            // wire form) plus a flag; raw bytes through a text format is
            // exactly what used to break JSON export.
            $entries[$key] = $entry->binary ? $entry->value : $entry->rawValue();
            if ($entry->binary) {
                $binaryKeys[] = $key;
            }
            if ($ttl !== null) {
                $ttlMap[$key] = $ttl;
            }
        }

        $output = match ($format) {
            'json' => $this->exportJson($entries, $ttlMap, $binaryKeys),
            'yaml', 'yml' => $this->exportYaml($entries, $ttlMap, $binaryKeys),
            default => throw new \RuntimeException("Unknown export format: {$format}. Use 'json', 'yaml' or 'yml'."),
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
        if ($entries === []) {
            // An empty store dumps as an empty OBJECT, not `[]` — the
            // envelope is documented as a mapping, and json consumers
            // legitimately assume dict shape (candy-kv's own importers
            // spare an empty list, foreign ones would not).
            return '{}' . \PHP_EOL;
        }

        // Binary values survive export: JSON_INVALID_UTF8_SUBSTITUTE replaces the
        // lone surrogates/invalid sequences a byte-blob carries with U+FFFD instead
        // of failing the whole encode (one binary value used to poison the store dump).
        $flags = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE;
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
        // binary rides skate_binary_<key> (the symfony route used to dump a
        // nested _ttl map the README never described).
        foreach ($ttlMap as $key => $seconds) {
            $entries[YamlDialect::TTL_PREFIX . $key] = $seconds;
        }
        foreach ($binaryKeys as $key) {
            $entries[YamlDialect::BINARY_PREFIX . $key] = 1;
        }

        return YamlDialect::build($entries);
    }
}
