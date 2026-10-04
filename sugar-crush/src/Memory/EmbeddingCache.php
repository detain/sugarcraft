<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Support\HomeDirectory;

/**
 * A cache of embedding vectors keyed by (model, text), so the memory recall
 * (roadmap 5.3-2) pays the embedding endpoint once per note BODY rather than
 * once per note per turn.
 *
 * WHY A CACHE AT ALL. The vector leg of {@see HybridMemoryRanker} compares the
 * user's latest message against every note. Without a cache each turn would
 * re-embed the whole store; with it a turn embeds the query and whatever note
 * text is new or edited since it was last seen. OpenClaw keeps the same kind
 * of cache (50,000 entries) beside its vectors for the same reason.
 *
 * KEYED BY CONTENT, NOT BY NOTE. The key is the model name plus a hash of the
 * exact text embedded, so an edited note is a miss by construction (no mtime
 * rule to get wrong), a note moved between scopes is a hit, and two models
 * never share a vector — a vector is only comparable to vectors of its own
 * model.
 *
 * WHERE IT LIVES: `~/.sugar-crush/cache/embeddings.sqlite` ({@see defaultPath()}),
 * owner-only, never inside a repository. A process with no owned home, or a
 * PHP build without pdo_sqlite, keeps the vectors in memory for its own life
 * ({@see inMemory()}) — slower across sessions, never wrong.
 *
 * NO ON-DISK CONNECTION OUTLIVES A CALL, as in {@see MemorySearchIndex}: the
 * parent process forks engine turns, and an SQLite handle carried across
 * fork() is one SQLite tells callers never to share.
 *
 * A CACHE, SO NOTHING HERE IS FATAL. A damaged file is deleted and the
 * vectors are recomputed; a lock held too long falls through to the
 * embedder. Only the embedder's own failure propagates — the caller
 * ({@see HybridMemoryRanker}) is what decides that a failed vector leg means
 * keyword-only ranking.
 *
 * BOUNDED: at most {@see MAX_ENTRIES} vectors, least recently used out.
 */
final class EmbeddingCache
{
    /** The cache file's name under `~/.sugar-crush/cache/`. */
    public const FILENAME = 'embeddings.sqlite';

    /** Most vectors kept; the least recently used go first past it. */
    public const MAX_ENTRIES = 50000;

    /** Texts per embedder call: one request per batch of misses. */
    public const BATCH_SIZE = 64;

    /** How long a lookup waits on another process's write lock, in seconds. */
    private const BUSY_TIMEOUT_SECONDS = 2;

    /** SQLITE_CORRUPT and SQLITE_NOTADB: a cache file worth deleting. */
    private const DAMAGED_CODES = [11, 26];

    /** @var array<string, list<float>> key => vector, for the in-memory cache */
    private array $memory = [];

    private function __construct(private readonly ?string $file)
    {
    }

    /** A cache persisted in $file, created owner-only on first use. */
    public static function at(string $file): self
    {
        return new self($file);
    }

    /** A cache held in this process only. */
    public static function inMemory(): self
    {
        return new self(null);
    }

    /**
     * The cache at {@see defaultPath()}, or an in-memory one when no owned
     * home directory exists.
     */
    public static function new(?string $home = null): self
    {
        $path = self::defaultPath($home);

        return $path === null ? self::inMemory() : self::at($path);
    }

    /**
     * `<home>/.sugar-crush/cache/embeddings.sqlite`, or null without a home
     * this process owns.
     */
    public static function defaultPath(?string $home = null): ?string
    {
        $home ??= HomeDirectory::owned();
        if ($home === null || $home === '') {
            return null;
        }

        return rtrim($home, '/') . '/.sugar-crush/cache/' . self::FILENAME;
    }

    /** The cache file, or null for an in-memory cache. */
    public function file(): ?string
    {
        return $this->file;
    }

    /**
     * One vector per text, in $texts order: cached ones as stored, the rest
     * from $embed — called once per {@see BATCH_SIZE} misses — and stored.
     *
     * @param list<string> $texts
     * @param \Closure(list<string>): list<list<float>> $embed the embedding
     *        call; one vector per input, in input order
     *
     * @return list<list<float>>
     *
     * @throws \RuntimeException when $embed fails or answers the wrong shape
     */
    public function vectors(string $model, array $texts, \Closure $embed): array
    {
        $keys = [];
        foreach ($texts as $i => $text) {
            $keys[$i] = self::key($model, $text);
        }

        $found = $this->lookup(array_values(array_unique($keys)));

        $missing = [];
        foreach ($keys as $i => $key) {
            if (!isset($found[$key]) && !isset($missing[$key])) {
                $missing[$key] = $texts[$i];
            }
        }

        $computed = [];
        foreach (array_chunk($missing, self::BATCH_SIZE, true) as $batch) {
            $vectors = $embed(array_values($batch));
            if (!\is_array($vectors) || \count($vectors) !== \count($batch)) {
                throw new \RuntimeException(sprintf(
                    'the embedding call answered %s vectors for %d inputs',
                    \is_array($vectors) ? (string) \count($vectors) : 'no',
                    \count($batch),
                ));
            }
            foreach (array_keys($batch) as $offset => $key) {
                $computed[$key] = self::vector($vectors[$offset] ?? null);
            }
        }

        if ($computed !== []) {
            $this->store($computed);
        }

        $out = [];
        foreach ($keys as $key) {
            $out[] = $found[$key] ?? $computed[$key];
        }

        return $out;
    }

