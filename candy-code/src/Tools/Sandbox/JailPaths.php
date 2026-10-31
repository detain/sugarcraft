<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tools\Sandbox;

/**
 * The escape hatches EVERY jail must keep shut, and the one repository file
 * it must never believe on its own word.
 *
 * This is the sandbox-independent half of {@see Bubblewrap}'s safety: the
 * lists of paths that stay read-only inside a writable root, and the
 * git-directory resolution (with its back-pointer check) that says WHICH
 * directories a command in this root writes through. Both backends share it —
 * a jail that binds the root writable but not the hatch rebinds is no jail at
 * all, whichever OS it runs on.
 *
 * THE `.git` FILE IS REPOSITORY CONTENT. A checkout that commits
 * `gitdir: /home/me` would, without the back-pointer check below, make any
 * jail bind the operator's home WRITABLE. The rule lives here, once, so
 * adding a second backend cannot mean auditing the trust logic a second time
 * in a second file.
 */
final class JailPaths
{
    /**
     * Paths under the writable root that are re-bound read-only, relative to
     * that root. See {@see Bubblewrap}'s class doc-block for why each one is
     * an escape hatch.
     */
    public const PROTECTED_PATHS = ['.candy-code', '.mcp.json'];

    /**
     * Paths re-bound read-only inside EVERY git directory the jail can write —
     * the root's own `.git`, or a linked worktree's gitdir and common dir —
     * relative to that git directory. One list for both shapes, so a worktree
     * cannot end up with a different set of escape hatches than a checkout.
     */
    public const PROTECTED_GIT_PATHS = ['hooks', 'config'];

    /**
     * The git directories a command in $root writes through: the root's own
     * `.git` directory for an ordinary checkout; for a LINKED worktree, whose
     * `.git` is a `gitdir:` file, that gitdir and the common dir above it.
     * [] outside a repository.
     *
     * THE `.git` FILE IS REPOSITORY CONTENT, so it is not believed on its own
     * word: a checkout committing `gitdir: /home/me` would otherwise have any
     * jail bind the operator's home WRITABLE. A linked gitdir is accepted only
     * when git's own back-pointer inside it (`<gitdir>/gitdir`) names this
     * root's `.git` — a file a clone cannot plant outside its own tree — and
     * the common dir only when it is the directory git keeps the registration
     * under (`<common>/worktrees/<name>`), never wherever `commondir` says.
     *
     * Keyed by directory; the value says whether it is a linked worktree's,
     * i.e. outside the root bind and in need of its own.
     *
     * @return array<string, bool>
     */
    public static function gitDirs(string $root): array
    {
        $dotGit = rtrim($root, '/') . '/.git';
        if (is_dir($dotGit)) {
            return [self::normalise($dotGit) => false];
        }
        if (!is_file($dotGit)) {
            return [];
        }
        $text = @file_get_contents($dotGit, length: 4096);
        if ($text === false || preg_match('/^gitdir:\s*(.+)$/m', $text, $m) !== 1) {
            return [];
        }
        $gitDir = self::absolute(trim($m[1]), $root);
        $backPointer = @file_get_contents($gitDir . '/gitdir', length: 4096);
        if (!is_dir($gitDir)
            || $backPointer === false
            || self::absolute(trim($backPointer), $gitDir) !== self::normalise($dotGit)
        ) {
            return [];
        }

        $commonDir = self::normalise(\dirname($gitDir, 2));
        if (basename(\dirname($gitDir)) !== 'worktrees' || !is_file($commonDir . '/HEAD')) {
            return [$gitDir => true];
        }

        return [$gitDir => true, $commonDir => true];
    }

    /** Realpath when possible, trailing slash off, '/' left as '/'. */
    public static function normalise(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $real = realpath($path);
        $path = $real === false ? $path : $real;

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private static function absolute(string $path, string $base): string
    {
        $joined = str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
        $real = realpath($joined);

        return self::normalise($real === false ? $joined : $real);
    }
}
