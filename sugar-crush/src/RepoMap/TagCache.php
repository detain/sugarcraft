<?php

declare(strict_types=1);

namespace SugarCraft\Crush\RepoMap;

use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Support\HomeDirectory;

/**
 * A per-project SQLite cache of {@see Tag}s, keyed on a file's root-relative
 * path plus its mtime and size. An unchanged file is never tokenized twice.
 *
 * It mirrors Aider's `RepoMap.TAGS_CACHE` (a diskcache keyed on filename and
 * mtime). Size is added to the key because a same-second rewrite keeps the
 * mtime, and an editor that preserves mtimes (`cp -p`, a git checkout of the
 * same second) would otherwise serve the old tags. Measured on sugar-crush's
 * own `src/`: tokenizing every file takes about 1 s for about 43,000 tags,
 * which is why a repo map recomputed on every turn needs this.
 *
 * WHERE IT LIVES. {@see defaultPath()} puts it under the user's home, at
 * `~/.sugar-crush/cache/repomap/<projectKey>.sqlite`, keyed by
 * {@see MemoryStore::projectKeyFor()}. It is never written inside the
 * repository, so it is never committed and never trips a project's
 * file-protection rules.
 *
 * A CACHE, SO CORRUPTION IS NOT AN ERROR. A database that cannot be opened
 * or read as SQLite is deleted and rebuilt once. A second failure throws,
 * because a cache that cannot even be created points at a permissions
 * problem the caller should report.
 *
 * WHICH CODE MADE A ROW IS PART OF THE ROW (roadmap 5.5-5). Each file is
 * stored with its PRODUCER stamp — {@see PHP_PRODUCER} for
 * {@see PhpSymbolExtractor}, {@see CtagsSymbolExtractor::cacheStamp()} for
 * universal-ctags (the extractor's rule version plus the binary's version
 * banner) — and {@see get()} answers only for the stamp the caller names. So
 * a ctags upgrade or a changed reference scan re-extracts exactly the files
 * that producer tagged, and a PHP extractor change exactly the PHP files.
 * {@see SCHEMA_VERSION} (with the PHP {@see EXTRACTOR_VERSION}) still wipes
 * everything when the storage layout itself changes.
 *
 * ONE ROW PER FILE. A file's tags are one compact JSON list in its row, not
 * one row per tag: sugar-crush's own tree is ~1,700 PHP files and ~309,000
 * tags, and a row per tag (plus an index on the name) made the first map
 * spend longer writing the cache than tokenizing the files. The same layout
 * makes a warm read one SELECT per file.
 *
 * IT ALSO KEEPS FINISHED MAPS ({@see rendered()} / {@see storeRendered()}): a
 * caller that can name everything a map depends on — the system prompt's
 * {@see \SugarCraft\Crush\Context\SymbolMapBlock} keys it by every mapped
 * file's path, mtime, size and producer — reuses the ranked, rendered text
 * instead of rebuilding the graph and re-running PageRank. At most
 * {@see MAX_RENDERED} are kept, oldest first out.
 */
final class TagCache
{
    /**
     * Bump when {@see PhpSymbolExtractor}'s output changes for the same
     * input; it is the PHP producer's stamp ({@see PHP_PRODUCER}).
     */
    public const EXTRACTOR_VERSION = 1;

    /** Bump when the tables change shape; a mismatch wipes every row. */
    public const SCHEMA_VERSION = 2;

    /** The producer stamp of {@see PhpSymbolExtractor}'s rows. */
    public const PHP_PRODUCER = 'php/' . self::EXTRACTOR_VERSION;

    /** Most finished maps kept by {@see storeRendered()}. */
    public const MAX_RENDERED = 8;

    /** Tag kinds as stored: one character each. */
    private const KIND_CODES = [Tag::KIND_DEFINITION => 'd', Tag::KIND_REFERENCE => 'r'];

    private function __construct(
        private readonly \PDO $pdo,
        private readonly string $path,
    ) {}

