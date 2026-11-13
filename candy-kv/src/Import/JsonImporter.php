<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\InputPath;
use SugarCraft\Kv\YamlDialect;

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
 * and a "_binary" list naming keys whose values are base64 of raw bytes:
 * {
 *   "_binary": ["key1"],
 *   "key1": "AH/7"
 * }
 *
 * Anything the store cannot hold faithfully is refused up front, before a
 * single write: a list top level, non-scalar values, and non-numeric or
 * non-positive TTLs all raise \RuntimeException naming the offending entry.
 * JSON numbers keep their document spelling (candy-kv-024): an out-of-range
 * integer lands as its exact digit string, and a float that storing would
 * rewrite (3.10, 1e2, 1e999) refuses the import like any other unfaithful
 * value.
 *
 * The normalization law itself is shared with YamlImporter through
 * {@see FlatDocumentNormaliser} (candy-kv-063); only the JSON number
 * fidelity half and the top-level envelope harvest stay here, because
 * those genuinely differ per format.
 */
final class JsonImporter
{
    private readonly array $options;

    /**
     * @param array{ttl?: array<string, int>} $options Import options.
     *                   ttl: Map of key → seconds until expiry. Keys match
     *                   the FULL document-key spelling (the '@db' suffix
     *                   included when present); a document-level "_ttl"
     *                   entry for the same key wins over this map.
     */
    public function __construct(
        private readonly Store $store,
        array $options = [],
    ) {
        $this->options = FlatDocumentNormaliser::validatedOptions($options);
    }

