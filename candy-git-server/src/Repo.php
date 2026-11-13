<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\GitServer\Lang;
use SugarCraft\GitServer\Support\AtomicWriter;
use SugarCraft\GitServer\Support\BoundedCommand;

/**
 * A Git repository managed by CandyGitServer.
 *
 * Port of charmbracelet/soft-serve Repo.
 *
 * @see https://github.com/charmbracelet/soft-serve
 */
final class Repo
{
    /** Unique repo name (slug). */
    public readonly string $name;

    /** Human-readable description. */
    public readonly string $description;

    /** Repo visibility (public / collaborator-only / private). */
    public readonly Visibility $visibility;

    /** BC accessor for the old boolean model: true iff visibility is Public. */
    public readonly bool $isPublic;

    /** Whether pushes are allowed without explicit write access. */
    public readonly bool $allowPush;

    /** Whether to mirror/pull from an upstream remote. */
    public readonly ?string $mirrorFrom;

    /** Language for syntax highlighting (e.g. 'php', 'go'). */
    public readonly string $highlightLanguage;

    /** @var list<string> Usernames with read access */
    private array $collaborators = [];

    /** Path to the bare Git repository on disk. */
    private string $path;

    private function __construct(
        string $name,
        string $path,
        string $description = '',
        Visibility $visibility = Visibility::Private,
        bool $allowPush = false,
        ?string $mirrorFrom = null,
        string $highlightLanguage = '',
        array $collaborators = [],
    ) {
        $this->name                = $name;
        $this->path                = $path;
        $this->description         = $description;
        $this->visibility          = $visibility;
        $this->isPublic            = $visibility === Visibility::Public;
        $this->allowPush           = $allowPush;
        $this->mirrorFrom          = $mirrorFrom;
        $this->highlightLanguage   = $highlightLanguage;
        $this->collaborators       = $collaborators;
    }

    /**
     * A repo handle for ($name, $path). The posture DEFAULT is Private
     * (009): a handle is un-trusted for the world until visibility is set
     * explicitly (withVisibility/withPublic, marker overlay, or the CLI's
     * --public at create). Callers wanting public pass it on purpose.
     */
    public static function new(string $name, string $path): self
    {
        return new self($name, $path);
    }

