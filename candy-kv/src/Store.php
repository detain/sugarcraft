<?php

declare(strict_types=1);

/**
 * CandyKv — a personal key/value store with multi-database support.
 *
 * A native PHP project inspired by charmbracelet/skate (Go), providing:
 * - Set / get / delete operations on named keys
 * - Multiple independent databases (named with @dbname suffix or explicit db arg)
 * - Binary data storage (base64-encoded internally)
 * - Glob pattern matching on keys (list / delete)
 * - Ordered listing (forward / reverse) with keys-only / values-only / all modes
 * - SQLite-backed persistent storage
 *
 * @see https://github.com/charmbracelet/skate
 */
namespace SugarCraft\Kv;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Kv\Lang;
use SugarCraft\Kv\Support\InputPath;
use SugarCraft\Kv\Support\Ttl;

/**
 * Main entry point for the candy-kv key/value store.
 *
 * Provides a store-wide API that routes operations to the appropriate
 * database. Each distinct database maps to its own SQLite file in the
 * data directory (default: $XDG_CONFIG_HOME/skate or ~/.config/skate —
 * the 'skate' name is the upstream port's directory and is kept for
 * users migrating from Go skate; the files themselves live 0600).
 *
 * @example
 * ```php
 * $kv = new Store();
 * $kv->set('key', 'value');
 * echo $kv->get('key');
 * foreach ($kv->list() as $entry) { ... }
 * ```
 */
final class Store
{
    // Factories: Store/Database deliberately have no `::new()` convenience
    // constructor (unlike the value-object libs) — both are constructor-arg
    // service classes where the constructor IS the only sensible entry point,
    // mirroring upstream's `skate.New()` usage shape. @rename-keep

    /** Default data directory relative to home. */
    public const DEFAULT_SUBDIR = '.config/skate';

    /** @var array<string, Database> Cached open database connections. */
    private array $databases = [];

    /** Base directory where database files live. */
    private string $dataDir;

    /** Default database name. */
    private string $defaultDb;

    /**
     * Create a new Store handle.
     *
     * Construction touches nothing on disk: no directory is created and no
     * database file is opened until the first WRITE. Reads against a store
     * that has never been written see empty results, so `list`, `get` or a
     * typo'd `key@dbname` can never litter the config directory with empty
     * .db files.
     *
     * @param string|null $dataDir  Override the data directory path.
     *                              Defaults to $XDG_CONFIG_HOME/skate or ~/.config/skate.
     * @param string      $defaultDb Default database name (used when no @db suffix is given).
     */
    public function __construct(?string $dataDir = null, string $defaultDb = 'default')
    {
        $this->dataDir = $dataDir ?? self::resolveDataDir();
        $this->defaultDb = $defaultDb;
    }

    // -------------------------------------------------------------------------
    // Core operations
    // -------------------------------------------------------------------------

