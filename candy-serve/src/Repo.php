<?php

declare(strict_types=1);

namespace CandyCore\Serve;

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

    /** Whether the repo is public (anyone can read). */
    public readonly bool $isPublic;

    /** Whether pushes are allowed without explicit write access. */
    public readonly bool $allowPush;

    /** Whether to mirror/pull from an upstream remote. */
    public readonly ?string $mirrorFrom;

    /** Language for syntax highlighting (e.g. 'php', 'go'). */
    public readonly string $highlightLanguage;

    /** Private: only collaborators can access. */
    private bool $private;

    /** @var list<string> Usernames with read access */
    private array $collaborators = [];

    /** Path to the bare Git repository on disk. */
    private string $path;

    private function __construct(
        string $name,
        string $path,
        string $description = '',
        bool $isPublic = true,
        bool $private = false,
        bool $allowPush = false,
        ?string $mirrorFrom = null,
        string $highlightLanguage = '',
        array $collaborators = [],
    ) {
        $this->name                = $name;
        $this->path                = $path;
        $this->description         = $description;
        $this->isPublic            = $isPublic;
        $this->private             = $private;
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
        return new self($this->name, $this->path, $d, $this->isPublic, $this->private, $this->allowPush, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withPublic(bool $v = true): self
    {
        return new self($this->name, $this->path, $this->description, $v, $this->private, $this->allowPush, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withPrivate(bool $v = true): self
    {
        return new self($this->name, $this->path, $this->description, $this->isPublic, $v, $this->allowPush, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withAllowPush(bool $v = true): self
    {
        return new self($this->name, $this->path, $this->description, $this->isPublic, $this->private, $v, $this->mirrorFrom, $this->highlightLanguage, $this->collaborators);
    }

    public function withMirrorFrom(?string $url): self
    {
        return new self($this->name, $this->path, $this->description, $this->isPublic, $this->private, $this->allowPush, $url, $this->highlightLanguage, $this->collaborators);
    }

    public function withHighlightLanguage(string $lang): self
    {
        return new self($this->name, $this->path, $this->description, $this->isPublic, $this->private, $this->allowPush, $this->mirrorFrom, $lang, $this->collaborators);
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
                throw new \RuntimeException("Failed to create repo directory: {$this->path}");
            }
        }

        $gitDir = $this->path . '/.git';
        if (!\is_dir($gitDir)) {
            $out = [];
            $rc  = 0;
            \exec('git init --bare 2>&1', $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException('git init failed: ' . \implode("\n", $out));
            }
        }

        // Set description
        \file_put_contents($this->path . '/description', $this->description ?: 'Unnamed repository');

        // Disable anonymous push
        \file_put_contents($this->path . '/git-daemon-export-ok', '');

        // Set hooks dir (shared)
        $hooksSrc = $this->path . '/hooks';
        if (\is_link($hooksSrc) || !\file_exists($hooksSrc)) {
            @\symlink('/usr/share/git-core/templates/hooks', $hooksSrc);
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
        \exec("git -C {$this->path} branch 2>&1", $out, $rc);
        if ($rc !== 0) return [];

        return \array_map(
            fn($line) => \ltrim($line, '* '),
            \array_filter($out, fn($l) => !\str_starts_with($l, ' '))
        );
    }

    /**
     * @return list<string> Tags in this repo
     */
    public function tags(): array
    {
        $out = [];
        $rc  = 0;
        \exec("git -C {$this->path} tag 2>&1", $out, $rc);
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
        \exec("git -C {$this->path} for-each-ref --format='%(objectname) %(refname)' {$prefix} 2>&1", $out, $rc);
        if ($rc !== 0) return [];

        $result = [];
        foreach ($out as $line) {
            $line = \trim($line);
            if ($line === '') continue;
            $parts = \preg_split('/\s+/', $line, 2);
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
        $out = [];
        $rc  = 0;
        \exec("git -C {$this->path} show {$escapedHash}:{$escapedPath} 2>&1", $out, $rc);
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
        foreach (['README.md', 'README', 'readme.md', 'README.txt'] as $name) {
            $ref = $this->refs()['refs/heads/master'] ?? $this->refs()['refs/heads/main'] ?? null;
            if ($ref === null) break;

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
        return $this->private;
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
