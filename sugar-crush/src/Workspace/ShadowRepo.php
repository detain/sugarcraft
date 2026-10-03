<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workspace;

/**
 * The private git directory workspace checkpoints use for a project that is
 * not a git work tree (item 3.A-1; opencode's snapshot dir, Kilo/Cline's
 * shadow repository).
 *
 * WHERE IT LIVES. `<base>/<sha1(realpath(root))>`, where `<base>` is the
 * `checkpoints/` directory beside the session database — so the default
 * store keeps them under `~/.sugar-crush/checkpoints/`, and a store built on a
 * scratch path keeps them in that scratch tree, exactly as its lock files do.
 * The project directory itself is never written to: the git data lives
 * outside it and every call names the project as `--work-tree`. A
 * `sugar-crush-root` file inside records which directory a hash belongs to.
 *
 * WHAT IT REFUSES. A base directory INSIDE the project (the snapshot would
 * contain its own object store and grow by itself every turn), and a base
 * that cannot be created owner-only.
 */
final class ShadowRepo
{
    /** Name of the file recording the work tree a shadow directory serves. */
    public const ROOT_MARKER = 'sugar-crush-root';

    private function __construct(
        private readonly string $gitDir,
        private readonly string $workTree,
    ) {}

    /**
     * The shadow repository for $root under $baseDir, or null when $root is
     * not an existing directory.
     */
    public static function forRoot(string $root, string $baseDir): ?self
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        return new self(rtrim($baseDir, '/') . '/' . sha1($real), $real);
    }

    public function gitDir(): string
    {
        return $this->gitDir;
    }

    public function workTree(): string
    {
        return $this->workTree;
    }

    /**
     * Why this shadow repository cannot be used, or null when it can.
     */
    public function refusal(): ?string
    {
        // The nearest existing ancestor of the store, resolved, then walked
        // up: the store is inside the project when the project is on that walk.
        $base = \dirname($this->gitDir);
        $probe = $base;
        while (!is_dir($probe) && \dirname($probe) !== $probe) {
            $probe = \dirname($probe);
        }
        $probe = realpath($probe);
        if ($probe === false) {
            return null;
        }
        for (; ; $probe = \dirname($probe)) {
            if ($probe === $this->workTree) {
                return 'the checkpoint store ' . $base . ' is inside the project, which would snapshot itself';
            }
            if ($probe === \dirname($probe)) {
                return null;
            }
        }
    }

    /**
     * Create the repository on first use, with $excludes as its
     * `info/exclude`. Null on success, the reason otherwise.
     *
     * @param list<string> $excludes
     */
    public function ensure(GitRunner $git, array $excludes): ?string
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        if (is_file($this->gitDir . '/HEAD')) {
            return null;
        }

        $base = \dirname($this->gitDir);
        if (!is_dir($base) && !@mkdir($base, 0o700, true) && !is_dir($base)) {
            return 'could not create the checkpoint store ' . $base;
        }
        if (!is_dir($this->gitDir) && !@mkdir($this->gitDir, 0o700, true) && !is_dir($this->gitDir)) {
            return 'could not create the shadow repository ' . $this->gitDir;
        }

        $runner = $git->withGitDir($this->gitDir, $this->workTree);
        $init = $runner->run('init', '-q');
        if (!$init['ok']) {
            return 'git init failed for the shadow repository: ' . self::why($init);
        }
        foreach (['core.autocrlf' => 'false', 'core.bare' => 'false', 'gc.auto' => '0'] as $key => $value) {
            $set = $runner->run('config', $key, $value);
            if (!$set['ok']) {
                return 'git config ' . $key . ' failed for the shadow repository: ' . self::why($set);
            }
        }

        @mkdir($this->gitDir . '/info', 0o700, true);
        if (@file_put_contents($this->gitDir . '/info/exclude', implode("\n", $excludes) . "\n") === false) {
            return 'could not write the shadow repository\'s exclude file';
        }
        @file_put_contents($this->gitDir . '/' . self::ROOT_MARKER, $this->workTree . "\n");

        return null;
    }

    /**
     * @param array{stderr: string, exitCode: int, timedOut: bool} $result
     */
    private static function why(array $result): string
    {
        if ($result['timedOut']) {
            return 'timed out';
        }

        return trim($result['stderr']) !== '' ? trim($result['stderr']) : 'exit ' . $result['exitCode'];
    }
}