    /**
     * Set a value.
     *
     * @param string $key    Entry key. May include @dbname to target a specific
     *                       database — E.g. "token@passwords" sets "token" in
     *                       the "passwords" database. A literal @ inside the
     *                       entry key is spelled "\@" (see parseKey rules);
     *                       an unescaped second @ is refused. Empty keys are
     *                       refused.
     * @param string $value  The value to store. Pass raw bytes with
     *                       $binary = true (they are base64-encoded for
     *                       storage) — the CLI auto-detects NUL and invalid
     *                       UTF-8 payloads and arms the flag itself.
     * @param bool   $binary Whether to treat $value as raw binary data.
     * @param int|null $ttlSeconds If > 0, entry expires after this many seconds.
     */
    public function set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null): Entry
    {
        // Guard first (candy-kv-001/014): a non-positive TTL is a silent
        // inversion (the writer would drop it → forever entry, the exact
        // opposite of what an expiry spells), and an oversized one overflows
        // the epoch arithmetic into poison/zombie rows. A forever entry is
        // made by passing NO ttl, never by a clever value.
        if ($ttlSeconds !== null && !Ttl::inRange($ttlSeconds)) {
            throw new \InvalidArgumentException(\sprintf(
                'TTL must be between 1 and %d seconds, got %d; omit the TTL for a forever entry.',
                Ttl::MAX_SECONDS,
                $ttlSeconds,
            ));
        }
        [$dbName, $entryKey] = $this->parseKey($key);
        if ($entryKey === '') {
            throw new \InvalidArgumentException('Entry key must not be empty.');
        }
        $stored = $binary ? \base64_encode($value) : $value;
        return $this->database($dbName, create: true)->set($entryKey, $stored, $binary, $ttlSeconds);
    }

    /**
     * Set a value that automatically expires after $ttlSeconds.
     *
     * @param string $key         Entry key (supports @dbname suffix).
     * @param mixed  $value       Scalar value (stringified); null, arrays,
     *                            non-stringable objects and resources are
     *                            refused rather than silently mangled (null
     *                            once slipped through and stored as "").
     * @param int    $ttlSeconds  Seconds until the entry expires. A value
     *                            <= 0 is a documented NO-OP: nothing is
     *                            written (it does NOT store the key forever).
     * @throws \InvalidArgumentException for values with no scalar string form.
     */
    public function setWithTtl(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        if ($value === null || \is_array($value) || \is_resource($value) || (\is_object($value) && !\method_exists($value, '__toString'))) {
            throw new \InvalidArgumentException(
                \sprintf('setWithTtl expects a scalar value, got %s for key "%s".', \get_debug_type($value), $key)
            );
        }
        $this->set($key, (string) $value, false, $ttlSeconds);
    }

    /**
     * Get a value by key.
     *
     * Pure lookup: a miss returns $fallback and nothing is written to any
     * stream. Typo suggestions are a CLI concern — the library never emits
     * to STDERR (a consumer embedding the store in a pipeline would get
     * attacker-controlled key material echoed into its output); ask
     * {@see self::suggestionFor()} for the closest existing key instead.
     * A binary-flagged row holding invalid base64 raises rather than
     * returning the corrupt text (see Entry::rawValue).
     *
     * @param string $key      Key name. Supports @dbname suffix for cross-database access.
     * @param string $fallback Value to return if the key does not exist.
     * @return string The value, or $fallback if not found.
     */
    public function get(string $key, string $fallback = ''): string
    {
        [$dbName, $entryKey] = $this->parseKey($key);
        $db = $this->database($dbName);
        $entry = $db?->get($entryKey);

        if ($entry !== null) {
            return $entry->rawValue();
        }

        return $fallback;
    }

    /**
     * Suggest the closest existing key to $key, or null when nothing is
     * near enough to help. Backs the CLI's "did you mean" line.
     *
     * Distance is Levenshtein over UTF-8 codepoints; a candidate must lie
     * within half the query's codepoint length, floored at one edit (a
     * deliberate skate tolerance — long queries get proportionally more
     * slack, short ones still clear one typo). The comparison scans every
     * non-expired key of the target database.
     */
    public function suggestionFor(string $key): ?string
    {
        [$dbName, $entryKey] = $this->parseKey($key);

        return $this->suggestSimilar($entryKey, $dbName);
    }

    /**
     * Get the full Entry object for a key (includes metadata).
     *
     * Returns null if the key does not exist, has expired, or the named
     * database has never been written.
     */
    public function entry(string $key): ?Entry
    {
        [$dbName, $entryKey] = $this->parseKey($key);
        return $this->database($dbName)?->get($entryKey);
    }

    /**
     * Render a stored value safely for a one-line terminal (TTY) listing.
     *
     * Stored values are attacker-controlled: a raw `list` would let a
     * poisoned value smuggle terminal escape sequences (cursor moves, colour
     * resets, even window-title rewrites) straight onto the operator's screen.
     * This collapses a value to a single, control-free preview line:
     *
     *  - A non-UTF-8 (binary) payload becomes a `<binary N bytes>` placeholder
     *    (N = raw byte length) instead of dumping raw bytes at the terminal.
     *  - {@see Sanitize::untrusted()} is the scrubber: it deletes C0 controls,
     *    DEL, whole ESC-introduced sequences, and — crucially — the UTF-8
     *    encodings of the C1 controls (`\xC2\x80`–`\xC2\x9F`), so a poisoned
     *    value cannot reach the terminal with U+009B (the 8-bit CSI
     *    introducer), which many terminals decode and EXECUTE even though it
     *    is well-formed UTF-8. An earlier version of this method hand-rolled
     *    a C0-only scrub and justified skipping that class by claiming
     *    `untrusted()` also passed encoded C1 through — that premise was
     *    factually wrong against the vendored sanitizer (kv-005); a bare
     *    `\xC2\x9B` pair from a synced store survived and landed verbatim on
     *    the `list` output line.
     *  - TAB, LF, CR fold to spaces (a deliberate single-line preview;
     *    `untrusted()` alone preserves them), and a final byte-class sweep
     *    re-asserts the guarantee so no residual 0x00-0x1f, 0x7f or C1 pair —
     *    e.g. printable residue of a malformed ESC sequence — survives.
     *
     * @param string $value Raw stored value (already base64-decoded for binary).
     */
    public static function sanitizeForTty(string $value): string
    {
        // Not valid UTF-8 → treat as opaque binary; never echo raw bytes.
        if (\preg_match('//u', $value) !== 1) {
            return '<binary ' . \strlen($value) . ' bytes>';
        }

        $safe = Sanitize::untrusted($value);
        $safe = \str_replace(["\r\n", "\n", "\r", "\t"], ' ', $safe);

        return \preg_replace('/[\x00-\x1f\x7f]|\xC2[\x80-\x9F]/', '', $safe) ?? $safe;
    }

    /**
     * Filter entries using fuzzy search on keys.
     *
     * Uses Smith-Waterman local alignment to score and rank entries
     * by similarity to the query string. Returns entries with score > 0,
     * sorted by score descending.
     *
     * @param string      $query  Fuzzy search query
     * @param string|null $dbName Database to search. Default = the store's default database.
     * @return list<Entry> Filtered, ranked entries
     */
    public function fuzzyFilter(string $query, ?string $dbName = null): array
    {
        if ($query === '') {
            return [];
        }

        $db = $this->database($dbName ?? $this->defaultDb);
        if ($db === null) {
            return [];
        }
        $matcher = new SmithWatermanMatcher();

        // Collect all keys and map to entries
        $keysToEntries = [];
        $keys = [];
        foreach ($db->list() as $entry) {
            if ($entry instanceof Entry) {
                $keys[] = $entry->key;
                $keysToEntries[$entry->key] = $entry;
            }
        }

        $results = $matcher->matchAll($query, $keys);

        return array_map(
            static fn($result) => $keysToEntries[$result->haystack],
            $results
        );
    }

    /**
     * Suggest a similar key using Levenshtein distance.
     *
     * @return string|null The closest matching key, or null if none are close.
     */
    private function suggestSimilar(string $key, string $dbName): ?string
    {
        $db = $this->database($dbName);
        if ($db === null) {
            return null;
        }
        $allKeys = $db->allKeys();
        if ($allKeys === []) {
            return null;
        }

        // Codepoint distance, not byte distance: PHP's levenshtein() counts
        // bytes, so a single two-byte character (ä, one CJK glyph) registers
        // as 2-3 "edits" and multibyte typos never clear the tolerance.
        // The allowance is floored at one edit: a plain half-the-length rule
        // gave 1-2 codepoint queries a zero threshold, silencing did-you-mean
        // exactly where a hint helps most (typo 'a' for live key 'at').
        $threshold = max(1, intdiv(\mb_strlen($key, 'UTF-8'), 2));

        $best = null;
        $bestDist = \PHP_INT_MAX;

        foreach ($allKeys as $candidate) {
            $dist = self::codepointLevenshtein($key, $candidate);
            if ($dist < $bestDist && $dist <= $threshold) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Levenshtein distance over UTF-8 codepoints (mb_str_split units).
     */
    private static function codepointLevenshtein(string $a, string $b): int
    {
        $s = \mb_str_split($a, 1, 'UTF-8');
        $t = \mb_str_split($b, 1, 'UTF-8');
        $m = \count($s);
        $n = \count($t);

        if ($m === 0) {
            return $n;
        }
        if ($n === 0) {
            return $m;
        }

        $prev = \range(0, $n);
        for ($i = 1; $i <= $m; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $n; $j++) {
                $cost = $s[$i - 1] === $t[$j - 1] ? 0 : 1;
                $cur[$j] = \min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }

        return $prev[$n];
    }

    /**
     * Delete one or more keys.
     *
     * Supports glob patterns. Returns the number of entries deleted.
     *
     * @param string $key Key or glob pattern (e.g. "user-*").
     */
    public function delete(string $key): int
    {
        [$dbName, $entryKey] = $this->parseKey($key);

        $db = $this->database($dbName);
        if ($db === null) {
            return 0;
        }

        // If the key contains glob characters, treat as pattern
        if ($this->isPattern($entryKey)) {
            return $db->deleteMany($entryKey);
        }

        return $db->delete($entryKey) ? 1 : 0;
    }

    /**
     * List entries in a database.
     *
     * @param string|null  $pattern   Glob pattern (* and ?). Null = all.
     * @param string       $dbName    Database name. Default = the store's default database.
     * @param bool         $reverse   Reverse sort order.
     * @param 'all'|'keys'|'values' $mode  What to yield per result.
     * @param string       $delimiter Accepted for signature compatibility only —
     *                                no library path applies it (mode='all' yields
     *                                `Entry` objects; row joining is the caller's
     *                                concern, as `bin/candy-kv` does). Slated for
     *                                v2 removal. See README API table.
     * @return \Generator<Entry|string>
     */
    public function list(
        ?string $pattern = null,
        ?string $dbName = null,
        bool $reverse = false,
        string $mode = 'all',
        string $delimiter = "\t",
    ): \Generator {
        $db = $this->database($dbName ?? $this->defaultDb);
        if ($db === null) {
            return;
        }
        yield from $db->list($pattern, $reverse, $mode, $delimiter);
    }

    /**
     * Store binary data from a file path.
     */
    public function setFile(string $key, string $filePath): Entry
    {
        // kv-019: public library door — a wrapper URL here would deserialize
        // archive metadata before the read is even attempted.
        InputPath::refuseStreamWrapper($filePath);

        // @-suppressed: the false return is handled with a proper exception;
        // the C-level warning would otherwise trip failOnWarning suites.
        $bytes = @\file_get_contents($filePath);
        if ($bytes === false) {
            throw new \RuntimeException(Lang::t('store.cannot_read', ['path' => $filePath]));
        }
        return $this->set($key, $bytes, true);
    }

    /**
     * Write binary data from a key to a file path.
     *
     * Named saveTo(): the operation writes store data OUT to disk, so the
     * direction verb replaces the legacy getFile() spelling from the
     * accessor-era rename; the store-side mutator setFile() keeps its name
     * because it is the file form of this class's canonical set() verb.
     */
    public function saveTo(string $key, string $filePath): bool
    {
        $entry = $this->entry($key);
        if ($entry === null) {
            return false;
        }
        $bytes = $entry->rawValue();
        // The payload is secret material: create/tighten to 0600 BEFORE any
        // byte is written (file_put_contents would publish the whole value
        // at umask mode, typically 0644). @-suppressed open failure is
        // reported via the bool return.
        $handle = @\fopen($filePath, 'wb');
        if ($handle === false) {
            return false;
        }
        @\chmod($filePath, 0o600);
        try {
            $written = @\fwrite($handle, $bytes);
        } finally {
            @\fclose($handle);
        }

        return $written !== false && $written === \strlen($bytes);
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::saveTo()} instead. REMOVED-IN: 2.0.0.
     */
    public function getFile(string $key, string $filePath): bool { return $this->saveTo($key, $filePath); }

    // -------------------------------------------------------------------------
    // Database management
    // -------------------------------------------------------------------------

    /**
     * Get the list of all database names.
     *
     * @return list<string>
     */
    public function listDatabases(): array
    {
        return Database::listDatabases($this->dataDir);
    }

    /**
     * Remove every already-expired entry from the default database and
     * return how many rows were dropped.
     *
     * Expired entries are invisible to get/list/count but continue to
     * occupy their rows (and disk) until pruned — this is the maintenance
     * verb the CLI `prune` command calls.
     */
    public function pruneExpired(): int
    {
        return $this->database($this->defaultDb)?->pruneExpired() ?? 0;
    }

    /**
     * Delete an entire database (all its entries).
     *
     * Returns false if the database did not exist OR if any of its files
     * (main or WAL sidecars) could not be removed — a surviving -wal file
     * still holds recoverable rows, so a partial teardown is not a delete.
     */
    public function deleteDatabase(string $dbName): bool
    {
        $path = $this->dbPath($dbName);
        if (!\file_exists($path)) {
            return false;
        }
        // Close the cached connection explicitly: relying on unset + GC to
        // release the handle before unlink() raced WAL sidecar recreation.
        if (isset($this->databases[$dbName])) {
            $this->databases[$dbName]->close();
            unset($this->databases[$dbName]);
        }
        $removedAll = true;
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (\is_file($file) && !@\unlink($file)) {
                $removedAll = false;
            }
        }

        return $removedAll && !\file_exists($path);
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /** Get the data directory path. */
    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /** Get the default database name. */
    public function defaultDatabase(): string
    {
        return $this->defaultDb;
    }

    /**
     * Execute a callback inside an atomic transaction on a named database.
     *
     * All Store operations targeting `$dbName` (e.g. `set('k@mydb', ...)`)
     * performed inside the callback share the same cached connection, so
     * they participate in the transaction. On exception the transaction is
     * rolled back and the exception propagates.
     *
     * @param callable(): mixed $fn
     * @return mixed The callback's return value.
     */
    public function transaction(string $dbName, callable $fn): mixed
    {
        // A transaction may write: the target database is created on demand.
        return $this->database($dbName, create: true)->transaction($fn);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Get an open Database handle, or null when a read targets a database
     * that has never been written.
     *
     * @param bool $create When true (write paths) the file — and the data
     *                     directory — are created on first touch. When false
     *                     (read paths) a missing file yields null and NOTHING
     *                     is created, not even the directory.
     */
    private function database(string $name, bool $create = false): ?Database
    {
        if (isset($this->databases[$name])) {
            return $this->databases[$name];
        }
        $path = $this->dbPath($name);
        if (!$create && !\is_file($path)) {
            return null;
        }

        if ($create && !\is_dir($this->dataDir)) {
            // Fail loud: a silently-skipped mkdir would defer the real error
            // to an opaque SQLite open failure on a path that does not exist.
            // mkdir is umask-filtered, so re-assert 0700 on what we created:
            // this directory holds secrets.
            if (!@\mkdir($this->dataDir, 0o700, true) && !\is_dir($this->dataDir)) {
                throw new \RuntimeException(
                    \sprintf('Cannot create the key-value data directory: %s', $this->dataDir)
                );
            }
            if (!@\chmod($this->dataDir, 0o700)) {
                throw new \RuntimeException(
                    \sprintf('Cannot set 0700 permissions on the key-value data directory: %s', $this->dataDir)
                );
            }
        }

        return $this->databases[$name] = new Database($path, $name, $create);
    }

    /**
     * Path to a database file.
     *
     * The name is validated here — the single choke point every database
     * access funnels through — so a crafted name ("..", "foo/bar",
     * "example.com") can never traverse outside the data directory.
     */
    private function dbPath(string $name): string
    {
        if ($name === '' || \preg_match('/^[A-Za-z0-9_-]+$/', $name) !== 1) {
            throw new \InvalidArgumentException(
                "Invalid database name '{$name}': only letters, digits, '-' and '_' are allowed."
            );
        }
        return $this->dataDir . '/' . $name . '.db';
    }

    /**
     * Parse "key@dbname" into [dbName, entryKey].
     * If no @ suffix, returns [defaultDb, key].
     *
     * An entry key may contain a literal @ when spelled "\@": the database
     * separator is the rightmost UNESCAPED @. After the split, the entry
     * part's "\@" sequences decode to "@", and any remaining unescaped @
     * ("a@b@c") is ambiguous and refused. Database names cannot contain
     * escape sequences (they are validated to [A-Za-z0-9_-]+ by dbPath).
     *
     * @return array{0: string, 1: string}
     */
    private function parseKey(string $key): array
    {
        return self::splitKey($key, $this->defaultDb);
    }

    /**
     * The key-spelling law as a pure function: split an import/export key
     * into [database, entry key] using the same rightmost-unescaped-'@'
     * rule Store::set() routes by. Importers derive their per-database
     * transaction roster through HERE, so a key spelled `a\@b` (a literal
     * at-sign) can never be misread as naming a second database — the
     * naive strrpos() version of this walk used to route `{"k@x":5,...}`
     * imports through a roster that silently missed `x`.
     *
     * @return array{0: string, 1: string} [database name, decoded entry key]
     * @throws \InvalidArgumentException when the entry part keeps an
     *                                   unescaped '@' (ambiguous "a@b@c").
     */
    public static function splitKey(string $key, string $defaultDb): array
    {
        // Rightmost unescaped '@' is the separator.
        $at = false;
        for ($i = \strlen($key) - 1; $i > 0; $i--) {
            if ($key[$i] === '@' && $key[$i - 1] !== '\\') {
                $at = $i;
                break;
            }
        }
        if ($at === false) {
            $decoded = \str_replace('\\@', '@', $key);
            self::refuseUnroundTrippableKey($decoded);
            return [$defaultDb, $decoded];
        }

        $dbName = \substr($key, $at + 1);
        $entryKey = \substr($key, 0, $at);

        // Only ONE unescaped @ is meaningful; a leftover in the entry key
        // ("a@b@c") is ambiguous and always a caller bug. An escaped \@ is
        // not that leftover: re-apply the separator predicate itself (same
        // positions >= 1 scan, same single-character backslash lookback)
        // so "a\@b@db" routes to db + key "a@b" exactly as the README law
        // spells it. A leading @ stays literal here too, mirroring the
        // no-separator path.
        for ($i = \strlen($entryKey) - 1; $i > 0; $i--) {
            if ($entryKey[$i] === '@' && $entryKey[$i - 1] !== '\\') {
                throw new \InvalidArgumentException(
                    "Entry key '{$entryKey}' cannot contain @ (reserved as the database separator; spell a literal one '\\@')."
                );
            }
        }

        // "default@foo" means db=foo, key=default
        $decoded = \str_replace('\\@', '@', $entryKey);
        self::refuseUnroundTrippableKey($decoded);
        return [$dbName, $decoded];
    }

    /**
     * G3 minimum law (kv-007): a decoded entry key still carrying a backslash
     * — terminating the key, or glued to an '@' produced by a doubled
     * backslash — cannot round-trip through the qualified "key@db" spelling,
     * because the grammar has no self-escape for the backslash itself. The
     * old silent rewrite: key "bs\" in db "x" exported as "bs\@x", the scan
     * read the separator as escaped, and the value reimported renamed into
     * the DEFAULT database at exit 0. Rather than guess, every routing door
     * (set/get/delete/import) refuses these spellings loudly; closing the
     * grammar with a real "\\" escape is the declined alternative (smaller
     * blast radius wins — the README states the law).
     *
     * @throws \InvalidArgumentException usage-class, exit 2 at the CLI.
     */
    private static function refuseUnroundTrippableKey(string $entryKey): void
    {
        if ($entryKey === '') {
            return;
        }
        if (\str_ends_with($entryKey, '\\') || \str_contains($entryKey, '\\@')) {
            throw new \InvalidArgumentException(
                "Entry key '{$entryKey}' cannot round-trip: the key-grammar has no backslash escape, so keys may not end with a backslash or carry a doubled-backslash @ spelling (kv-007)."
            );
        }
    }

    /**
     * kv-043: true when $key carries a `*` or `?` byte that is NOT spelled
     * with the pattern engine's escape. Same single-backslash lookback the
     * '@' split applies — the grammar has no `\\` self-escape (kv-007), so
     * "escaped" always means "one backslash immediately before it".
     */
    public static function hasRawGlob(string $key): bool
    {
        for ($i = 0, $len = \strlen($key); $i < $len; $i++) {
            if (($key[$i] === '*' || $key[$i] === '?') && ($i === 0 || $key[$i - 1] !== '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * kv-043: the pattern engine's unescape as a pure key mapping — drop the
     * backslash that spells a literal `*`/`?` (`\*` → `*`, `\?` → `?`).
     * Every other backslash stays exactly as typed: the grammar has no
     * self-escape, so `\<x>` for other x is literal text, mirroring
     * Database::globToLike's "a backslash before anything else stays
     * literal" law. This is what makes a `get` arg spelled `ra\*wt` name
     * the key `ra*wt` instead of dying at the pattern gate.
     */
    public static function unescapeGlobKey(string $key): string
    {
        return \preg_replace('/\\\\([*?])/', '$1', $key) ?? $key;
    }

    /**
     * Resolve the data directory: $XDG_CONFIG_HOME/skate, then
     * $HOME/.config/skate, then the passwd entry's home directory.
     *
     * XDG_CONFIG_HOME and HOME must be ABSOLUTE paths (the XDG base
     * directory specification says so): a relative value would silently
     * relocate secrets under the current working directory, which changes
     * every time the caller changes directory.
     *
     * There is deliberately NO fallback to the library install tree:
     * a HOME-less process must fail loudly, not write secrets into a
     * world-writable-shared vendor directory.
     *
     * @throws \RuntimeException when no home directory can be determined
     *                           or a configured home is relative.
     */
    public static function resolveDataDir(): string
    {
        $xdg = \getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '') {
            self::assertAbsolutePath($xdg, 'XDG_CONFIG_HOME');

            return \rtrim($xdg, '/') . '/skate';
        }

        $home = \getenv('HOME');
        if ($home === false || $home === '') {
            $home = self::homeFromPasswd();
        }
        if ($home === null || $home === '') {
            throw new \RuntimeException(
                'Cannot determine the data directory: set XDG_CONFIG_HOME or HOME.'
            );
        }
        if (\is_string($home)) {
            self::assertAbsolutePath($home, 'HOME');
        }

        return \rtrim($home, '/') . '/' . self::DEFAULT_SUBDIR;
    }

    /**
     * Refuse a relative "home" with a message naming the offending variable.
     */
    private static function assertAbsolutePath(string $path, string $variable): void
    {
        if ($path[0] !== '/') {
            throw new \RuntimeException(
                \sprintf('%s must be an absolute path, got "%s" (a relative home would move the store with the working directory).', $variable, $path)
            );
        }
    }

    /**
     * Read the effective user's home from the passwd database.
     */
    private static function homeFromPasswd(): ?string
    {
        if (!\function_exists('posix_getpwuid') || !\function_exists('posix_geteuid')) {
            return null;
        }
        $entry = @\posix_getpwuid(\posix_geteuid());

        return (\is_array($entry) && isset($entry['dir']) && $entry['dir'] !== '' && $entry['dir'] !== '/')
            ? $entry['dir']
            : null;
    }

    /**
     * Check if a key string contains glob wildcard characters.
     */
    private function isPattern(string $key): bool
    {
        return \strcspn($key, '*?') < \strlen($key);
    }
}
