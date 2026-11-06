<?php

declare(strict_types=1);

namespace SugarCraft\GitServer;

use SugarCraft\GitServer\Lang;
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
        Visibility $visibility = Visibility::Public,
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
        if (@\file_put_contents($file, $this->visibility->value . "\n") === false) {
            throw new \RuntimeException(Lang::t('repo.visibility_marker_unwritable', ['file' => $file]));
        }
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
            $ok = \mkdir($this->path, 0755, true);
            if (!$ok) {
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

        // Set description
        \file_put_contents($gitDir . '/description', $this->description ?: 'Unnamed repository');

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

        return $result['code'] === 0 ? $result['output'] : [];
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
     * @return string|null  null if not found
     */
    public function readFile(string $commitHash, string $path): ?string
    {
        $result = BoundedCommand::run(['git', '-C', $this->path, 'show', $commitHash . ':' . $path]);
        if ($result['code'] !== 0) {
            return null;
        }

        return \implode("\n", $result['output']);
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
