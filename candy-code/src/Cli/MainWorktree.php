<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Workspace\GitRunner;

/**
 * The `--worktree [name]` flag (C-AGT-6.c): the whole MAIN session — TUI,
 * one-shot `-p` run, everything downstream of this call — works inside a git
 * worktree rather than the checkout it was launched from.
 *
 * WHY HERE AND NOT IN Bootstrap. The swap is a ROOT swap decided before any
 * model, store or hook of the launch exists: `Bootstrap::app()` reads project
 * trust, `.mcp.json` and the instruction tree off its root argument, and
 * `NonInteractive` takes it from `$args->root` on the one-shot path. Building
 * the tree inside either door would mean two callers re-deciding it; `bin/
 * candy-code` calls {@see enter()} once, before both, and hands the returned
 * path back as the launch's root. Bootstrap stays untouched on purpose.
 *
 * THE TREE IS AN ORDINARY WorktreeManager ENTRY (`main-<name>` on branch
 * `candy-code/<name>`), not a parallel mechanism — the registry, the ignore
 * file under the base, and `hasWork()` are the ones sub-agent runs already
 * rely on, and the two id families cannot collide (`main-` prefix, named in
 * {@see WorktreeManager::mainAgentId()}).
 *
 * THE RELEASE LAW, which is the design: a `--worktree` session that produced
 * NOTHING (no commit, no uncommitted change — {@see WorktreeManager::
 * releaseIfUnused()}'s own test) must not litter the base directory, so exit
 * releases it; a session that produced work KEEPS both tree and branch and
 * SAYS SO on stderr, because quietly deleting an agent's output would be the
 * worse bug (roadmap 4.9's release law, inherited whole). The announce rides
 * a shutdown function — the one place that fires on every exit shape, `exit`
 * from -p, a `quit` Cmd, an exception unwinding past `Program::run()`.
 *
 * WHY THE SHUTDOWN HOOK CARRIES A PID GUARD. AgentWorkerPool fork children
 * inherit registered shutdown closures and `exit()` through them on every
 * completed stage; without the guard each child's exit would fire the parent
 * session's release — deleting the live tree out from under the still-running
 * parent. Same reason bin/candy-code's terminal-restore hook checks
 * `getmypid()` (1021 NEW-1); the by-ref/$released flag is the double-fire
 * half: normal completion and a fatal both reach shutdown in the parent.
 *
 * A REUSED tree (a second `candy-code --worktree same-name`) arms no hook:
 * the first session's law governs that tree, not this launch's exit.
 */
final class MainWorktree
{
    /**
     * A worktree name is a directory component AND a branch component, so it
     * is held to the union of what both allow: a letter or digit first (git
     * refs and our base paths both refuse a leading "-" or "."), then
     * letters, digits, ".", "-" and "_". `..` can never form under a
     * first-alnum rule with no slashes, but it is checked explicitly anyway
     * because {@see WorktreeManager::createWorktree()} refuses it as its own
     * contract and a name reaching that throw would print a git-flavoured
     * message for the user's typo.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * The launch's entered tree, or null when `--worktree` never ran (or
     * only reused an existing one). Holds a WorktreeManager instance so
     * release() judges the SAME registry state the entry wrote.
     *
     * @var array{manager: WorktreeManager, agentId: string, name: string, path: string, branch: string}|null
     */
    private static ?array $entered = null;

    private static bool $released = false;

