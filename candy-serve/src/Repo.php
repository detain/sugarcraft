<?php

declare(strict_types=1);

namespace SugarCraft\Serve;

use SugarCraft\Serve\Lang;

/**
 * A Git repository managed by CandyServe.
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

    /** Repo visibility (public / collaborator-only / private) — plan item 7.8. */
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
     * Initialize a bare Git repository at $path.
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
            $out = [];
            $rc  = 0;
            $path = \escapeshellarg($this->path);
            \exec("git -C {$path} init --bare 2>&1", $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException(Lang::t('repo.git_init_failed', ['output' => \implode("\n", $out)]));
            }

            // Group-writable objects so pushes work when server workers run
            // under different users sharing a group (soft-serve parity).
            \exec("git -C {$path} config core.sharedRepository umask 2>&1", $out, $rc);
        }

        // Set description
        \file_put_contents($this->path . '/description', $this->description ?: 'Unnamed repository');

        // Disable anonymous push
        \file_put_contents($this->path . '/git-daemon-export-ok', '');

        // Set hooks dir (shared)
        $hooksSrc = $this->path . '/hooks';
        if (!\file_exists($hooksSrc) && !\is_link($hooksSrc)) {
            // A missing hooks dir is survivable (git falls back to no hooks),
            // but hiding the failure makes "why don't my hooks run?" undebuggable.
            if (!@\symlink('/usr/share/git-core/templates/hooks', $hooksSrc)) {
                \error_log("candy-serve: failed to symlink shared hooks into {$hooksSrc}");
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

    public function exists(): bool
    {
        return \is_dir($this->path . '/.git');
    }

    /**
     * @return list<string> Branches in this repo
     */
    public function branches(): array
    {
        $out = [];
        $rc  = 0;
        $path = \escapeshellarg($this->path);
        \exec("git -C {$path} branch --format='%(refname:short)' 2>&1", $out, $rc);
        if ($rc !== 0) return [];

        return \array_values(\array_filter(\array_map('trim', $out), fn($l) => $l !== ''));
    }

    /**
     * @return list<string> Tags in this repo
     */
    public function tags(): array
    {
        $out = [];
        $rc  = 0;
        $path = \escapeshellarg($this->path);
        \exec("git -C {$path} tag 2>&1", $out, $rc);
        return $rc === 0 ? $out : [];
    }

    /**
     * Get a list of { ref => hash } for refs matching $prefix.
     *
     * @return array<string, string>
     */
    public function refs(string $prefix = 'refs/heads'): array
    {
        $out = [];
        $rc  = 0;
        $path = \escapeshellarg($this->path);
        $escapedPrefix = \escapeshellarg($prefix);
        \exec("git -C {$path} for-each-ref --format='%(objectname) %(refname)' {$escapedPrefix} 2>&1", $out, $rc);
        if ($rc !== 0) return [];

        $result = [];
        foreach ($out as $line) {
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
        $escapedPath = \escapeshellarg($path);
        $escapedHash = \escapeshellarg($commitHash);
        $repoPath = \escapeshellarg($this->path);
        $out = [];
        $rc  = 0;
        \exec("git -C {$repoPath} show {$escapedHash}:{$escapedPath} 2>&1", $out, $rc);
        if ($rc !== 0) return null;
        return \implode("\n", $out);
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
