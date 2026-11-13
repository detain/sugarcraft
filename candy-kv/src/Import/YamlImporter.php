<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\InputPath;
use SugarCraft\Kv\Support\SizeCap;
use SugarCraft\Kv\YamlDialect;

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
 * Per-database keys use the @ suffix (a literal @ is spelled "\@", the
 * same law {@see Store::splitKey()} applies on every write):
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
 * A nested `_ttl` mapping (produced by legacy symfony-route exports)
 * is still accepted and merged.
 *
 * Anything the dialect cannot hold faithfully is refused before a single
 * write: a sequence top level, non-scalar values, and non-numeric or
 * non-positive TTLs all raise \RuntimeException naming the entry.
 *
 * The normalization law itself is shared with JsonImporter through
 * {@see FlatDocumentNormaliser} (candy-kv-063); only the metadata-harvest
 * walk (flat `skate_ttl_`/`skate_binary_` lines vs the legacy nested
 * envelope) and the plain scalar cast stay here, because those genuinely
 * differ per format.
 */
final class YamlImporter
{
    /**
     * @var array{ttl?: array<string, int>} Import options.
     *                ttl: Map of key → seconds until expiry. Keys match the
     *                FULL document-key spelling (the '@db' suffix included
     *                when present); document-level "_ttl" / "skate_ttl_"
     *                entries win over this map.
     */
    private readonly array $options;

    public function __construct(
        private readonly Store $store,
        array $options = [],
    ) {
        $this->options = FlatDocumentNormaliser::validatedOptions($options);
    }

    /**
     * Import entries from a YAML file path.
     *
     * @param string $path      Path to the YAML file.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @param int    $maxBytes  Admission ceiling (candy-kv-073); 0 = uncapped.
     * @return ImportReport Imported/overwritten/skipped triple.
     */
    public function importFromFile(string $path, bool $atomic = true, string $ifExists = 'replace', int $maxBytes = SizeCap::DEFAULT_MAX_BYTES): ImportReport
    {
        // kv-019: this is a PUBLIC library door — the path may come from
        // config or uploads in wrapping automation, never a terminal argv.
        InputPath::refuseStreamWrapper($path);

        // candy-kv-101: same directory guard as the JSON door — refuse
        // before a warning-emitting filesystem read can name this file.
        if (!\is_file($path)) {
            throw new \RuntimeException("Cannot read YAML file: {$path}");
        }

        // kv-073: stat gate before the bytes, bounded read after it — the
        // twin of the JSON door, same refusal wording.
        SizeCap::refuseOversized(\filesize($path), $maxBytes, "Import file \"{$path}\"");
        $handle = \fopen($path, 'rb');
        $yaml = $handle === false ? null : SizeCap::readStream($handle, $maxBytes, "Import file \"{$path}\"");
        if ($handle !== false) {
            \fclose($handle);
        }
        if ($yaml === null) {
            throw new \RuntimeException("Cannot read YAML file: {$path}");
        }
        return $this->importFromString($yaml, $atomic, $ifExists, $maxBytes);
    }