    /**
     * Enter (creating if needed) the main-session worktree $name names,
     * answering the path the launch should run at.
     *
     * @param string|null $root the launch's project root, null for the cwd
     * @param string|null $name the flag's raw value, null for a bare flag
     *                          (auto-named from the clock plus entropy)
     *
     * @throws \RuntimeException outside a git work tree, or on a name the
     *                           ref/path rules refuse — bin/candy-code turns
     *                           this into the exit-2 usage path
     */
    public static function enter(?string $root, ?string $name): string
    {
        $cwd = ($root !== null && $root !== '') ? $root : (getcwd() ?: '.');

        // THE REFUSAL DOOR: --worktree is meaningless without a repository,
        // and creating one implicitly is not this flag's promise. Checked
        // through GitRunner — the package's bounded, env-scrubbed spawn —
        // exactly as WorktreeManager's own calls run.
        $inside = GitRunner::new($cwd)->run('rev-parse', '--is-inside-work-tree');
        if (!$inside['ok'] || trim($inside['stdout']) !== 'true') {
            throw new \RuntimeException(Lang::t('cli.worktree.not_git', ['path' => $cwd]));
        }

        // A subdirectory launch roots the WORKTREE family at the toplevel,
        // where the other worktree builders resolve it — `git worktree add`
        // runs from anywhere, but the manager's branch bookkeeping and the
        // default base live at the top.
        $top = GitRunner::new($cwd)->run('rev-parse', '--show-toplevel');
        $repoRoot = trim($top['stdout']);
        if (!$top['ok'] || $repoRoot === '') {
            throw new \RuntimeException(Lang::t('cli.worktree.not_git', ['path' => $cwd]));
        }

        $name = self::resolveName($name);
        $agentId = WorktreeManager::mainAgentId($name);

        $manager = WorktreeManager::new($repoRoot);

        $registry = $manager->listWorktrees();
        if (isset($registry[$agentId])) {
            // An earlier session's tree with this name: reuse it, and arm NO
            // release hook — the law "your exit drops an unused tree" belongs
            // to whoever created it, and this launch created nothing.
            return $manager->worktreePath($agentId);
        }

        $path = $manager->createWorktree($agentId, WorktreeManager::mainBranchName($name));
        $branch = $manager->branchOf($agentId) ?? WorktreeManager::mainBranchName($name);

        self::$entered = ['manager' => $manager, 'agentId' => $agentId, 'name' => $name, 'path' => $path, 'branch' => $branch];
        self::$released = false;

        $installPid = getmypid();
        register_shutdown_function(static function () use ($installPid): void {
            // Forked children inherit this closure; the release law belongs
            // to the pid that installed it (class docblock, third paragraph).
            if (getmypid() !== $installPid) {
                return;
            }
            $announce = self::release();
            if ($announce !== null) {
                // \r\n on both sides: an abnormal exit can leave the terminal
                // raw, where a bare \n does not return the carriage — the
                // same reasoning as every other \r\n line in bin's shutdown
                // hooks. A clean one-shot run just sees a line break break.
                fwrite(STDERR, "\r\n" . $announce . "\r\n");
            }
        });

        return $path;
    }

    /**
     * The release step the shutdown hook performs, split out so a test can
     * drive it WITHOUT spawning a child to trigger shutdown: answer null when
     * the tree is gone (removed as unused, or never entered, or already
     * released), answer the announce line when it was kept.
     */
    public static function release(): ?string
    {
        $entered = self::$entered;
        if ($entered === null || self::$released) {
            return null;
        }
        self::$released = true;

        if ($entered['manager']->releaseIfUnused($entered['agentId'])) {
            return null;
        }
        if (!isset($entered['manager']->listWorktrees()[$entered['agentId']])) {
            // Gone without our help (pruned externally, or removed by a
            // `session` verb while the run lived): nothing to announce.
            return null;
        }

        return Lang::t('cli.worktree.kept_announce', [
            'path' => $entered['path'],
            'branch' => $entered['branch'],
            'name' => $entered['name'],
        ]);
    }

    /**
     * The bare-flag name: sortable, collision-resistant, and unmistakably
     * machine-made — clock to the second plus six hex of entropy.
     */
    public static function autoName(): string
    {
        return date('ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    /**
     * @throws \RuntimeException on a name the path and ref rules refuse
     */
    private static function resolveName(?string $name): string
    {
        if ($name === null) {
            return self::autoName();
        }
        $name = trim($name);
        if ($name === '' || str_contains($name, '..') || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \RuntimeException(Lang::t('cli.worktree.bad_name', ['name' => $name]));
        }

        return $name;
    }

    /**
     * Test seam ONLY: forget this process's entry so one test's tree cannot
     * decide another's release. Production code never calls it.
     *
     * @internal
     */
    public static function resetForTesting(): void
    {
        self::$entered = null;
        self::$released = false;
    }
}