    /**
     * Import entries from a JSON file path.
     *
     * @param string $path      Path to the JSON file.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @return ImportReport Imported/overwritten/skipped triple.
     */
    public function importFromFile(string $path, bool $atomic = true, string $ifExists = 'replace'): ImportReport
    {
        // kv-019: this is a PUBLIC library door — the path may come from
        // config or uploads in wrapping automation, never a terminal argv.
        InputPath::refuseStreamWrapper($path);

        // candy-kv-101: guard the read itself, not just the CLI door — a
        // directory (or FIFO) makes file_get_contents emit an E_WARNING
        // carrying this library's absolute source path before returning
        // false. is_file() answers without touching contents, so the
        // domain refusal below is the only thing the caller ever sees.
        if (!\is_file($path)) {
            throw new \RuntimeException("Cannot read JSON file: {$path}");
        }

        $json = \file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("Cannot read JSON file: {$path}");
        }
        return $this->importFromString($json, $atomic, $ifExists);
    }

    /**
     * Import entries from a JSON string.
     *
     * @param string $json      Raw JSON string.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @return ImportReport Imported/overwritten/skipped triple.
     * @throws \RuntimeException when the document shape cannot round-trip.
     */
    public function importFromString(string $json, bool $atomic = true, string $ifExists = 'replace'): ImportReport
    {
        // Guarded decode: a non-array top level (bare scalar/null) is rejected
        // with a clear \RuntimeException instead of silently iterating as an
        // empty foreach downstream. Malformed JSON still surfaces \JsonException.
        $data = self::decodeDocument($json);

        // A JSON list is not a key/value document. Its integer subscripts
        // would import as entries "0", "1", ... — a silent reshape of the
        // caller's data — so refuse the shape outright.
        if ($data !== [] && \array_is_list($data)) {
            throw new \RuntimeException('Expected a JSON object mapping keys to values, got a list.');
        }

        // Extract the TTL envelope if present (whole seconds, or refuse).
        $ttlMap = isset($data['_ttl']) ? FlatDocumentNormaliser::ttlMap($data['_ttl']) : [];
        unset($data['_ttl']);

        // Binary entries ride base64 values plus the "_binary" flag list;
        // without it a reimport would store the base64 text as the value.
        $binaryKeys = isset($data['_binary']) ? FlatDocumentNormaliser::binaryKeyList($data['_binary']) : [];
        unset($data['_binary']);

        // Merge constructor options with file-level TTL. Document keys match
        // on their full spelling (the '@db' suffix included when present),
        // and the file-level "_ttl" map wins over the constructor map.
        $ttlMap = \array_merge($this->options['ttl'] ?? [], $ttlMap);

        // Normalise every row BEFORE any write (fail-fast: a document with
        // one bad entry imports nothing rather than importing a prefix of
        // itself and reporting a partial count). The key law, the TTL
        // horizon and the non-scalar sentence are the shared normaliser's;
        // the number fidelity below is JSON's own.
        $rows = [];
        foreach ($data as $key => $value) {
            $key = (string) $key; // JSON object keys are strings; PHP may hand us int subscripts.
            $binary = ($binaryKeys[$key] ?? false) === true;
            $ttl = FlatDocumentNormaliser::resolveTtl($ttlMap, $key);
            FlatDocumentNormaliser::validatedEntryKey($key, $this->store);

            if (\is_int($value)) {
                $value = (string) $value;
            } elseif (\is_float($value)) {
                // kv-024: only a float whose canonical text IS a spelling the
                // document used round-trips as stored text; see floatTextOrFail.
                $value = self::floatTextOrFail($value, $key, $json);
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
     * Decode the document with the flags this store's fidelity law needs:
     * JSON_THROW_ON_ERROR (malformed answers loudly, as sugar-core's shared
     * Json::decodeArray does) plus JSON_BIGINT_AS_STRING (candy-kv-024) —
     * an out-of-int-range literal must keep every digit, and without the
     * flag json_decode hands back a float, so `18446744073709551615` used
     * to import as '1.844674407371E+19' with the digits destroyed. The
     * shared helper cannot carry the flag (its rider is tracked for the
     * core lane, gate G8: kv-local decode flags), so the guarded top-level
     * check is mirrored here with the same wording.
     *
     * @return array<array-key, mixed>
     *
     * @throws \JsonException    On malformed JSON.
     * @throws \RuntimeException When the decoded top level is not an object.
     */
    private static function decodeDocument(string $json): array
    {
        // candy-kv-102: empty-document symmetry with the YAML route. The
        // dialect answers trim === '' as an empty mapping and the importer
        // commits 'Imported 0 entries.' at rc 0; json_decode('') threw
        // Syntax error, so the same empty shape carried two verdicts. A
        // document that IS whitespace imports nothing — loudly silently,
        // exactly like its YAML twin.
        if (\trim($json) === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = \json_decode($json, true, flags: \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(
                'Expected JSON top level to be an array, got ' . \get_debug_type($decoded) . '.'
            );
        }

        return $decoded;
    }

    /**
     * The float half of the number fidelity law (candy-kv-024): a decoded
     * float may be stored only when its canonical text — the shortest repr
     * that re-parses to the identical value, which is what a plain (string)
     * cast yields — is a plain fixed-point decimal AND a numeric literal the
     * raw document actually contains. Otherwise storing rewrites digits the
     * document spelled: 3.10 would land as '3.1', 1e2 as '100', 1e999 decodes
     * to the word 'INF'. The README promises exactly this: values that cannot
     * round-trip as stored text refuse the whole import. Known best-effort
     * hole: the scan is document-wide, so a drifted spelling that happens to
     * match a sibling entry's exact literal slips through — every such pair
     * carries the same digits at least once in the document anyway.
     */
    private static function floatTextOrFail(float $value, string $key, string $json): string
    {
        $text = (string) $value;
        if (!\is_finite($value) || \preg_match('/^-?\d++(?:\.\d++)?$/', $text) !== 1) {
            throw new \RuntimeException(
                \sprintf('Entry "%s" has the float %s; only exactly-spelled decimal numbers round-trip.', $key, $text)
            );
        }
        if (\preg_match('/(?<![\d.])' . \preg_quote($text, '/') . '(?![\d.eE])/', $json) !== 1) {
            throw new \RuntimeException(
                \sprintf('Entry "%s" has the float %s; storing it would rewrite the document spelling, so only exactly-spelled decimal numbers round-trip.', $key, $text)
            );
        }

        return $text;
    }
}
