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
        $bytes = \file_get_contents($filePath);
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
        return \file_put_contents($filePath, $bytes) !== false;
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
     */
    private function dbPath(string $name): string
    {
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
