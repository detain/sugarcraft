<?php

declare(strict_types=1);

/**
 * SugarSkate — a personal key/value store with multi-database support.
 *
 * Port of charmbracelet/skate providing:
 * - Set / get / delete operations on named keys
 * - Multiple independent databases (named with @dbname suffix or explicit db arg)
 * - Binary data storage (base64-encoded internally)
 * - Glob pattern matching on keys (list / delete)
 * - Ordered listing (forward / reverse) with keys-only / values-only / all modes
 * - SQLite-backed persistent storage
 *
 * @see https://github.com/charmbracelet/skate
 */
namespace SugarCraft\Skate;

use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Skate\Lang;

/**
 * Main entry point for the Skate key/value store.
 *
 * Provides a store-wide API that routes operations to the appropriate
 * database. Each distinct database maps to its own SQLite file in the
 * skate data directory (default: ~/.config/skate/).
 *
 * @example
 * ```php
 * $skate = new Store();
 * $skate->set('key', 'value');
 * echo $skate->get('key');
 * foreach ($skate->list() as $entry) { ... }
 * ```
 */
final class Store
{
    /** Default data directory relative to home. */
    public const DEFAULT_SUBDIR = '.config/skate';

    /** @var array<string, Database> Cached open database connections. */
    private array $databases = [];

    /** Base directory where database files live. */
    private string $dataDir;

    /** Default database name. */
    private string $defaultDb;

    /**
     * Create a new Store.
     *
     * @param string|null $dataDir  Override the data directory path.
     *                              Defaults to $XDG_CONFIG_HOME/skate or ~/.config/skate.
     * @param string      $defaultDb Default database name (used when no @db suffix is given).
     */
    public function __construct(?string $dataDir = null, string $defaultDb = 'default')
    {
        $this->dataDir = $dataDir ?? $this->defaultDataDir();
        $this->defaultDb = $defaultDb;

        if (!\is_dir($this->dataDir)) {
            \mkdir($this->dataDir, 0o700, true);
        }

        $this->database($this->defaultDb);
    }

    // -------------------------------------------------------------------------
    // Core operations
    // -------------------------------------------------------------------------