    /**
     * Import entries from a YAML string.
     *
     * @param string $yaml      Raw YAML string.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @param int    $maxBytes  Admission ceiling (candy-kv-073); 0 = uncapped.
     * @return ImportReport Imported/overwritten/skipped triple.
     * @throws \RuntimeException when the document shape cannot round-trip.
     */
    public function importFromString(string $yaml, bool $atomic = true, string $ifExists = 'replace', int $maxBytes = SizeCap::DEFAULT_MAX_BYTES): ImportReport
    {
        // kv-073 string door: the bytes already exist, so the guard counts
        // them; SizeCap reads it before the parser does (the document is
        // refused whole — a truncated parse of a truncated read is the
        // silent-corruption shape this law exists to kill).
        SizeCap::refuseOversized(\strlen($yaml), $maxBytes, 'The import document');

        $data = $this->parseYaml($yaml);

        // A YAML sequence is not a key/value document — refuse rather than
        // import its indices as entries "0", "1", ...
        if ($data !== [] && \array_is_list($data)) {
            throw new \RuntimeException('Expected a YAML mapping of keys to values, got a sequence.');
        }

        // Collect TTL map (skate_ttl_KEY: seconds entries), the binary flag
        // map, and the legacy nested _ttl/_binary envelope forms. The walk
        // is YAML's own (candy-kv-063 keeps it here); the laws each shape
        // check enforces are the shared normaliser's.
        $ttlMap = [];
        $binaryMap = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\str_starts_with($key, YamlDialect::TTL_PREFIX)) {
                $actualKey = \substr($key, \strlen(YamlDialect::TTL_PREFIX));
                $ttlMap[$actualKey] = FlatDocumentNormaliser::wholeSeconds(
                    \sprintf('TTL for entry "%s"', $actualKey),
                    $value
                );
                unset($data[$key]);
            } elseif (\str_starts_with($key, YamlDialect::BINARY_PREFIX)) {
                $actualKey = \substr($key, \strlen(YamlDialect::BINARY_PREFIX));
                // Export writes 0 or 1. Anything else must NOT fail open:
                // (bool)(int)'text' is false, which silently demotes a
                // base64 payload to literal text.
                if (!((\is_int($value) && ($value === 0 || $value === 1)) || (\is_string($value) && ($value === '0' || $value === '1')))) {
                    throw new \RuntimeException(
                        \sprintf('Binary flag for entry "%s" must be 0 or 1, got %s.', $actualKey, \get_debug_type($value))
                    );
                }
                $binaryMap[$actualKey] = $value === 1 || $value === '1';
                unset($data[$key]);
            } elseif ($key === YamlDialect::JSON_TTL_KEY) {
                // Legacy symfony-route export: nested `_ttl: {key: secs}`.
                // A scalar here is a mangled envelope, not a data key.
                foreach (FlatDocumentNormaliser::ttlMap($value) as $ttlKey => $seconds) {
                    $ttlMap[$ttlKey] = $seconds;
                }
                unset($data[$key]);
            } elseif ($key === YamlDialect::JSON_BINARY_KEY) {
                // Legacy symfony-route export: nested `_binary: [names]`.
                foreach (FlatDocumentNormaliser::binaryKeyList($value) as $name => $flag) {
                    $binaryMap[$name] = $flag;
                }
                unset($data[$key]);
            }
        }

        // Merge with constructor options: file-level entries win, and keys
        // match the full document spelling (e.g. 'k@mydb'), not the bare key.
        $ttlMap = \array_merge($this->options['ttl'] ?? [], $ttlMap);

        // Normalise every row BEFORE any write (fail-fast: a document with
        // one bad entry imports nothing rather than a prefix of itself).
        $rows = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $binary = ($binaryMap[$key] ?? false) === true;
            $ttl = FlatDocumentNormaliser::resolveTtl($ttlMap, $key);
            FlatDocumentNormaliser::validatedEntryKey($key, $this->store);

            if (\is_int($value) || \is_float($value)) {
                // The dialect types its scalars; the text is what the
                // document spelled, so a plain cast is the faithful store
                // (JSON's number fidelity law is the format-local twin).
                $value = (string) $value;
            } elseif (!\is_string($value)) {
                FlatDocumentNormaliser::refuseNonScalar($key, $value);
            }

            if ($binary) {
                $value = YamlDialect::decodeBinary($value, $key);
            }
            $rows[] = [$key, $value, $binary, $ttl];
        }

        // candy-kv-051: the live-store meeting point — existence check,
        // clobber policy and the count triple all live in ImportCommit,
        // reached through the shared commit roster (candy-kv-063) that
        // forbids a private copy in either format class.
        return FlatDocumentNormaliser::commit($this->store, $rows, $atomic, $ifExists);
    }

    /**
     * YAML parser entry point.
     *
     * Both routes implement the same flat dialect (see
     * {@see \SugarCraft\Kv\YamlDialect}): symfony/yaml when the component is
     * present (declared as a `suggest`; candy-kv's own dev install ships it),
     * otherwise the dialect's strict fallback parser — which understands
     * quoted keys, double-quote escapes (backslash, \n, control bytes) and
     * plain scalars, and throws RuntimeException ('Syntax error on line N')
     * on anything it cannot represent faithfully.
     *
     * @return array<array-key, mixed>
     */
    private function parseYaml(string $yaml): array
    {
        return YamlDialect::parse($yaml);
    }
}