    /**
     * Is $name safe to use as a single repository directory component?
     *
     * The charset gate alone is NOT containment: '.' and '..' pass
     * /^[a-zA-Z0-9._-]+$/ and, appended to the repos root, would address the
     * parent directory — reading or creating repositories outside the jail
     * (anonymous on the daemon, on-demand-creating on the SSH path). Reject
     * every all-dots name; everything else in the charset is a plain
     * filesystem component with no traversal meaning.
     */
    public static function isJailSafeName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        if (\preg_match('/^[a-zA-Z0-9._-]+$/', $name) !== 1) {
            return false;
        }
        // Leading '.' or '-' names invite confusion (option-looking tokens,
        // hidden entries); the jail requires plain names.
        if ($name[0] === '.' || $name[0] === '-') {
            return false;
        }
        return \preg_match('/^\.+$/', $name) !== 1;
    }

    /**
     * Name of the on-disk marker file that persists a repo's visibility for
     * the scan-based launcher (`serve --http --repo-root`). Lives inside the
     * git directory; its content is a Visibility value ('public',
     * 'collaborator-only', 'private').
     */
    public const VISIBILITY_MARKER = 'candy-git-server-visibility';

    /**
     * Visibility recorded by the marker inside a git directory — the
     * launcher's persistence. FAIL-CLOSED: a missing, unreadable, or
     * unrecognizable marker means PRIVATE. Repos are only world-visible
     * because an operator explicitly published them; scanning a directory
     * of ordinary clones must never mint anonymous readability.
     */
    public static function markerVisibility(string $gitDir): Visibility
    {
        $raw = @\file_get_contents($gitDir . '/' . self::VISIBILITY_MARKER);
        if ($raw === false) {
            return Visibility::Private;
        }
        return match (\trim($raw)) {
            Visibility::Public->value             => Visibility::Public,
            Visibility::CollaboratorOnly->value   => Visibility::CollaboratorOnly,
            default                               => Visibility::Private,
        };
    }

    /**
     * The git directory for a path (path itself when bare — a HEAD file at
     * the root — else path/.git). Static so discovery gates that only have a
     * path on hand (transport disk fallbacks) resolve the same layout the
     * marker writer does, never a hardcoded '/.git' that silently misses a
     * bare repo's marker and fail-closes a published repo to private.
     */
    public static function gitDirFor(string $path): string
    {
        return \is_file($path . '/HEAD') ? $path : $path . '/.git';
    }

    /** The git directory for this repo (path itself when bare, else path/.git). */
    public function gitDir(): string
    {
        return self::gitDirFor($this->path);
    }

    /** Persist the current visibility marker into the repo's git directory. */
    public function writeVisibilityMarker(): void
    {
        $gitDir = $this->gitDir();
        // n15: mkdir runs only when the directory does not exist yet (an
        // existing .git keeps the mode its creator chose — re-chmodding
        // someone else's git dir is not this method's business). The 0700
        // request states the intent: a fresh marker directory must never
        // land group/world-accessible on a permissive-umask accident.
        if (!\is_dir($gitDir) && !@\mkdir($gitDir, 0700, true) && !\is_dir($gitDir)) {
            throw new \RuntimeException(Lang::t('repo.visibility_marker_unwritable', ['file' => $gitDir]));
        }
        $file = $gitDir . '/' . self::VISIBILITY_MARKER;
        // 042: the marker used to be a bare file_put_contents — a
        // truncation mid-write silently UN-PUBLISHES (an empty marker
        // reads fail-closed private, dropping the operator's declared
        // public state). The atomic exchange only ever leaves the old
        // bytes or the new ones. Mode stays 0644: group readers are the
        // sharedRepository=umask push shape, and the marker is posture,
        // not a secret.
        try {
            AtomicWriter::write($file, $this->visibility->value . "\n", 0644);
        } catch (\RuntimeException $e) {
            // The pinned failure contract is the lang-keyed line; the
            // helper's why is appended so the operator sees the actual
            // syscall failure underneath.
            throw new \RuntimeException(
                Lang::t('repo.visibility_marker_unwritable', ['file' => $file]) . ' (' . $e->getMessage() . ')'
            );
        }
    }

    // ---------------------------------------------------------------------
    // Metadata plane (S25, finding 012): the fields the visibility marker
    // never carried — description, allowPush, mirrorFrom,
    // highlightLanguage, collaborators (+ the mirror job's persisted
    // last-attempt). One JSON file inside the git dir, written through the
    // same atomic discipline, guarded by its own sidecar lock so two
    // control-plane processes cannot lose each other's edits.
    // ---------------------------------------------------------------------

    /** Name of the per-repo metadata file (lives inside the git directory). */
    public const META_FILE = 'candy-git-server-meta.json';

    /** Sidecar lock spanning meta load→mutate→publish. */
    public const META_LOCK_FILE = 'candy-git-server-meta.lock';

    /** Lock a mirror's pull batch against concurrent cron ticks (finding 044). */
    public const MIRROR_LOCK_FILE = 'candy-git-server-mirror.lock';

    /** Current metadata-plane schema version. */
    public const META_VERSION = 1;

    public static function metaPathFor(string $gitDir): string
    {
        return $gitDir . '/' . self::META_FILE;
    }

    /**
     * @return array{version:int,description:string,allowPush:string,mirrorFrom:?string,
     *               highlightLanguage:string,collaborators:list<string>,mirrorLastAttempt:?int}
     */
    private static function metaDefaults(): array
    {
        return [
            'version'           => self::META_VERSION,
            'description'       => '',
            'allowPush'         => false,
            'mirrorFrom'        => null,
            'highlightLanguage' => '',
            'collaborators'     => [],
            'mirrorLastAttempt' => null,
        ];
    }

    /**
     * Read the metadata plane with the lib's strict-vocabulary discipline
     * (S07 kin): every field is CHECKED, garbage falls back to its safe
     * default with one named error_log line — never a cast of junk into a
     * security posture (a string-'false' allowPush must not read TRUE). A
     * version NEWER than this build understands is refused wholesale:
     * silently dropping fields the future added would UNDO the operator's
     * metadata on the next publish, which is exactly the loss 012 is about.
     * Missing/unreadable file = plain defaults (a fresh repo has no meta).
     *
     * @return array<string, mixed>
     */
    public static function readMeta(string $gitDir): array
    {
        $defaults = self::metaDefaults();
        $raw = @\file_get_contents(self::metaPathFor($gitDir));
        if ($raw === false || \trim($raw) === '') {
            return $defaults;
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            \error_log("candy-git-server: {$gitDir}: metadata plane is corrupt JSON; using safe defaults");

            return $defaults;
        }
        $version = $decoded['version'] ?? self::META_VERSION;
        if (!\is_int($version) || $version > self::META_VERSION) {
            \error_log('candy-git-server: ' . $gitDir
                . ': metadata plane version ' . (\is_scalar($version) ? (string) $version : \gettype($version))
                . ' is newer than this build understands; refusing the overlay (no fields are dropped and re-published)');

            return $defaults;
        }

        $meta = $defaults;
        $meta['version'] = self::META_VERSION;

        if (\array_key_exists('description', $decoded)) {
            if (\is_string($decoded['description'])) {
                $meta['description'] = $decoded['description'];
            } else {
                \error_log("candy-git-server: {$gitDir}: metadata description is not a string; defaulting");
            }
        }
        if (\array_key_exists('allowPush', $decoded)) {
            // The ONLY accepted spellings are the JSON booleans — a
            // "truthy string" is precisely the 007 garbage this lib
            // refused at the config boundary.
            if (\is_bool($decoded['allowPush'])) {
                $meta['allowPush'] = $decoded['allowPush'];
            } else {
                \error_log("candy-git-server: {$gitDir}: metadata allowPush is not a boolean; defaulting to false");
            }
        }
        if (\array_key_exists('mirrorFrom', $decoded)) {
            if ($decoded['mirrorFrom'] === null || \is_string($decoded['mirrorFrom'])) {
                $meta['mirrorFrom'] = $decoded['mirrorFrom'];
            } else {
                \error_log("candy-git-server: {$gitDir}: metadata mirrorFrom is neither string nor null; defaulting");
            }
        }
        if (\array_key_exists('highlightLanguage', $decoded)) {
            if (\is_string($decoded['highlightLanguage'])) {
                $meta['highlightLanguage'] = $decoded['highlightLanguage'];
            } else {
                \error_log("candy-git-server: {$gitDir}: metadata highlightLanguage is not a string; defaulting");
            }
        }
        if (\array_key_exists('collaborators', $decoded) && \is_array($decoded['collaborators'])) {
            $clean = [];
            foreach ($decoded['collaborators'] as $entry) {
                if (\is_string($entry) && Repo::isJailSafeName($entry)) {
                    $clean[] = $entry;
                } else {
                    \error_log("candy-git-server: {$gitDir}: metadata collaborator entry rejected (not a plain name)");
                }
            }
            $meta['collaborators'] = \array_values(\array_unique($clean));
        } elseif (\array_key_exists('collaborators', $decoded)) {
            \error_log("candy-git-server: {$gitDir}: metadata collaborators is not a list; defaulting to empty");
        }
        if (\array_key_exists('mirrorLastAttempt', $decoded)) {
            if (\is_int($decoded['mirrorLastAttempt']) && $decoded['mirrorLastAttempt'] >= 0) {
                $meta['mirrorLastAttempt'] = $decoded['mirrorLastAttempt'];
            } elseif ($decoded['mirrorLastAttempt'] === null) {
                $meta['mirrorLastAttempt'] = null;
            } else {
                \error_log("candy-git-server: {$gitDir}: metadata mirrorLastAttempt is not a non-negative int; defaulting");
            }
        }

        return $meta;
    }

    /** This handle's persistable fields as a metadata-plane array. */
    public function metaSnapshot(): array
    {
        return [
            'version'           => self::META_VERSION,
            'description'       => $this->description,
            'allowPush'         => $this->allowPush,
            'mirrorFrom'        => $this->mirrorFrom,
            'highlightLanguage' => $this->highlightLanguage,
            'collaborators'     => $this->collaborators(),
            // The job owns this stamp through mutateMeta(); the VO never
            // carries it, so a snapshot publish leaves it untouched.
            'mirrorLastAttempt' => null,
        ];
    }

    /**
     * Publish this handle's fields to the metadata plane atomically. The
     * mirror last-attempt stamp is PRESERVED across a snapshot publish —
     * it belongs to the job, not to whoever edited the description.
     */
    public function writeMeta(): void
    {
        $gitDir = $this->gitDir();
        $snapshot = $this->metaSnapshot();
        $existing = self::readMeta($gitDir);
        $snapshot['mirrorLastAttempt'] = $existing['mirrorLastAttempt'];
        $this->publishMeta($gitDir, $snapshot);
    }

    private function publishMeta(string $gitDir, array $meta): void
    {
        $json = \json_encode($meta, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('candy-git-server: metadata plane could not be encoded for ' . $gitDir);
        }
        // 0644 like the visibility marker: operator metadata, not a
        // secret (credentials never enter this plane). Atomic xb+fsync+
        // rename via the S24 helper — a truncated meta publish can only
        // ever leave the old bytes, never a half-written ACL story.
        AtomicWriter::write(self::metaPathFor($gitDir), $json . "\n", 0644);
    }

    /**
     * Load→mutate→publish the metadata plane under an exclusive sidecar
     * lock (UserStore's S11 discipline in miniature): concurrent
     * `repo collaborator` processes serialize instead of each publishing
     * over the other's bytes. The mutator receives the validated on-disk
     * array and returns the array to publish; its value comes back out.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $mutator
     * @return array<string,mixed> the published metadata
     */
    public function mutateMeta(callable $mutator): array
    {
        $gitDir = $this->gitDir();
        if (!\is_dir($gitDir)) {
            throw new \RuntimeException(Lang::t('repo.visibility_marker_unwritable', ['file' => $gitDir]));
        }
        $lock = @\fopen($gitDir . '/' . self::META_LOCK_FILE, 'c');
        if ($lock === false) {
            throw new \RuntimeException(Lang::t('repo.visibility_marker_unwritable', ['file' => $gitDir . '/' . self::META_LOCK_FILE]));
        }
        @\chmod($gitDir . '/' . self::META_LOCK_FILE, 0600);
        try {
            if (!\flock($lock, \LOCK_EX)) {
                throw new \RuntimeException(Lang::t('repo.visibility_marker_unwritable', ['file' => $gitDir . '/' . self::META_LOCK_FILE]));
            }
            $meta = $mutator(self::readMeta($gitDir));
            if (!\is_array($meta)) {
                throw new \LogicException('metadata mutator must return an array');
            }
            $meta['version'] = self::META_VERSION;
            $this->publishMeta($gitDir, $meta);

            return $meta;
        } finally {
            @\flock($lock, \LOCK_UN);
            @\fclose($lock);
        }
    }

    /**
     * Overlay this handle with the on-disk metadata plane (the scan
     * path's missing half — pre-012 the launcher rebuilt visibility
     * only, and every CollaboratorOnly repo silently degraded to
     * admin-only across a restart). Visibility stays the marker's
     * business; the meta never carries it.
     */
    public function withMetaFromDisk(): self
    {
        $meta = self::readMeta($this->gitDir());

        return (new self(
            $this->name,
            $this->path,
            $meta['description'],
            $this->visibility,
            $meta['allowPush'],
            $meta['mirrorFrom'],
            $meta['highlightLanguage'],
            $meta['collaborators'],
        ));
    }

    /**
     * The mirror job's persisted last-attempt for this repo (null =
     * never), read straight from the metadata plane.
     */
    public function mirrorLastAttempt(): ?int
    {
        $stamp = self::readMeta($this->gitDir())['mirrorLastAttempt'];

        return \is_int($stamp) ? $stamp : null;
    }

    /** Persist a mirror pull-attempt stamp (finding 044). */
    public function stampMirrorAttempt(int $when): void
    {
        $this->mutateMeta(static function (array $meta) use ($when): array {
            $meta['mirrorLastAttempt'] = $when;

            return $meta;
        });
    }

    // -------------------------------------------------------------------------
    // Builder
    // -------------------------------------------------------------------------
    public function withDescription(string $d): self
    {
        return new self($this->name, $this->path, $d, $this->visibility, $this->allowPush, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withVisibility(Visibility $v): self
    {
        return new self($this->name, $this->path, $this->description, $v, $this->allowPush, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    /**
     * BC setter for the old boolean model. `withPublic(false)` on a
     * Private repo stays Private (the old code kept the private bit);
     * on a Public repo it demotes to CollaboratorOnly.
     */
    public function withPublic(bool $v = true): self
    {
        $visibility = match (true) {
            $v => Visibility::Public,
            $this->visibility === Visibility::Private => Visibility::Private,
            default => Visibility::CollaboratorOnly,
        };
        return $this->withVisibility($visibility);
    }

    /**
     * BC setter for the old boolean model. `withPrivate(false)` on a
     * Private repo resolves to CollaboratorOnly (the conservative
     * reading — the old public bit is no longer tracked separately).
     */
    public function withPrivate(bool $v = true): self
    {
        $visibility = match (true) {
            $v => Visibility::Private,
            $this->visibility === Visibility::Private => Visibility::CollaboratorOnly,
            default => $this->visibility,
        };
        return $this->withVisibility($visibility);
    }

    public function withAllowPush(bool $v = true): self
    {
        return new self($this->name, $this->path, $this->description, $this->visibility, $v, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withMirrorFrom(?string $url): self
    {
        return new self($this->name, $this->path, $this->description, $this->visibility, $this->allowPush, $url, $this->highlightLanguage, $this->collaborators);
    }

    public function withHighlightLanguage(string $lang): self
    {
        return new self($this->name, $this->path, $this->description, $this->visibility, $this->allowPush, $this->mirrorFrom, $lang, $this->collaborators);
    }

    public function addCollaborator(string $username): self
    {
        if (\in_array($username, $this->collaborators, true)) return $this;
        $clone = clone $this;
        $clone->collaborators[] = $username;
        return $clone;
    }

    public function removeCollaborator(string $username): self
    {
        $clone = clone $this;
        $clone->collaborators = \array_values(
            \array_filter($clone->collaborators, fn($u) => $u !== $username)
        );
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Git operations
    // -------------------------------------------------------------------------

    /**
     * Initialize a bare Git repository layout under $path: the Git itself
     * lives at $path/.git, matching exists() and every discovery gate
     * (bin repo list/info, GitDaemon::findRepoByPath, SSHServer). Before
     * this fix `git init --bare` made $path itself the bare repo, so a
     * freshly created repository was invisible to every gate that looks
     * for $path/.git.
     *
     * @throws \RuntimeException If git is not available or init fails
     */
    public function init(): self
    {
        if (!\is_dir($this->path)) {
            // 038: the jail's per-repo directory used to land 0755 under a
            // permissive umask — world-readable straight off disk, which
            // bypasses every access check with a plain `cp -r`. 0750 with
            // an explicit chmod: owner full, group read-only, no world.
            try {
                AtomicWriter::ensureDir($this->path, 0750);
            } catch (\RuntimeException $e) {
                // The pinned failure contract is the lang-keyed line.
                throw new \RuntimeException(Lang::t('repo.create_dir_failed', ['path' => $this->path]));
            }
        }

        $gitDir = $this->path . '/.git';
        if (!\is_dir($gitDir)) {
            $init = BoundedCommand::run(['git', 'init', '--bare', $gitDir]);
            if ($init['code'] !== 0) {
                throw new \RuntimeException(Lang::t('repo.git_init_failed', ['output' => \implode("\n", $init['output'])]));
            }

            // Group-writable objects so pushes work when server workers run
            // under different users sharing a group (soft-serve parity).
            BoundedCommand::run(['git', '-C', $gitDir, 'config', 'core.sharedRepository', 'umask']);
        }

        // Set description (042: routed through the atomic writer — a
        // truncated description from the old bare write was cosmetic-but-
        // permanent; now the field is either the old bytes or the new).
        AtomicWriter::write($gitDir . '/description', (string) ($this->description ?: 'Unnamed repository'), 0644);

        // Deliberately NO 'git-daemon-export-ok' marker: that file ENABLES
        // anonymous read-export of this repo to any co-hosted stock
        // git-daemon process — writing it on every init is a booby trap for
        // the host. A repo is shared anonymously only when the operator
        // opts that marker in by hand.

        // Set hooks dir (shared)
        $hooksSrc = $gitDir . '/hooks';
        if (!\file_exists($hooksSrc) && !\is_link($hooksSrc)) {
            // A missing hooks dir is survivable (git falls back to no hooks),
            // but hiding the failure makes "why don't my hooks run?" undebuggable.
            if (!@\symlink('/usr/share/git-core/templates/hooks', $hooksSrc)) {
                \error_log("candy-git-server: failed to symlink shared hooks into {$hooksSrc}");
            }
        }

        return $this;
    }

    /**
     * Get the path to the bare Git repository.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Whether a Git repository lives at this path in EITHER supported
     * layout — the lib's own `$path/.git` bare-Git shape (init()) or a
     * true bare directory (HEAD at the root). Mirrors gitDir() so the
     * registerRepo marker gate, and everything else that asks "does this
     * repo exist", cannot go blind to bare shapes the marker writer
     * itself handles.
     */
    public function exists(): bool
    {
        return \is_dir($this->gitDir());
    }

    /**
     * @return list<string> Branches in this repo
     */
    public function branches(): array
    {
        $result = BoundedCommand::run(['git', '-C', $this->path, 'branch', '--format=%(refname:short)']);
        if ($result['code'] !== 0) {
            return [];
        }

        return \array_values(\array_filter(\array_map('trim', $result['output']), static fn ($l) => $l !== ''));
    }

    /**
     * @return list<string> Tags in this repo
     */
    public function tags(): array
    {
        $result = BoundedCommand::run(['git', '-C', $this->path, 'tag']);
        if ($result['code'] !== 0) {
            return [];
        }

        // S16 (finding 088): the identical trim/filter legs branches()
        // applies — same stream shape, same handling, no stray \r and no
        // '' entries leaking to callers.
        return \array_values(\array_filter(\array_map('trim', $result['output']), static fn ($l) => $l !== ''));
    }

    /**
     * Get a list of { ref => hash } for refs matching $prefix.
     *
     * @return array<string, string>
     */
    public function refs(string $prefix = 'refs/heads'): array
    {
        $listing = BoundedCommand::run([
            'git', '-C', $this->path,
            'for-each-ref', '--format=%(objectname) %(refname)', $prefix,
        ]);
        if ($listing['code'] !== 0) {
            return [];
        }

        $result = [];
        foreach ($listing['output'] as $line) {
            $line = \trim($line);
            if ($line === '') continue;
            // for-each-ref's format string guarantees exactly one separating space
            $parts = \explode(' ', $line, 2);
            if (\count($parts) === 2) {
                $result[$parts[1]] = $parts[0];
            }
        }
        return $result;
    }

    /**
     * Read a file from the repo at a given commit + path.
     *
     * S16 (finding 031): the bytes handed back are the blob's bytes —
     * runRaw skips the line-split, so a CRLF file no longer round-trips
     * as LF and a trailing newline is content, not noise. And exit!=0 is
     * no longer one big shrug: "that object/path is not here" is the
     * honest null the API promised, while a REPOSITORY fault (unreadable
     * dir, corrupt objects) is logged with the child's own verdict
     * before the same null — a browse 404 and a broken repo stay
     * distinguishable by whoever holds the log.
     *
     * @return string|null  null if not found (or the repo itself failed)
     */
    public function readFile(string $commitHash, string $path): ?string
    {
        $result = BoundedCommand::runRaw(['git', '-C', $this->path, 'show', $commitHash . ':' . $path]);
        if ($result['code'] === 0) {
            if ($result['truncated']) {
                // Partial bytes are a lie with a Content-Length: refuse
                // honestly instead of serving a silent prefix (finding 030).
                \error_log("candy-git-server: {$this->path}: {$commitHash}:{$path} exceeds the bounded output cap; refusing partial content");

                return null;
            }

            return $result['stdout'];
        }

        if (self::isNotFoundFault($result['stderr'])) {
            return null;
        }

        \error_log("candy-git-server: {$this->path}: git show {$commitHash}:{$path} failed (code {$result['code']}): "
            . \trim(\substr($result['stderr'], -400)));

        return null;
    }

    /**
     * The git fatal family that means "this rev/path simply is not
     * here" — the ordinary browse miss. Anything else that fails at
     * exit 128 is the repository's fault, not the reader's.
     */
    private static function isNotFoundFault(string $stderr): bool
    {
        return \preg_match(
            '/does not exist|exists on disk, but not in|bad object|unknown revision|not a valid object name|ambiguous argument|bad revision/i',
            $stderr
        ) === 1;
    }

    /**
     * Get the README content (tries common names).
     *
     * @return array{content: string, name: string}|null
     */
    public function readme(): ?array
    {
        $refs = $this->refs();
        $ref = $refs['refs/heads/master'] ?? $refs['refs/heads/main'] ?? null;
        if ($ref === null) return null;

        foreach (['README.md', 'README', 'readme.md', 'README.txt'] as $name) {
            $content = $this->readFile($ref, $name);
            if ($content !== null) {
                return ['content' => $content, 'name' => $name];
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    public function isPrivate(): bool
    {
        return $this->visibility->isPrivate();
    }

    /**
     * Whether this repo is publicly visible. A repo that is not
     * explicitly public is collaborator-only (or private).
     */
    public function isVisiblePublic(): bool
    {
        return $this->visibility->isPublic();
    }

    /** Whether this repo mirrors an upstream remote (has a pull URL). */
    public function isMirror(): bool
    {
        return $this->mirrorFrom !== null;
    }

    public function isCollaborator(string $username): bool
    {
        return \in_array($username, $this->collaborators, true);
    }

    /** @return list<string> */
    public function collaborators(): array
    {
        return $this->collaborators;
    }
}