    /** How many vectors the cache holds. */
    public function count(): int
    {
        if ($this->file === null) {
            return \count($this->memory);
        }

        $db = $this->open();
        if ($db === null) {
            return 0;
        }

        try {
            return (int) $db->query('SELECT COUNT(*) FROM vectors')->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The cache key of $text embedded by $model. */
    public static function key(string $model, string $text): string
    {
        return hash('xxh128', $model . "\0" . $text);
    }

    /**
     * A vector as a list of finite floats.
     *
     * @throws \RuntimeException for anything else
     *
     * @return list<float>
     */
    private static function vector(mixed $raw): array
    {
        if (!\is_array($raw) || $raw === []) {
            throw new \RuntimeException('the embedding call answered an empty or non-list vector');
        }

        $vector = [];
        foreach ($raw as $value) {
            if (!\is_int($value) && !\is_float($value)) {
                throw new \RuntimeException('the embedding call answered a non-numeric vector component');
            }
            $value = (float) $value;
            if (!is_finite($value)) {
                throw new \RuntimeException('the embedding call answered a non-finite vector component');
            }
            $vector[] = $value;
        }

        return $vector;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, list<float>>
     */
    private function lookup(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        if ($this->file === null) {
            return array_intersect_key($this->memory, array_flip($keys));
        }

        $db = $this->open();
        if ($db === null) {
            return array_intersect_key($this->memory, array_flip($keys));
        }

        $found = [];
        try {
            foreach (array_chunk($keys, 500) as $chunk) {
                $stmt = $db->prepare(
                    'SELECT key, vector FROM vectors WHERE key IN (' . implode(',', array_fill(0, \count($chunk), '?')) . ')'
                );
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as [$key, $blob]) {
                    $vector = self::unpack((string) $blob);
                    if ($vector !== null) {
                        $found[(string) $key] = $vector;
                    }
                }
            }

            if ($found !== []) {
                $touch = $db->prepare('UPDATE vectors SET used_at = ? WHERE key = ?');
                $now = time();
                $db->exec('BEGIN');
                foreach (array_keys($found) as $key) {
                    $touch->execute([$now, $key]);
                }
                $db->exec('COMMIT');
            }
        } catch (\Throwable $e) {
            $this->discardIfDamaged($e);
        }

        return $found;
    }

    /** @param array<string, list<float>> $vectors */
    private function store(array $vectors): void
    {
        $db = $this->file === null ? null : $this->open();
        if ($db === null) {
            $this->memory = array_merge($this->memory, $vectors);
            if (\count($this->memory) > self::MAX_ENTRIES) {
                $this->memory = \array_slice($this->memory, -self::MAX_ENTRIES, null, true);
            }

            return;
        }

        try {
            $now = time();
            $insert = $db->prepare('INSERT OR REPLACE INTO vectors (key, vector, used_at) VALUES (?, ?, ?)');
            $db->exec('BEGIN IMMEDIATE');
            foreach ($vectors as $key => $vector) {
                $insert->execute([$key, pack('g*', ...$vector), $now]);
            }
            $db->exec(
                'DELETE FROM vectors WHERE key IN (SELECT key FROM vectors ORDER BY used_at DESC, key LIMIT -1 OFFSET '
                . self::MAX_ENTRIES . ')'
            );
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            $this->discardIfDamaged($e);
        }
    }

    /** @return list<float>|null */
    private static function unpack(string $blob): ?array
    {
        if ($blob === '' || \strlen($blob) % 4 !== 0) {
            return null;
        }
        $values = unpack('g*', $blob);

        return $values === false ? null : array_values(array_map('floatval', $values));
    }

    private function open(): ?\PDO
    {
        if ($this->file === null || !\extension_loaded('pdo_sqlite')) {
            return null;
        }

        $dir = \dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $previousUmask = umask(0077);
        try {
            $db = new \PDO('sqlite:' . $this->file);
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(\PDO::ATTR_TIMEOUT, self::BUSY_TIMEOUT_SECONDS);
            // A derived cache: a crash costs a recompute, never a note.
            $db->exec('PRAGMA synchronous = OFF');
            $db->exec(
                'CREATE TABLE IF NOT EXISTS vectors ('
                . 'key TEXT PRIMARY KEY, vector BLOB NOT NULL, used_at INTEGER NOT NULL)'
            );
        } catch (\Throwable $e) {
            $this->discardIfDamaged($e);

            return null;
        } finally {
            umask($previousUmask);
        }
        @chmod($this->file, 0600);

        return $db;
    }

    private function discardIfDamaged(\Throwable $e): void
    {
        $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        if ($this->file !== null && \in_array($code, self::DAMAGED_CODES, true)) {
            @unlink($this->file);
        }
    }
}
