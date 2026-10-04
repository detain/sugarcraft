<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Memory;

use SugarCraft\Crush\Workspace\GitRunner;

/**
 * The home memory directory as a git repository (roadmap 5.4, nanobot's
 * git-versioned memory): every `/memory` change is a commit, `/memory log`
 * lists them and `/memory restore <sha>` puts the directory back as it stood
 * at one — as a NEW commit, so a restore is itself undoable.
 *
 * ONLY THE HOME DIRECTORY (`~/.sugar-crush/memory`). A repository's
 * `.sugar-crush/memory/` is already inside the user's own checkout, and a
 * `.git` nested there would be an embedded repository in their project —
 * {@see forStore()} answers null for a repository store
 * ({@see MemoryStore::writesIndex()} is false only there).
 *
 * EVERY CALL IS PINNED TO THIS DIRECTORY'S OWN `.git`, never discovered: with
 * discovery, a home directory that is itself a dotfiles repository would have
 * `git add -A` stage the user's notes into THAT repository the first time this
 * directory had no `.git` of its own. Calls go through {@see GitRunner} — the
 * package's one bounded git spawn (scrubbed `GIT_*` environment, hooks,
 * signing and pagers off, a fixed identity, `setsid` detach, a deadline and
 * the reaper's ladder) — so this class adds no child process of its own to
 * account for.
 *
 * WHAT IS NOT VERSIONED: the search index cache (`.search*.sqlite*`, a
 * derived file rebuilt from mtimes), the auto-memory throttle state, the
 * compaction journal and the dream pass's cursor, and the atomic writer's
 * in-flight temps. The journal is an append log the dream pass reads, not
 * curated memory: versioned, every commit would carry its growth, and a
 * restore would rewind it and replay entries the dream already folded in.
 * The generated `MEMORY.md` indexes ARE versioned, so a restore brings back the
 * index of every project's directory as it stood; the store's own scopes are
 * then regenerated through {@see MemoryStore::generateIndex()} before the
 * restore is committed, so the index can never describe other notes than the
 * ones restored.
 *
 * Notes are written 0600 and git checks files out under the umask, so a
 * restore re-applies 0600 to every file it brings back.
 */
final class MemoryHistory
{
    /** Most rows `/memory log` lists. */
    public const MAX_LOG_ENTRIES = 200;

    /** The subject of the repository's first commit, which holds only `.gitignore`. */
    public const START_SUBJECT = 'memory: start history';

    /** Rows `/memory log` lists when the user names no count. */
    public const DEFAULT_LOG_ENTRIES = 20;

    private const GITIGNORE = "# sugar-crush memory history: derived caches, run state, the compaction journal and in-flight temps\n"
        . ".search*.sqlite*\n"
        . ".auto-memory-*.json\n"
        . ".compaction-journal-*.jsonl\n"
        . ".dream-*.json\n"
        . ".*.tmp.*\n";

    /** A revision is named by a hex object id only — never a ref, range or option. */
    private const REVISION_PATTERN = '/\A[0-9a-f]{4,64}\z/D';

    /** The store's own scopes, regenerated after a restore. */
    private const SCOPES = ['user', 'project', 'agent'];

    private function __construct(
        private readonly string $dir,
        private readonly ?MemoryStore $store,
        private readonly GitRunner $git,
    ) {}

    /** The history of `$dir`, a home memory directory. */
    public static function new(string $dir): self
    {
        $dir = rtrim($dir, '/');

        return new self($dir, null, GitRunner::new($dir)->withGitDir($dir . '/.git', $dir));
    }

    /**
     * The history of `$store`'s directory, or null for a repository store,
     * which is never versioned here (see the class doc-block).
     */
    public static function forStore(MemoryStore $store): ?self
    {
        if (!$store->writesIndex()) {
            return null;
        }

        $history = self::new($store->path());

        return new self($history->dir, $store, $history->git);
    }

    /** Whether a `git` executable is on PATH — a stat walk, no spawn. */
    public static function available(): bool
    {
        return GitRunner::available();
    }

    /** The directory this history versions. */
    public function dir(): string
    {
        return $this->dir;
    }

    /** Whether the directory already has its own repository. */
    public function isInitialized(): bool
    {
        return is_dir($this->dir . '/.git');
    }

    /**
     * Commit whatever changed since the last commit, starting the repository
     * on first use, and answer the new commit's short id — or null when
     * nothing changed.
     *
     * @throws \RuntimeException when git is missing or a call fails
     */
    public function commit(string $message): ?string
    {
        $this->ensureInitialized();

        return $this->commitPending($message);
    }

    /**
     * The newest `$limit` commits, newest first. An uninitialised directory
     * has none.
     *
     * @return list<MemoryRevision>
     *
     * @throws \RuntimeException when a git call fails
     */
    public function log(int $limit = self::DEFAULT_LOG_ENTRIES): array
    {
        if (!$this->isInitialized()) {
            return [];
        }

        $limit = max(1, min(self::MAX_LOG_ENTRIES, $limit));
        $result = $this->git->run('log', '-n', (string) $limit, '--format=%H%x09%h%x09%ct%x09%s');
        if (!$result['ok']) {
            // A repository with no commit yet has no HEAD to walk.
            if ($this->git->run('rev-parse', '--verify', '--quiet', 'HEAD')['exitCode'] !== 0) {
                return [];
            }

            throw self::failed('git log', $result);
        }

        $revisions = [];
        foreach (preg_split('/\R/', trim($result['stdout'])) ?: [] as $line) {
            $fields = explode("\t", $line, 4);
            if (\count($fields) !== 4 || $fields[0] === '') {
                continue;
            }

            $revisions[] = new MemoryRevision(
                $fields[0],
                $fields[1],
                (new \DateTimeImmutable('@' . (int) $fields[2]))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
                $fields[3],
            );
        }

        return $revisions;
    }

