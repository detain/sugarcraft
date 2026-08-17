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
 * SECURITY-relevant readers must NOT use {@see path()} at all: see
 * {@see owned()}, and {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()},
 * which resolves `~/.sugar-crush/config.json` and `hooks.yaml` through it.
 *
 * THREE ANSWERS, not two, and the third one is new because the second was being
 * cited for a guarantee it never made. `resolved()` answers "is there a home
 * this process can NAME"; that is a different question from "is that directory
 * this user's", and `Bootstrap::agentPresets()` carried a comment claiming the
 * latter was checked when only the former was. MEASURED on this host before
 * {@see owned()} existed, with `HOME` pointed at a mode-1777 directory:
 *
 *     trustedConfigDirPath()          => <world-writable dir>/.sugar-crush
 *     agentPresets() user tier reads  => ["notmine"] body='OWNERSHIP-CLAIM-FALSE-BODY'
 *
 * i.e. exactly the read that method's own exception text says it refuses.
 */
final class HomeDirectory
{
    /**
     * This user's home directory, or the least-surprising stand-in when
     * nothing can say.
     *
     * NOT FOR POLICY OR PROMPT READS, and the sentence is here rather than only
     * in the caller because the fallback is `sys_get_temp_dir()` — mode 1777 on
     * every stock Linux, sticky bit included, and a sticky bit stops other users
     * DELETING entries, not CREATING them. A reader that resolves a policy file
     * or a prompt body through this can therefore be handed one a different
     * local user wrote. {@see owned()} is the resolution for those.
     *
     * WHICH READERS ARE STILL ON IT — the list below is ASSERTED against a
     * derivation over `src/`, not maintained by hand, and the previous hand list
     * is why. It claimed to be "every remaining reader named by grep" and was
     * wrong in three directions at once: it named NINE where `grep` finds TEN;
     * it named {@see \SugarCraft\Crush\Workflows\WorkflowEngine}, which does not
     * call this at all (its only mention is a `{@see}`, and the real caller is
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry}); and it omitted
     * {@see \SugarCraft\Crush\Cli\Bootstrap} and
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} entirely. It also
     * omitted {@see \SugarCraft\Crush\Memory\ForeignMemoryImporter}, whose user
     * tier put foreign memory bodies in the model's context off this resolution
     * — that one is now on {@see owned()}, which is what took the count from
     * eleven to ten. Drift in the sentence asserting there is none is the exact
     * defect this doc-block exists to remove, so
     * {@see \SugarCraft\Crush\Tests\Support\HomeDirectoryPathReaderInventoryTest}
     * fails in BOTH directions — a name here that does not call it, and a caller
     * in `src/` that is not named here.
     *
     * PROMPT-BEARING (the resulting bodies go in front of the model), four:
     * {@see \SugarCraft\Crush\Skills\SkillLoader},
     * {@see \SugarCraft\Crush\Skills\SkillDiscovery},
     * {@see \SugarCraft\Crush\Skills\ForeignSkillDiscovery},
     * {@see \SugarCraft\Crush\Commands\CommandLoader}.
     *
     * STORE LOCATION (the fallback is a convenience, not a trust decision), six:
     * {@see \SugarCraft\Crush\Agents\Team},
     * {@see \SugarCraft\Crush\Agents\TeamManager},
     * {@see \SugarCraft\Crush\Agents\Teammate},
     * {@see \SugarCraft\Crush\Agents\WorktreeManager},
     * {@see \SugarCraft\Crush\Workflows\WorkflowRegistry} (its `~` expansion) and
     * {@see \SugarCraft\Crush\Cli\Bootstrap} (its `homePath()`, which
     * {@see \SugarCraft\Crush\Cli\Bootstrap::trustedConfigDirPath()} deliberately
     * does NOT go through).
     *
     * The first group is the one that should migrate; it is four subsystems with
     * their own suites and it is not this change-set.
     */
    public static function path(): string
    {
        return self::resolved() ?? sys_get_temp_dir();
    }

