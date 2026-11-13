<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Import;

use SugarCraft\Kv\Store;
use SugarCraft\Kv\Support\Ttl;

/**
 * The normalization law shared by both flat importers (candy-kv-063).
 *
 * JSON and YAML parse into the same flat key/value document; everything
 * from there to the live store — constructor option validation, the
 * `_ttl`/`_binary` envelope shapes, whole-second TTL parsing, the
 * per-row key law, the non-scalar refusal sentence, and the atomic
 * commit roster — was byte-identical code living twice. Post-mortems
 * copied are post-mortems that drift: the kv-041 transaction-roster bug
 * landed in both classes at once precisely because they were twins, and
 * every future law change demanded two coordinated edits that could fall
 * out of step. A structural pin forbids either importer from growing a
 * private copy of this loop again.
 *
 * What deliberately stays per-importer: the metadata-harvest walks
 * (JSON's top-level `_ttl`/`_binary` members vs YAML's `skate_ttl_` and
 * `skate_binary_` line prefixes — the formats genuinely differ there),
 * and the number law (JSON's document-spelling round-trip fidelity via
 * floatTextOrFail vs YAML's dialect-typed plain scalars).
 */
final class FlatDocumentNormaliser
{
    /**
     * Fail fast on the constructor `ttl` map (candy-kv-031): the options
     * and a document share one write path, so they must share one law. A
     * raw `'soon'` used to leak a TypeError out of resolveTtl's return
     * type where every document-level shape answers with a domain
     * exception.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function validatedOptions(array $options): array
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
     * The nested `_ttl` envelope, shared by the JSON top-level member and
     * the YAML legacy symfony-route line: a map of key → whole seconds,
     * or a refusal naming what arrived instead.
     *
     * @return array<string, int>
     */
    public static function ttlMap(mixed $map): array
    {
        if (!\is_array($map)) {
            throw new \RuntimeException(
                \sprintf('_ttl must be a map of key → seconds, got %s.', \get_debug_type($map))
            );
        }

        $ttlMap = [];
        foreach ($map as $key => $seconds) {
            $ttlMap[(string) $key] = self::wholeSeconds(\sprintf('_ttl entry "%s"', (string) $key), $seconds);
        }

        return $ttlMap;
    }

    /**
     * The `_binary` name-list envelope: keys whose values are base64 of
     * raw bytes. Without the flag a reimport would store the base64 text
     * as the value, so the list must be exact.
     *
     * @return array<string, true>
     */
    public static function binaryKeyList(mixed $list): array
    {
        if (!\is_array($list)) {
            throw new \RuntimeException(
                \sprintf('_binary must be a list of key names, got %s.', \get_debug_type($list))
            );
        }

        $binaryKeys = [];
        foreach ($list as $name) {
            if (!\is_string($name)) {
                // A skipped non-string member would silently demote one
                // intended binary entry to literal base64 text.
                throw new \RuntimeException(
                    \sprintf('_binary must list key names as strings, got %s.', \get_debug_type($name))
                );
            }
            $binaryKeys[$name] = true;
        }

        return $binaryKeys;
    }

    /**
     * Whole seconds or a refusal — is_numeric admits floats and
     * float-shaped strings; the store counts whole seconds, and (int)
     * would silently truncate 1.5 to 1 while the text promises "integer".
     * The subject is the sentence head ('_ttl entry "k"', 'TTL for entry
     * "k"'), so each envelope spelling keeps its own voice on one law.
     */
    public static function wholeSeconds(string $subject, mixed $seconds): int
    {
        if (!\is_int($seconds) && !\is_numeric($seconds)) {
            throw new \RuntimeException(
                \sprintf('%s must be an integer number of seconds, got %s.', $subject, \get_debug_type($seconds))
            );
        }
        if ((float) $seconds !== (float) (int) $seconds) {
            throw new \RuntimeException(
                \sprintf('%s must be an integer number of seconds, got %s.', $subject, (string) $seconds)
            );
        }

        return (int) $seconds;
    }

    /**
     * @param array<string, int> $ttlMap
     */
    public static function resolveTtl(array $ttlMap, string $key): ?int
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
        // used to reach the writer unbounded — overflow poisons the row
        // and a near-max value mints the forever zombie the TTL spells the
        // opposite of.
        if ($ttl > Ttl::MAX_SECONDS) {
            throw new \RuntimeException(
                \sprintf('TTL for entry "%s" exceeds the maximum of %d seconds.', $key, Ttl::MAX_SECONDS)
            );
        }

        return $ttl;
    }

    /**
     * The key law pre-writes (candy-kv-030): Store::splitKey is the same
     * authority Store::set routes by, and the walk happens BEFORE any row
     * is committed — under atomic=false the old shape left prefix rows
     * mutated and made the refuse-before-any-write law false for exactly
     * this error class.
     */
    public static function validatedEntryKey(string $key, Store $store): void
    {
        if (Store::splitKey($key, $store->defaultDatabase())[1] === '') {
            // Same law and wording Store::set applies after parsing; an
            // empty entry key ({"":"v"}, ': v') would otherwise still
            // poison the pre-pass's completeness.
            throw new \InvalidArgumentException('Entry key must not be empty.');
        }
    }

    /**
     * One non-scalar refusal sentence for both formats (candy-kv-103
     * wording): the type name is not reliably consonant-led, so the
     * article-free "of type %s" shape carries every get_debug_type value.
     * Declared `never` because it always throws — the callers' rows-shape
     * law depends on the analyzer knowing the branch cannot fall through.
     */
    public static function refuseNonScalar(string $key, mixed $value): never
    {
        // null lands here too: get_debug_type(null) = 'null'. A null once
        // coerced silently to an empty string — explicit "nothing" is not
        // the same as "".
        throw new \RuntimeException(
            \sprintf('Entry "%s" has a value of type %s; only strings and numbers round-trip.', $key, \get_debug_type($value))
        );
    }

    /**
     * The commit step (candy-kv-051 kernel + candy-kv-063 roster).
     *
     * The live-store meeting point — existence check, clobber policy and
     * the count triple — is ImportCommit's, reached through exactly one
     * shared call site. Zero-entry documents skip the transaction
     * entirely: opening a database to BEGIN/COMMIT nothing would create
     * the SQLite file, breaking the documented law that no file exists
     * "until the first write" (README, Data directory).
     *
     * The roster MUST see every row (and use the same escape-aware '@'
     * law Store::set routes by): the old value-type-filtered strrpos()
     * walk missed rows whose values were numbers, letting their writes
     * land on a second database OUTSIDE any transaction without ever
     * tripping the multi-database refusal below.
     *
     * @param list<array{0: string, 1: string, 2: bool, 3: ?int}> $rows validated [key, value, binary, ttl]
     */
    public static function commit(Store $store, array $rows, bool $atomic, string $ifExists): ImportReport
    {
        $import = fn(): ImportReport => ImportCommit::run($store, $rows, $ifExists);

        if ($atomic && $rows !== []) {
            $dbNames = [];
            foreach ($rows as [$key]) {
                $dbNames[] = Store::splitKey($key, $store->defaultDatabase())[0];
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

            // Single-database: run all sets inside a transaction on that
            // db, via the public Store API (it routes to the same cached
            // Database the sets below use, so they all join the
            // transaction).
            $targetDb = $uniqueDbs[0] ?? $store->defaultDatabase();

            return $store->transaction($targetDb, $import);
        }

        return $import();
    }
}
