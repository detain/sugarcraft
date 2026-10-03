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
 * problem the caller should report. An extractor version change wipes every
 * row ({@see EXTRACTOR_VERSION}), because tags from an older rule set would
 * silently mix with new ones.
 */
final class TagCache
{
    /**
     * Bump when {@see PhpSymbolExtractor}'s output changes for the same
     * input, so every cached row is re-extracted.
     */
    public const EXTRACTOR_VERSION = 1;

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
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
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
     * The tags for $relPath when the cached entry still matches $mtime and
     * $size, or null for a miss.
     *
     * @return list<Tag>|null
     */
    public function get(string $relPath, int $mtime, int $size): ?array
    {
        $stmt = $this->pdo->prepare('SELECT mtime, size FROM files WHERE path = ?');
        $stmt->execute([$relPath]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['mtime'] !== $mtime || (int) $row['size'] !== $size) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT path, line, name, kind, type, scope FROM tags WHERE path = ? ORDER BY seq');
        $stmt->execute([$relPath]);

        return \array_map(Tag::fromArray(...), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Replace $relPath's entry with $tags, stamped with $mtime and $size, in
     * one transaction, so a reader never sees half a file's tags.
     *
     * @param list<Tag> $tags
     */
    public function put(string $relPath, int $mtime, int $size, array $tags): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->deleteRows($relPath);
            $this->pdo->prepare('INSERT INTO files (path, mtime, size) VALUES (?, ?, ?)')->execute([$relPath, $mtime, $size]);
            $insert = $this->pdo->prepare('INSERT INTO tags (path, seq, line, name, kind, type, scope) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach (\array_values($tags) as $seq => $tag) {
                $insert->execute([$relPath, $seq, $tag->line, $tag->name, $tag->kind, $tag->type, $tag->scope]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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

    /** @return array{files:int,tags:int} */
    public function stats(): array
    {
        return [
            'files' => (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'tags' => (int) $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
        ];
    }

    private function deleteRows(string $relPath): void
    {
        $this->pdo->prepare('DELETE FROM tags WHERE path = ?')->execute([$relPath]);
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
            $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS files (path TEXT PRIMARY KEY, mtime INTEGER NOT NULL, size INTEGER NOT NULL)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
                path TEXT NOT NULL, seq INTEGER NOT NULL, line INTEGER NOT NULL,
                name TEXT NOT NULL, kind TEXT NOT NULL, type TEXT NOT NULL, scope TEXT NOT NULL,
                PRIMARY KEY (path, seq)
            )');
            $pdo->exec('CREATE INDEX IF NOT EXISTS tags_name ON tags (name)');

            $version = $pdo->query("SELECT value FROM meta WHERE key = 'extractor_version'")->fetchColumn();
            if ($version !== (string) self::EXTRACTOR_VERSION) {
                $pdo->exec('DELETE FROM tags');
                $pdo->exec('DELETE FROM files');
                $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('extractor_version', ?)")
                    ->execute([(string) self::EXTRACTOR_VERSION]);
            }
        } finally {
            \umask($previousUmask);
        }
        @\chmod($dbPath, 0600);

        return $pdo;
    }
}