    /**
     * Set a value.
     *
     * @param string $key    Entry key. May include @dbname to target a specific database.
     *                       E.g. "token@passwords" sets "token" in the "passwords" database.
     * @param string $value  The value to store. For binary data use Entry::binary().
     * @param bool   $binary Whether to treat $value as base64-encoded binary data.
     * @param int|null $ttlSeconds If > 0, entry expires after this many seconds.
     */
    public function set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null): Entry
    {
        [$dbName, $entryKey] = $this->parseKey($key);
        $stored = $binary ? \base64_encode($value) : $value;
        return $this->database($dbName)->set($entryKey, $stored, $binary, $ttlSeconds);
    }

    /**
     * Set a value that automatically expires after $ttlSeconds.
     *
     * @param string $key         Entry key (supports @dbname suffix).
     * @param mixed  $value       The value to store (cast to string).
     * @param int    $ttlSeconds  Seconds until the entry expires.
     */
    public function setWithTtl(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }
        $this->set($key, (string) $value, false, $ttlSeconds);
    }

    /**
     * Get a value by key, with levenshtein-based typo suggestions on miss.
     *
     * @param string $key      Key name. Supports @dbname suffix for cross-database access.
     * @param string $fallback Value to return if the key does not exist.
     * @return string The value, or $fallback if not found.
     */
    public function get(string $key, string $fallback = ''): string
    {
        [$dbName, $entryKey] = $this->parseKey($key);
        $entry = $this->database($dbName)->get($entryKey);

        if ($entry !== null) {
            return $entry->rawValue();
        }

        $suggestion = $this->suggestSimilar($entryKey, $dbName);
        if ($suggestion !== null) {
            // Emit suggestion to stderr so it doesn't pollute stdout
            \fwrite(STDERR, "key not found; did you mean '{$suggestion}'?" . \PHP_EOL);
        }

        return $fallback;
    }

    /**
     * Get the full Entry object for a key (includes metadata).
     *
     * Returns null if the key does not exist or has expired.
     */
    public function entry(string $key): ?Entry
    {
        [$dbName, $entryKey] = $this->parseKey($key);
        return $this->database($dbName)->get($entryKey);
    }

    /**
     * Render a stored value safely for a one-line terminal (TTY) listing.
     *
     * Stored values are attacker-controlled: a raw `list` would let a
     * poisoned value smuggle terminal escape sequences (cursor moves, colour
     * resets, even window-title rewrites) straight onto the operator's screen.
     * This collapses a value to a single, control-free line:
     *
     *  - A non-UTF-8 (binary) payload becomes a `<binary N bytes>` placeholder
     *    (N = raw byte length) instead of dumping raw bytes at the terminal.
     *  - C0 control bytes are stripped and \n / \r / \t fold to spaces via
     *    {@see Sanitize::controlChars()} — a deliberate single-line preview.
     *  - ESC (0x1b) and DEL (0x7f) are additionally removed here: Sanitize
     *    preserves ESC for trusted SGR output, but a *stored* value must never
     *    be trusted to emit escape sequences.
     *
     * @param string $value Raw stored value (already base64-decoded for binary).
     */
    public static function sanitizeForTty(string $value): string
    {
        // Not valid UTF-8 → treat as opaque binary; never echo raw bytes.
        if (\preg_match('//u', $value) !== 1) {
            return '<binary ' . \strlen($value) . ' bytes>';
        }

        $safe = Sanitize::controlChars($value);
        return \preg_replace('/[\x1b\x7f]/', '', $safe) ?? $safe;
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
        $allKeys = $this->database($dbName)->allKeys();
        if ($allKeys === []) {
            return null;
        }

        $best = null;
        $bestDist = \PHP_INT_MAX;

        foreach ($allKeys as $candidate) {
            $dist = \levenshtein($key, $candidate);
            if ($dist < $bestDist && $dist <= (int) (\strlen($key) / 2)) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }

        return $best;
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

        // If the key contains glob characters, treat as pattern
        if ($this->isPattern($entryKey)) {
            return $this->database($dbName)->deleteMany($entryKey);
        }

        return $this->database($dbName)->delete($entryKey) ? 1 : 0;
    }

    /**
     * List entries in a database.
     *
     * @param string|null  $pattern   Glob pattern (* and ?). Null = all.
     * @param string       $dbName    Database name. Default = the store's default database.
     * @param bool         $reverse   Reverse sort order.
     * @param 'all'|'keys'|'values' $mode  What to yield per result.
     * @param string       $delimiter Delimiter between key and value when mode='all'.
     * @return \Generator<Entry|string>
     */
    public function list(
        ?string $pattern = null,
        string $dbName = null,
        bool $reverse = false,
        string $mode = 'all',
        string $delimiter = "\t",
    ): \Generator {
        $db = $this->database($dbName ?? $this->defaultDb);
        yield from $db->list($pattern, $reverse, $mode, $delimiter);
    }

    /**
     * Store binary data from a file path.
     */
    public function setFile(string $key, string $filePath): Entry
    {
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
     */
    public function getFile(string $key, string $filePath): bool
    {
        $entry = $this->entry($key);
        if ($entry === null) {
            return false;
        }
        $bytes = $entry->rawValue();
        // @-suppressed: unwritable destination is reported via the bool return.
        return @\file_put_contents($filePath, $bytes) !== false;
    }

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
     * Delete an entire database (all its entries).
     */
    public function deleteDatabase(string $dbName): bool
    {
        $path = $this->dbPath($dbName);
        if (!\file_exists($path)) {
            return false;
        }
        unset($this->databases[$dbName]);
        return \unlink($path) !== false;
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
        return $this->database($dbName)->transaction($fn);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Get or open a Database instance.
     */
    private function database(string $name): Database
    {
        if (!isset($this->databases[$name])) {
            $this->databases[$name] = new Database($this->dbPath($name), $name);
        }
        return $this->databases[$name];
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
     * @return array{0: string, 1: string}
     */
    private function parseKey(string $key): array
    {
        $at = \strrpos($key, '@');
        if ($at === false) {
            return [$this->defaultDb, $key];
        }

        $dbName = \substr($key, $at + 1);
        $entryKey = \substr($key, 0, $at);

        // Only ONE @ separator is meaningful; a leftover @ in the entry key
        // ("a@b@c") is ambiguous and always a caller bug.
        if (\strpos($entryKey, '@') !== false) {
            throw new \InvalidArgumentException(
                "Entry key '{$entryKey}' cannot contain @ (reserved as the database separator)."
            );
        }

        // "default@foo" means db=foo, key=default
        return [$dbName, $entryKey];
    }

    /**
     * Determine the default data directory.
     */
    private function defaultDataDir(): string
    {
        $xdg = \getenv('XDG_CONFIG_HOME');
        if ($xdg !== false && $xdg !== '') {
            $base = $xdg;
        } else {
            $home = \getenv('HOME') ?: \dirname(__DIR__, 2);
            $base = $home . '/.config';
        }
        return $base . '/skate';
    }

    /**
     * Check if a key string contains glob wildcard characters.
     */
    private function isPattern(string $key): bool
    {
        return \strcspn($key, '*?') < \strlen($key);
    }
}