    /**
     * This user's home directory when this process can establish that it IS
     * this user's, or null.
     *
     * THE CHECK, and its domain, because "ownership" was asserted for six
     * rounds by a method that performed no `stat` at all:
     *
     *  - the resolved home must EXIST and be a directory. `sys_get_temp_dir()`
     *    is never reached, because {@see resolved()} — not {@see path()} — is
     *    what this builds on;
     *  - it must not be WORLD-WRITABLE (`perms & 0002`). This is the clause that
     *    kills the measured escape: `/tmp` is 1777, and a `HOME` landing there
     *    lets any local user pre-create `.sugar-crush` and own the session's
     *    permission mode, hook chain and agent roster;
     *  - it must be owned by this process's EFFECTIVE uid. This is the clause
     *    that kills the other direction — a `HOME` exported at somebody else's
     *    0755 home directory.
     *
     * GROUP-WRITABLE IS DELIBERATELY ALLOWED. `umask 002` layouts give real home
     * directories mode 0775 with a per-user primary group, and refusing those
     * would break working installations to defend against a group the user is
     * already trusting. The residual is stated rather than hidden: a home
     * writable by a SHARED group is accepted by this check.
     *
     * NON-POSIX HOSTS DEGRADE TO THE PERMISSION CLAUSE ALONE. Without
     * `posix_geteuid()` there is no uid to compare against — PHP on Windows
     * reports `fileowner()` as 0 for everything — so the ownership clause is
     * skipped there rather than failing every launch closed. The threat it
     * covers is POSIX-shaped anyway: Windows' `sys_get_temp_dir()` is per-user.
     *
     * ACLs ARE INVISIBLE TO THIS, and the limit is written down rather than
     * left to be rediscovered: `fileperms()` reports the mode bits and nothing
     * else, so `setfacl -m user:mallory:rwx ~` on a `drwx------` home is
     * ACCEPTED here. It could not be measured on this host (`setfacl` is not
     * installed) and is stated by inspection of what `fileperms()` can see. The
     * same goes for anything else that grants write access outside the mode
     * bits — a bind mount of a shared directory over `$HOME`, or a filesystem
     * mounted without permission enforcement.
     *
     * Returns the home AS SPELLED, not its `realpath()`: the checks are made on
     * the resolved directory, but callers concatenate onto this and their own
     * tests, messages and refusal keys are written in the spelling the
     * environment gave. Resolving here would silently rewrite `/tmp/...` to
     * `/private/tmp/...` on macOS for every one of them.
     *
     * TWO SPELLINGS ARE EXCEPTIONS TO THAT, because they are the cases where
     * gate-on-resolved and return-as-spelled genuinely diverge:
     *
     *  - A RELATIVE `$HOME` IS REFUSED. `realpath('.')` gates a real directory
     *    while `'.'` names a different one the moment anything calls `chdir()`,
     *    and every consumer of this concatenates onto the answer. MEASURED with
     *    the cwd inside a checkout and `HOME=.`: `owned()` returned `"."`,
     *    `Bootstrap::trustedConfigDirPath()` returned `"./.sugar-crush"`, and
     *    `agentPresets(<some other project>)` handed that checkout's own
     *    `.sugar-crush/agents` the deliberately-privileged USER tier —
     *    `presets=["relhome"] mode=bypass-permissions refusals=[]`. A relative
     *    home is not a spelling worth preserving; it is one the caller has to
     *    fix, and refusing says so.
     *  - TRAILING SEPARATORS ARE STRIPPED. `HOME=/path/` produced `/path//.sugar-crush`
     *    in every derived path AND in every refusal key, so two launches of the
     *    same session keyed the same directory two ways. `/` is returned as
     *    itself, since stripping it leaves nothing.
     */
    public static function owned(): ?string
    {
        $home = self::resolved();
        if ($home === null) {
            return null;
        }

        // Windows spells absolute paths `C:\Users\x`, which has no leading
        // separator — hence the drive-letter arm rather than a bare
        // `str_starts_with($home, '/')`.
        $absolute = str_starts_with($home, '/')
            || str_starts_with($home, '\\')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $home) === 1;

        if (!$absolute) {
            return null;
        }

        $home = rtrim($home, '/') ?: '/';

        $real = realpath($home);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        $perms = @fileperms($real);
        if ($perms === false || ($perms & 0o002) !== 0) {
            return null;
        }

        if (!\function_exists('posix_geteuid')) {
            return $home;
        }

        $owner = @fileowner($real);

        return $owner === false || $owner !== posix_geteuid() ? null : $home;
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
