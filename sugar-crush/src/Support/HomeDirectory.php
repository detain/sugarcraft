<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

/**
 * Where `~` is, for every store, registry and importer in this package.
 *
 * ONE RESOLUTION, because two was a production bug and half a migration was
 * worse than either. This codebase used to read the home directory two ways:
 * `getenv('HOME')` (the config directory, the skill trees) and
 * `$_SERVER['HOME'] ?? '/root'` (the agent presets, the team/worktree stores,
 * the command loader, the workflow engine, the Claude memory importer). While
 * BOTH halves read `$_SERVER` they were consistently wrong together; once one
 * half moved to `getenv()` they could DISAGREE, and the disagreement is
 * reachable by the exact call the migration was motivated by — `putenv('HOME',
 * …)` moved `~/.claude/skills` and left `~/.claude/agents` behind. Two
 * subsystems reading one repository out of two different homes is a worse
 * failure than both reading it out of the wrong one.
 *
 * `getenv()` is the half that wins because it is the one a process can
 * actually set: `$_SERVER['HOME']` is populated once, from the environment
 * `php` started with, and `variables_order` without `S` empties it outright.
 *
 * FALLBACKS ALSO HAD TO CONVERGE. The two halves disagreed there as well —
 * `/root` on one side, `/tmp` on the other — and `/root` is the wrong
 * direction for a fallback to point: reading another user's home is worse than
 * reading nothing. So the passwd database is asked before either, because the
 * environment not SAYING where home is does not mean nobody knows, and the
 * last resort is the system temp directory rather than a hard-coded `/root`.
 *
 * SECURITY-relevant readers must NOT use the fallback at all: see
 * {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()}, which
 * refuses to resolve `~/.sugar-crush/config.json` or `hooks.yaml` out of a
 * world-writable stand-in.
 */
final class HomeDirectory
{
    /**
     * This user's home directory, or the least-surprising stand-in when
     * nothing can say.
     */
    public static function path(): string
    {
        return self::resolved() ?? sys_get_temp_dir();
    }

    /**
     * This user's home directory when it can actually be DETERMINED, or null.
     *
     * Null is "this process cannot tell whose home this is", which is a
     * different answer from any directory — the distinction a caller holding a
     * policy file rather than a cache needs.
     */
    public static function resolved(): ?string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: null);
        if (\is_string($home) && $home !== '') {
            return $home;
        }

        if (!\function_exists('posix_geteuid') || !\function_exists('posix_getpwuid')) {
            return null;
        }

        // `@`-silenced because the null return below IS the handling, and a
        // raw warning would land in the middle of the TUI's own output.
        $entry = @posix_getpwuid(posix_geteuid());
        $dir = \is_array($entry) ? ($entry['dir'] ?? null) : null;

        return \is_string($dir) && $dir !== '' ? $dir : null;
    }
}
