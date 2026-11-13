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
    /** Gate G10 ship shape (candy-kv-073): the JSON pair writer's flags. */
    private const JSON_FLAGS = \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

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
            // kv-073: the payload streams — one fwrite per entry pair
            // instead of building the whole document before the first
            // byte. A 10k-row export used to peak at roughly twice its
            // payload in the entries map plus the encoded string (the
            // symfony YAML route measured ~8x); now peak stays flat in
            // the row count.
            $this->streamExport($format, $dbName, $pattern, $this->stdout);
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
        // kv-073: one code path — the string door captures the same
        // streamer the CLI writes through — AND streams into a temp file
        // rather than a memory buffer, so even this door's return is one
        // buffered copy of the payload instead of the pairs map, the
        // encoded string, and the return (the symfony YAML route measured
        // ~8x amplification on the batch shape). Refusals still answer as
        // exceptions with nothing emitted: the key-law pre-pass runs before
        // the first pair writes.
        $buffer = \fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new \RuntimeException('Cannot open the export buffer.');
        }
        try {
            if ($format !== 'json' && $format !== 'yaml' && $format !== 'yml') {
                throw new \RuntimeException("Unknown export format: {$format}. Use 'json', 'yaml' or 'yml'.");
            }
            $this->streamExport($format, $dbName, $pattern, $buffer);
            $size = \fstat($buffer)['size'] ?? 0;
            \rewind($buffer);
            $payload = \fread($buffer, $size);
        } finally {
            \fclose($buffer);
        }

        return $payload === false ? '' : $payload;
    }

    /**
     * @param resource $sink
     */
    private function streamExport(string $format, ?string $dbName, ?string $pattern, $sink): void
    {
        // Key-law pre-pass (kv-007 + envelope collisions) BEFORE any byte
        // writes: a store that cannot round-trip must not receive a
        // truncated document on stdout, and streaming makes "abort after
        // emitting rows 1..N-1" otherwise the new failure shape. Keys are
        // cheap to walk twice and both refusals are pure key arithmetic.
        foreach ($this->store->list($pattern, $dbName, false, 'keys') as $storedKey) {
            $this->assertKeyRoundTrips((string) $storedKey, $format, $dbName);
        }

        $binaryKeys = [];
        $ttlMap = [];
        $rows = $this->walkRows($dbName, $pattern, $binaryKeys, $ttlMap);

        if ($format === 'json') {
            $this->writeJsonPairs($sink, $rows, $binaryKeys, $ttlMap);

            return;
        }
        $this->writeYamlPairs($sink, $rows, $binaryKeys, $ttlMap);
    }

    /**
     * The per-entry serialization walk: value selection, the kv-009
     * promotion, the kv-026 liveness skip, and the metadata collection the
     * envelope writers need. Rows YIELD as [qualifiedKey, valueText] pairs
     * so nothing accumulates; the by-ref metadata arrays are the only
     * retained state (names and ints — bytes-scale, not payload-scale).
     *
     * @param list<string>          $binaryKeys
     * @param array<string, string> $entries
     */
    private function walkRows(?string $dbName, ?string $pattern, array &$binaryKeys, array &$ttlMap): \Generator
    {
        foreach ($this->store->list($pattern, $dbName) as $entry) {
            // list()'s default mode yields Entry; the string-row guard keeps
            // the walk honest if a caller ever swaps modes underneath us.
            if (!$entry instanceof Entry) {
                continue;
            }

            $ttl = null;
            if ($entry->expiresAt !== null) {
                $diff = $entry->expiresAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
                if ($diff <= 0) {
                    // kv-026: the row was live when the SQL gate listed it but
                    // its life hit zero during the walk. Dumping it now would
                    // shed the TTL envelope and reimport as a forever entry —
                    // silent laundering in the security-relevant direction.
                    // One liveness law on both doors: skip it loudly instead.
                    $shown = $dbName === null ? $entry->key : $entry->key . '@' . $dbName;
                    \fwrite($this->stderr, 'export: skipped "' . Store::sanitizeForTty($shown) . '" — its TTL expires during this export run.' . \PHP_EOL);
                    continue;
                }
                $ttl = $diff;
            }

            $key = $this->qualifyKey($entry->key, $dbName);

            // Binary entries export their stored base64 (an ASCII-safe
            // wire form) plus a flag; raw bytes through a text format is
            // exactly what used to break JSON export.
            $value = $entry->binary ? $entry->value : $entry->rawValue();
            if (!$entry->binary && !\mb_check_encoding($value, 'UTF-8')) {
                // kv-009: a --text-forced row may hold bytes no text format
                // carries losslessly — json_encode substituted them with
                // U+FFFD, so the export quietly MUTATED stored bytes and the
                // "value-preserving" README law was false on the JSON leg.
                // Promotion into the binary envelope (base64 + flag) keeps
                // the bytes intact; the reimported row carries the binary
                // flag its payload has earned.
                $value = \base64_encode($value);
                $binaryKeys[] = $key;
            }
            yield [$key, $value];
            if ($entry->binary) {
                $binaryKeys[] = $key;
            }
            if ($ttl !== null) {
                $ttlMap[$key] = $ttl;
            }
        }
    }

    /**
     * Exporting one named database qualifies its keys, so the dump
     * can be reimported into a store whose default database is
     * different (the @ suffix is how Store routes on write). A
     * stored key carrying a literal @ must ride the document in the
     * escaped spelling the key law defines: exported bare, "p@q"
     * reimports as key "p" in a phantom database "q" with a clean
     * exit. Only the ENTRY part is escaped — the suffix separator
     * stays raw, because the rightmost unescaped @ is the one the
     * importer must split on.
     */
    private function qualifyKey(string $storedKey, ?string $dbName): string
    {
        $entryKey = \str_replace('@', '\\@', $storedKey);

        return $dbName !== null ? "{$entryKey}@{$dbName}" : $entryKey;
    }

    /**
     * Both pure-key refusals, run on every exported key BEFORE the first
     * byte of payload writes (see streamExport's pre-pass).
     */
    private function assertKeyRoundTrips(string $storedKey, string $format, ?string $dbName): void
    {
        // Stored keys carrying a backslash cannot survive the qualified
        // spelling: `bs\` + separator reads back as an escaped `\@` and
        // the entry reimports renamed into the default database (kv-007).
        // New stores cannot even hold such keys (Store::splitKey refuses
        // them at every routing door); this is the legacy-store door.
        if ($dbName !== null && (\str_ends_with($storedKey, '\\') || \str_contains($storedKey, '\\@'))) {
            throw new \RuntimeException(
                "Key \"{$storedKey}\" in database \"{$dbName}\" cannot round-trip through the qualified export spelling (backslash grammar, kv-007)."
            );
        }

        // Fail loud on envelope collisions: exporting a key that looks
        // like a reserved metadata form for THIS format would silently
        // turn into TTL/binary state on reimport. The check is
        // format-scoped — JSON reserves _ttl/_binary for its envelope,
        // YAML reserves the skate_* line prefixes plus the legacy
        // nested _ttl/_binary envelope names (kv-021) — because refusing
        // the other format's prefix family blocked entries that
        // round-trip perfectly well.
        $reason = YamlDialect::reservedKeyReason($this->qualifyKey($storedKey, $dbName), $format);
        if ($reason !== null) {
            throw new \RuntimeException(\ucfirst($reason) . '.');
        }
    }

    /**
     * Piecewise JSON writer.
     *
     * @param \Generator<array{0:string,1:string}> $rows
     * @param list<string>       $binaryKeys
     * @param array<string, int> $ttlMap
     */
    private function writeJsonPairs($sink, \Generator $rows, array &$binaryKeys, array &$ttlMap): void
    {
        $started = false;
        $emit = function (string|int $key, mixed $value) use ($sink, &$started): void {
            $encoded = \json_encode([$key => $value], self::JSON_FLAGS, 512);
            if (!\is_string($encoded)) {
                // No INVALID_UTF8_SUBSTITUTE here (kv-009): the walk promotes every
                // non-UTF-8 value into the base64 binary envelope, so anything still
                // carrying invalid bytes is a key or an unforeseen field — and there
                // a failed encode that throws LOUD beats a silent U+FFFD rewrite.
                throw new \RuntimeException('Failed to encode JSON: ' . \json_last_error_msg());
            }
            // A single-pair pretty encode is "{\n" INNER "\n}"; the inner
            // keeps the object's real 4-space indentation, so concatenating
            // pairs reproduces the whole-document encoding byte for byte
            // (pinned against the batch shape by the suite's export tests).
            \fwrite($sink, ($started ? ",\n" : "{\n") . \substr($encoded, 2, \strlen($encoded) - 4));
            $started = true;
        };

        foreach ($rows as [$key, $value]) {
            $emit($key, $value);
        }
        if ($ttlMap !== []) {
            $emit('_ttl', $ttlMap);
        }
        if ($binaryKeys !== []) {
            $emit('_binary', $binaryKeys);
        }

        if (!$started) {
            // An empty store dumps as an empty OBJECT, not `[]` — the
            // envelope is documented as a mapping, and json consumers
            // legitimately assume dict shape (candy-kv's own importers
            // spare an empty list, foreign ones would not).
            \fwrite($sink, '{}' . \PHP_EOL);

            return;
        }
        \fwrite($sink, "\n}" . \PHP_EOL);
    }

    /**
     * Piecewise YAML writer: the flat dialect is one line per pair on both
     * engines, so per-pair dumps concatenated ARE the whole-map dump byte
     * for byte (engine fold/quote behaviour is per-scalar — probe-verified
     * at 7/13/31/77/200 randomized shapes and re-pinned structurally below
     * against the suite's exact-output export tests).
     *
     * @param \Generator<array{0:string,1:string}> $rows
     * @param list<string>       $binaryKeys
     * @param array<string, int> $ttlMap
     */
    private function writeYamlPairs($sink, \Generator $rows, array &$binaryKeys, array &$ttlMap): void
    {
        // One flat dialect on both engines: TTL rides skate_ttl_<key>,
        // binary rides skate_binary_<key> (the symfony route used to dump a
        // nested _ttl map the README never described).
        foreach ($rows as [$key, $value]) {
            \fwrite($sink, YamlDialect::build([$key => $value]));
        }
        foreach ($ttlMap as $key => $seconds) {
            \fwrite($sink, YamlDialect::build([YamlDialect::TTL_PREFIX . $key => $seconds]));
        }
        foreach ($binaryKeys as $key) {
            \fwrite($sink, YamlDialect::build([YamlDialect::BINARY_PREFIX . $key => 1]));
        }
    }
}
