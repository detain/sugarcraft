<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\InputPath;
use SugarCraft\Kv\Support\Ttl;
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
        $this->options = self::validatedOptions($options);
    }

    /**
     * Fail fast on the constructor `ttl` map (candy-kv-031): the options and
     * a document share one write path, so they must share one law. A raw
     * `'soon'` used to leak a TypeError out of resolveTtl's return type
     * where every document-level shape answers with a domain exception.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function validatedOptions(array $options): array
    {
        if (!\array_key_exists('ttl', $options) || $options['ttl'] === null) {
            return $options;
        }
        if (!\is_array($options['ttl'])) {
            throw new \RuntimeException(
                \sprintf('Import option "ttl" must be a map of key → seconds, got %s.', \get_debug_type($options['ttl']))
            );
        }

        $validated = [];
        foreach ($options['ttl'] as $key => $seconds) {
            if (!\is_int($seconds) && !\is_numeric($seconds)) {
                throw new \RuntimeException(
                    \sprintf('TTL option for entry "%s" must be an integer number of seconds, got %s.', (string) $key, \get_debug_type($seconds))
                );
            }
            if ((float) $seconds !== (float) (int) $seconds) {
                throw new \RuntimeException(
                    \sprintf('TTL option for entry "%s" must be an integer number of seconds, got %s.', (string) $key, (string) $seconds)
                );
            }
            $ttl = (int) $seconds;
            if ($ttl < 1 || $ttl > Ttl::MAX_SECONDS) {
                throw new \RuntimeException(
                    \sprintf('TTL option for entry "%s" must be between 1 and %d seconds.', (string) $key, Ttl::MAX_SECONDS)
                );
            }
            $validated[(string) $key] = $ttl;
        }
        $options['ttl'] = $validated;

        return $options;
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

        // Extract TTL map if present (values must be whole seconds).
        $ttlMap = [];
        if (isset($data['_ttl'])) {
            if (!\is_array($data['_ttl'])) {
                throw new \RuntimeException(
                    \sprintf('_ttl must be a map of key → seconds, got %s.', \get_debug_type($data['_ttl']))
                );
            }
            foreach ($data['_ttl'] as $ttlKey => $seconds) {
                if (!\is_int($seconds) && !\is_numeric($seconds)) {
                    throw new \RuntimeException(
                        \sprintf('_ttl entry "%s" must be an integer number of seconds, got %s.', (string) $ttlKey, \get_debug_type($seconds))
                    );
                }
                // is_numeric admits floats and float-shaped strings; the
                // store counts whole seconds, and (int) would silently
                // truncate 1.5 to 1 while the text promises "integer".
                if ((float) $seconds !== (float) (int) $seconds) {
                    throw new \RuntimeException(
                        \sprintf('_ttl entry "%s" must be an integer number of seconds, got %s.', (string) $ttlKey, (string) $seconds)
                    );
                }
                $ttlMap[(string) $ttlKey] = (int) $seconds;
            }
            unset($data['_ttl']);
        }

        // Binary entries ride base64 values plus the "_binary" flag list;
        // without it a reimport would store the base64 text as the value.
        $binaryKeys = [];
        if (isset($data['_binary'])) {
            if (!\is_array($data['_binary'])) {
                throw new \RuntimeException(
                    \sprintf('_binary must be a list of key names, got %s.', \get_debug_type($data['_binary']))
                );
            }
            foreach ($data['_binary'] as $bKey) {
                if (!\is_string($bKey)) {
                    // A skipped non-string member would silently demote one
                    // intended binary entry to literal base64 text.
                    throw new \RuntimeException(
                        \sprintf('_binary must list key names as strings, got %s.', \get_debug_type($bKey))
                    );
                }
                $binaryKeys[$bKey] = true;
            }
            unset($data['_binary']);
        }

        // Merge constructor options with file-level TTL. Document keys match
        // on their full spelling (the '@db' suffix included when present),
        // and the file-level "_ttl" map wins over the constructor map.
        $ttlMap = \array_merge($this->options['ttl'] ?? [], $ttlMap);

        // Normalise every row BEFORE any write (fail-fast: a document with
        // one bad entry imports nothing rather than importing a prefix of
        // itself and reporting a partial count).
        $rows = [];
        foreach ($data as $key => $value) {
            $key = (string) $key; // JSON object keys are strings; PHP may hand us int subscripts.
            $binary = ($binaryKeys[$key] ?? false) === true;
            $ttl = self::resolveTtl($ttlMap, $key);
            // The key law pre-writes too (candy-kv-030): Store::splitKey is
            // the same authority Store::set routes by, and the walk happens
            // HERE, so a malformed key refuses the document before any row
            // is committed — under atomic=false the old shape left prefix
            // rows mutated and made the docblock's refuse-before-any-write
            // law false for exactly this error class.
            if (Store::splitKey($key, $this->store->defaultDatabase())[1] === '') {
                // Same law and wording Store::set applies after parsing; an
                // empty entry key ({"":"v"}) would otherwise still poison
                // the pre-pass's completeness.
                throw new \InvalidArgumentException('Entry key must not be empty.');
            }

            if (\is_int($value)) {
                $value = (string) $value;
            } elseif (\is_float($value)) {
                // kv-024: only a float whose canonical text IS a spelling the
                // document used round-trips as stored text; see floatTextOrFail.
                $value = self::floatTextOrFail($value, $key, $json);
            } elseif (!\is_string($value)) {
                // null lands here too: get_debug_type(null) = 'null'. A
                // null once coerced silently to an empty string — explicit
                // "nothing" is not the same as "".
                throw new \RuntimeException(
                    \sprintf('Entry "%s" has a %s value; only strings and numbers round-trip.', $key, \get_debug_type($value))
                );
            }

            if ($binary) {
                $value = YamlDialect::decodeBinary($value, $key);
            }
            $rows[] = [$key, $value, $binary, $ttl];
        }

        // candy-kv-051: the live-store meeting point — existence check,
        // clobber policy and the count triple all live in ImportCommit,
        // shared verbatim with YamlImporter (a structural pin forbids a
        // private copy of that loop in either class).
        $import = fn(): ImportReport => ImportCommit::run($this->store, $rows, $ifExists);

        if ($atomic && $rows !== []) {
            // Zero-entry documents skip the transaction entirely: opening a
            // database to BEGIN/COMMIT nothing would create the SQLite file,
            // breaking the documented law that no file exists "until the
            // first write" (README, Data directory).
            // Collect the databases the validated keys route to so the whole
            // import runs inside that database's transaction. The roster MUST
            // see every row (and use the same escape-aware '@' law Store::set
            // routes by): the old value-type-filtered strrpos() walk missed
            // rows whose values were numbers, letting their writes land on a
            // second database OUTSIDE any transaction without ever tripping
            // the multi-database refusal below.
            $dbNames = [];
            foreach ($rows as [$key]) {
                $dbNames[] = Store::splitKey($key, $this->store->defaultDatabase())[0];
            }

            $uniqueDbs = \array_unique($dbNames);

            // Multi-database atomic import is not supported — cross-db
            // SQLite transactions cannot be atomic across separate .db files.
            if (\count($uniqueDbs) > 1) {
                throw new \RuntimeException(
                    'Atomic import is not supported across multiple databases. ' .
                    'Use --no-atomic (atomic=false in the library) for ' .
                    'multi-database imports, or import each database separately.'
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

    /**
     * @param array<string, int> $ttlMap
     */
    private static function resolveTtl(array $ttlMap, string $key): ?int
    {
        if (!\array_key_exists($key, $ttlMap)) {
            return null;
        }
        $ttl = $ttlMap[$key];
        if ($ttl <= 0) {
            throw new \RuntimeException(
                \sprintf('TTL for entry "%s" must be at least 1 second; omit it for a forever entry.', $key)
            );
        }
        // The shared horizon (candy-kv-001): an integer-shaped value that
        // passed the number law (PHP_INT_MAX rides the float round-trip)
        // used to reach the writer unbounded — overflow poisons the row and
        // a near-max value mints the forever zombie the TTL spells the
        // opposite of.
        if ($ttl > Ttl::MAX_SECONDS) {
            throw new \RuntimeException(
                \sprintf('TTL for entry "%s" exceeds the maximum of %d seconds.', $key, Ttl::MAX_SECONDS)
            );
        }
        return $ttl;
    }
}