    /**
     * Open (creating if needed) the cache at $dbPath. The file and its WAL
     * sidecars are owner-only (0600).
     *
     * @throws \RuntimeException when the database can be neither opened nor rebuilt.
     */
    public static function open(string $dbPath): self
    {
        $dir = \dirname($dbPath);
        if ($dbPath !== ':memory:' && !\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            throw new \RuntimeException("Cannot create the repo-map cache directory {$dir}.");
        }

        try {
            return new self(self::connect($dbPath), $dbPath);
        } catch (\PDOException) {
            // Not a database (truncated, overwritten, half-written): it is
            // only a cache, so start over once.
            foreach (['', '-wal', '-shm'] as $suffix) {
                @\unlink($dbPath . $suffix);
            }
        }

        try {
            return new self(self::connect($dbPath), $dbPath);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Cannot open the repo-map cache {$dbPath}: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * `<home>/.sugar-crush/cache/repomap/<projectKey>.sqlite`, or null when
     * no home directory this process owns can be established. Without a home
     * there is nowhere outside the repository to put the cache, and writing
     * it into the repository is exactly what this design avoids.
     */
    public static function defaultPath(string $projectRoot, ?string $home = null): ?string
    {
        $home ??= HomeDirectory::owned();
        if ($home === null || $home === '') {
            return null;
        }

        return \rtrim($home, '/') . '/.sugar-crush/cache/repomap/' . MemoryStore::projectKeyFor($projectRoot) . '.sqlite';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The tags for $relPath when the cached entry still matches $mtime, $size
     * and the producer, or null for a miss.
     *
     * @param ?string $producer the stamp the row must carry; null is {@see PHP_PRODUCER}
     * @return list<Tag>|null
     */
    public function get(string $relPath, int $mtime, int $size, ?string $producer = null): ?array
    {
        $rows = $this->row($relPath, $mtime, $size, $producer);
        if ($rows === null) {
            return null;
        }

        $tags = [];
        foreach ($rows as $row) {
            $tags[] = self::tag($relPath, $row);
        }

        return $tags;
    }

    /**
     * {@see get()} reduced to what a {@see SymbolGraph} reads: the
     * definition tags, and how often each identifier is referenced. A file's
     * reference tags are the bulk of every cache (nine in ten on this tree)
     * and the graph needs only their counts, so a map of a large checkout
     * never holds them as objects.
     *
     * @return array{definitions: list<Tag>, references: array<string, int>}|null
     */
    public function summary(string $relPath, int $mtime, int $size, ?string $producer = null): ?array
    {
        $rows = $this->row($relPath, $mtime, $size, $producer);
        if ($rows === null) {
            return null;
        }

        $definitions = [];
        $references = [];
        foreach ($rows as $row) {
            if (($row[0] ?? null) === 'r') {
                $name = (string) ($row[2] ?? '');
                $references[$name] = ($references[$name] ?? 0) + 1;
            } else {
                $definitions[] = self::tag($relPath, $row);
            }
        }

        return ['definitions' => $definitions, 'references' => $references];
    }

    /**
     * Replace $relPath's entry with $tags, stamped with $mtime, $size and the
     * producer, so a reader never sees half a file's tags.
     *
     * @param list<Tag> $tags
     * @param ?string   $producer null is {@see PHP_PRODUCER}
     */
    public function put(string $relPath, int $mtime, int $size, array $tags, ?string $producer = null): void
    {
        $rows = [];
        foreach (\array_values($tags) as $tag) {
            $rows[] = $tag->isDefinition()
                ? [self::KIND_CODES[Tag::KIND_DEFINITION], $tag->line, $tag->name, $tag->type, $tag->scope]
                : [self::KIND_CODES[Tag::KIND_REFERENCE], $tag->line, $tag->name];
        }

        $this->pdo->prepare('INSERT OR REPLACE INTO files (path, mtime, size, producer, count, tags) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $relPath,
                $mtime,
                $size,
                $producer ?? self::PHP_PRODUCER,
                \count($rows),
                \json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
    }

    /**
     * The tags for one file, from the cache when its mtime and size still
     * match, otherwise extracted with $extractor and stored. A file that no
     * longer exists is forgotten and yields [].
     *
     * @return list<Tag>
     */
    public function tagsFor(string $absPath, string $relPath, PhpSymbolExtractor $extractor): array
    {
        \clearstatcache(true, $absPath);
        $stat = @\stat($absPath);
        if ($stat === false) {
            $this->forget($relPath);

            return [];
        }

        $cached = $this->get($relPath, (int) $stat['mtime'], (int) $stat['size']);
        if ($cached !== null) {
            return $cached;
        }

        $tags = $extractor->extractFile($absPath, $relPath);
        $this->put($relPath, (int) $stat['mtime'], (int) $stat['size'], $tags);

        return $tags;
    }

    public function forget(string $relPath): void
    {
        $this->deleteRows($relPath);
    }

    /**
     * Drop every entry whose path is not in $livePaths (files deleted or
     * renamed since they were cached). Returns how many entries went.
     *
     * @param list<string> $livePaths
     */
    public function prune(array $livePaths): int
    {
        $live = \array_flip($livePaths);
        $gone = [];
        foreach ($this->pdo->query('SELECT path FROM files')->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            if (!isset($live[$path])) {
                $gone[] = (string) $path;
            }
        }
        if ($gone === []) {
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($gone as $path) {
                $this->deleteRows($path);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return \count($gone);
    }

    /**
     * Run $work inside one transaction, so a map that stores hundreds of
     * extracted files commits once rather than once per file.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    public function transaction(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** The finished map stored under $key, or null. */
    public function rendered(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM renders WHERE key = ?');
        $stmt->execute([$key]);
        $body = $stmt->fetchColumn();

        return \is_string($body) ? $body : null;
    }

    /** Keep $body as the finished map for $key; the oldest past {@see MAX_RENDERED} go. */
    public function storeRendered(string $key, string $body): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO renders (key, body, stored_at) VALUES (?, ?, ?)')
            ->execute([$key, $body, (int) (\microtime(true) * 1_000_000)]);
        $this->pdo->exec(
            'DELETE FROM renders WHERE key IN (SELECT key FROM renders ORDER BY stored_at DESC, rowid DESC LIMIT -1 OFFSET '
            . self::MAX_RENDERED . ')'
        );
    }

    /** @return array{files:int,tags:int} */
    public function stats(): array
    {
        return [
            'files' => (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'tags' => (int) $this->pdo->query('SELECT COALESCE(SUM(count), 0) FROM files')->fetchColumn(),
        ];
    }

    /**
     * The stored tag rows of $relPath when the entry matches, else null.
     *
     * @return list<list<mixed>>|null
     */
    private function row(string $relPath, int $mtime, int $size, ?string $producer): ?array
    {
        $stmt = $this->pdo->prepare('SELECT mtime, size, producer, tags FROM files WHERE path = ?');
        $stmt->execute([$relPath]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['mtime'] !== $mtime || (int) $row['size'] !== $size
            || (string) $row['producer'] !== ($producer ?? self::PHP_PRODUCER)) {
            return null;
        }

        $rows = \json_decode((string) $row['tags'], true);

        return \is_array($rows) ? \array_values(\array_filter($rows, 'is_array')) : null;
    }

    /** @param list<mixed> $row */
    private static function tag(string $relPath, array $row): Tag
    {
        return ($row[0] ?? null) === 'r'
            ? Tag::reference($relPath, (int) ($row[1] ?? 1), (string) ($row[2] ?? ''))
            : Tag::definition($relPath, (int) ($row[1] ?? 1), (string) ($row[2] ?? ''), (string) ($row[3] ?? ''), (string) ($row[4] ?? ''));
    }

    private function deleteRows(string $relPath): void
    {
        $this->pdo->prepare('DELETE FROM files WHERE path = ?')->execute([$relPath]);
    }

    /** @throws \PDOException */
    private static function connect(string $dbPath): \PDO
    {
        $previousUmask = \umask(0077);
        try {
            $pdo = new \PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
            $pdo->exec('PRAGMA journal_mode=WAL');
            // A derived cache: a crash may cost the last writes, never a
            // source file, so it does not pay for an fsync per commit.
            $pdo->exec('PRAGMA synchronous=NORMAL');
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

            $version = $pdo->query("SELECT value FROM meta WHERE key = 'extractor_version'")->fetchColumn();
            $expected = self::SCHEMA_VERSION . '/' . self::PHP_PRODUCER;
            if ($version !== $expected) {
                // Every table this class has ever had, in any layout.
                foreach (['tags', 'files', 'renders'] as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS ' . $table);
                }
                $pdo->exec('DROP INDEX IF EXISTS tags_name');
                $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('extractor_version', ?)")
                    ->execute([$expected]);
            }

            $pdo->exec('CREATE TABLE IF NOT EXISTS files (
                path TEXT PRIMARY KEY, mtime INTEGER NOT NULL, size INTEGER NOT NULL,
                producer TEXT NOT NULL, count INTEGER NOT NULL, tags TEXT NOT NULL
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS renders (key TEXT PRIMARY KEY, body TEXT NOT NULL, stored_at INTEGER NOT NULL)');
        } finally {
            \umask($previousUmask);
        }
        if ($dbPath !== ':memory:') {
            @\chmod($dbPath, 0600);
        }

        return $pdo;
    }
}
