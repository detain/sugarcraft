<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\InputPath;
use SugarCraft\Kv\Support\Ttl;
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
     * Import entries from a YAML file path.
     *
     * @param string $path      Path to the YAML file.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @return ImportReport Imported/overwritten/skipped triple.
     */
    public function importFromFile(string $path, bool $atomic = true, string $ifExists = 'replace'): ImportReport
    {
        // kv-019: this is a PUBLIC library door — the path may come from
        // config or uploads in wrapping automation, never a terminal argv.
        InputPath::refuseStreamWrapper($path);

        $yaml = \file_get_contents($path);
        if ($yaml === false) {
            throw new \RuntimeException("Cannot read YAML file: {$path}");
        }
        return $this->importFromString($yaml, $atomic, $ifExists);
    }

    /**
     * Import entries from a YAML string.
     *
     * @param string $yaml      Raw YAML string.
     * @param bool   $atomic    Whether to wrap all sets in a single transaction.
     * @param string $ifExists  Clobber policy: replace, skip or fail.
     * @return ImportReport Imported/overwritten/skipped triple.
     * @throws \RuntimeException when the document shape cannot round-trip.
     */
    public function importFromString(string $yaml, bool $atomic = true, string $ifExists = 'replace'): ImportReport
    {
        $data = $this->parseYaml($yaml);

        // A YAML sequence is not a key/value document — refuse rather than
        // import its indices as entries "0", "1", ...
        if ($data !== [] && \array_is_list($data)) {
            throw new \RuntimeException('Expected a YAML mapping of keys to values, got a sequence.');
        }

        // Collect TTL map (skate_ttl_KEY: seconds entries), the binary flag
        // map, and the legacy nested _ttl/_binary envelope forms.
        $ttlMap = [];
        $binaryMap = [];
        foreach ($data as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\str_starts_with($key, YamlDialect::TTL_PREFIX)) {
                $actualKey = \substr($key, \strlen(YamlDialect::TTL_PREFIX));
                if (!\is_int($value) && !\is_numeric($value)) {
                    throw new \RuntimeException(
                        \sprintf('TTL for entry "%s" must be an integer number of seconds, got %s.', $actualKey, \get_debug_type($value))
                    );
                }
                // is_numeric admits float-shaped text; the store counts whole
                // seconds and (int) would truncate 1.5 silently.
                if ((float) $value !== (float) (int) $value) {
                    throw new \RuntimeException(
                        \sprintf('TTL for entry "%s" must be an integer number of seconds, got %s.', $actualKey, (string) $value)
                    );
                }
                $ttlMap[$actualKey] = (int) $value;
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
                if (!\is_array($value)) {
                    throw new \RuntimeException(
                        \sprintf('_ttl must be a map of key → seconds, got %s.', \get_debug_type($value))
                    );
                }
                foreach ($value as $k => $secs) {
                    if (!\is_int($secs) && !\is_numeric($secs)) {
                        throw new \RuntimeException(
                            \sprintf('_ttl entry "%s" must be an integer number of seconds, got %s.', (string) $k, \get_debug_type($secs))
                        );
                    }
                    if ((float) $secs !== (float) (int) $secs) {
                        throw new \RuntimeException(
                            \sprintf('_ttl entry "%s" must be an integer number of seconds, got %s.', (string) $k, (string) $secs)
                        );
                    }
                    $ttlMap[(string) $k] = (int) $secs;
                }
                unset($data[$key]);
            } elseif ($key === YamlDialect::JSON_BINARY_KEY) {
                if (!\is_array($value)) {
                    throw new \RuntimeException(
                        \sprintf('_binary must be a list of key names, got %s.', \get_debug_type($value))
                    );
                }
                foreach ($value as $k) {
                    if (!\is_string($k)) {
                        throw new \RuntimeException(
                            \sprintf('_binary must list key names as strings, got %s.', \get_debug_type($k))
                        );
                    }
                    $binaryMap[$k] = true;
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
            $ttl = self::resolveTtl($ttlMap, $key);
            // The key law pre-writes too (candy-kv-030): Store::splitKey is
            // the same authority Store::set routes by, and the walk happens
            // HERE, so a malformed key refuses the document before any row
            // is committed — under atomic=false the old shape left prefix
            // rows mutated and made the refuse-before-any-write law false
            // for exactly this error class.
            if (Store::splitKey($key, $this->store->defaultDatabase())[1] === '') {
                // Same law and wording Store::set applies after parsing; an
                // empty entry key (: v) would otherwise still poison the
                // pre-pass's completeness.
                throw new \InvalidArgumentException('Entry key must not be empty.');
            }

            if (\is_int($value) || \is_float($value)) {
                $value = (string) $value;
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
        // shared verbatim with JsonImporter (a structural pin forbids a
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