    /**
     * Put the directory back as it stood at `$revision`, as a new commit, and
     * answer that commit's short id — or null when the directory already
     * matched it. Uncommitted changes are committed first, so nothing a
     * restore replaces is lost.
     *
     * @throws \InvalidArgumentException for a revision that is not a hex id, or names no commit here
     * @throws \RuntimeException when git is missing or a call fails
     */
    public function restore(string $revision): ?string
    {
        $revision = strtolower(trim($revision));
        if (preg_match(self::REVISION_PATTERN, $revision) !== 1) {
            throw new \InvalidArgumentException("'{$revision}' is not a commit id; use one /memory log lists");
        }
        if (!$this->isInitialized()) {
            throw new \InvalidArgumentException('memory has no history yet');
        }

        $resolved = $this->git->run('rev-parse', '--verify', '--quiet', $revision . '^{commit}');
        if (!$resolved['ok'] || trim($resolved['stdout']) === '') {
            throw new \InvalidArgumentException("no memory commit '{$revision}'");
        }
        $sha = trim($resolved['stdout']);

        $this->commitPending('memory: snapshot before restore');

        // The index and work tree become the revision's tree: files the
        // revision lacks are removed, changed ones rewritten. Ignored files
        // (the search cache) are not tracked, so they are left alone.
        $checkout = $this->git->run('read-tree', '--reset', '-u', $sha);
        if (!$checkout['ok']) {
            throw self::failed('git read-tree', $checkout);
        }

        $this->tightenPermissions();
        if ($this->store !== null) {
            foreach (self::SCOPES as $scope) {
                $this->store->generateIndex($scope);
            }
        }

        return $this->commitPending('memory: restore to ' . substr($sha, 0, 12));
    }

    /** @throws \RuntimeException */
    private function ensureInitialized(): void
    {
        if ($this->isInitialized()) {
            return;
        }
        if (!self::available()) {
            throw new \RuntimeException('memory history needs git on PATH');
        }
        if (!is_dir($this->dir)) {
            throw new \RuntimeException("{$this->dir} is not a directory");
        }

        // `init <dir>` rather than a discovered repository: see the class
        // doc-block. The branch is named so no "default branch" hint fires.
        $init = GitRunner::new($this->dir)->run('-c', 'init.defaultBranch=main', 'init', '-q', $this->dir);
        if (!$init['ok']) {
            throw self::failed('git init', $init);
        }

        $ignore = $this->dir . '/.gitignore';
        if (!is_file($ignore)) {
            @file_put_contents($ignore, self::GITIGNORE);
            @chmod($ignore, 0o600);
        }

        // The first commit holds the ignore rules alone, so the notes already
        // on disk are committed by the caller under the caller's own subject
        // rather than credited to the repository's creation.
        $add = $this->git->run('add', '--', '.gitignore');
        $start = $add['ok'] ? $this->git->run('commit', '-q', '--no-verify', '-m', self::START_SUBJECT) : $add;
        if (!$start['ok']) {
            throw self::failed('git commit', $start);
        }
    }

    /** @throws \RuntimeException */
    private function commitPending(string $message): ?string
    {
        $add = $this->git->run('add', '-A', '--', '.');
        if (!$add['ok']) {
            throw self::failed('git add', $add);
        }

        // Exit 0: the index matches HEAD (or, before the first commit, holds
        // nothing) - there is nothing to commit.
        $staged = $this->git->run('diff', '--cached', '--quiet');
        if ($staged['exitCode'] === 0) {
            return null;
        }
        if ($staged['exitCode'] !== 1) {
            throw self::failed('git diff', $staged);
        }

        $commit = $this->git->run('commit', '-q', '--allow-empty-message', '--no-verify', '-m', $message);
        if (!$commit['ok']) {
            throw self::failed('git commit', $commit);
        }

        $head = $this->git->run('rev-parse', '--short=12', 'HEAD');

        return $head['ok'] ? trim($head['stdout']) : null;
    }

    /**
     * Re-apply the store's 0600 to every file the checkout wrote under the
     * umask — the tracked files, as git lists them; nothing else is touched.
     *
     * @throws \RuntimeException
     */
    private function tightenPermissions(): void
    {
        $listed = $this->git->run('ls-files', '-z');
        if (!$listed['ok']) {
            throw self::failed('git ls-files', $listed);
        }

        foreach (explode("\0", $listed['stdout']) as $relative) {
            $path = $this->dir . '/' . $relative;
            if ($relative !== '' && is_file($path) && !is_link($path)) {
                @chmod($path, 0o600);
            }
        }
    }

    /** @param array{stderr: string, exitCode: int, timedOut: bool} $result */
    private static function failed(string $what, array $result): \RuntimeException
    {
        $why = $result['timedOut'] ? 'timed out' : trim($result['stderr']);

        return new \RuntimeException("{$what} failed" . ($why === '' ? " (exit {$result['exitCode']})" : ": {$why}"));
    }
}
