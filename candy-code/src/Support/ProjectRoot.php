<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Core\Util\LegacyPaths; // @rename-keep
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

/**
 * The directory a launch's `.candy-code/*` lookups resolve against (audit
 * 15d-13 (b)), as opposed to the working directory the tools are jailed to.
 *
 * WHY THE TWO DIFFER. `cd repo/src && candy-code` used to look for the
 * project's settings, skills, rules, commands, workflows, agent presets,
 * hooks, `.mcp.json` and memory at `repo/src/.candy-code`, where there are
 * none, so a trusted repository's `.candy-code/settings.json` was silently
 * ignored one directory down. The working directory is still the launch
 * directory — Bash, Read and the other tools see exactly what they saw — and
 * only those lookups walk up.
 *
 * THE RULE, in order:
 *
 *  1. The launch directory itself, when it holds a `.candy-code/` directory,
 *     a `.mcp.json` or a `.git` — every launch that worked before resolves
 *     to the same directory, with no subprocess.
 *  2. Otherwise git is asked for the enclosing work tree
 *     (`git rev-parse --show-toplevel`, bounded at {@see GIT_TIMEOUT_SECONDS},
 *     the same prefix and lock rule as the environment block's git reads).
 *     Outside any work tree, or when git cannot answer, the launch directory
 *     is the root: there is nothing to bound a walk with, and an unbounded
 *     walk from `~/work/x` reaches `~/.candy-code`, which is the USER's
 *     config directory, not a project's.
 *  3. Inside a work tree, the nearest directory between the launch directory
 *     and the work-tree root that holds `.candy-code/` or `.mcp.json` wins
 *     — a monorepo package with its own `.candy-code/` keeps it — and the
 *     work-tree root otherwise.
 *
 * A WORK TREE AT OR ABOVE THE HOME DIRECTORY IS NOT WALKED. A dotfiles
 * repository rooted at `~` (or a checkout of `/`) would otherwise make
 * `~/.candy-code` — the user tier — the PROJECT tier of every directory under
 * it. Such a launch keeps its own directory as the root, as before.
 *
 * Memoised per process by the spelled directory: a launch asks once per
 * entry point and per loader, and the answer cannot change under a running
 * session without a `git init` nobody expects it to notice. The result is
 * idempotent — resolving a resolved root answers the same root — so a loader
 * that resolves again what Bootstrap already resolved costs a hash lookup.
 */
final class ProjectRoot
{
    use CapturesProcessOutput;

    /** The bound on the one git read, matching the environment block's. */
    public const GIT_TIMEOUT_SECONDS = 2.0;

    /**
     * The entries that make a directory a project root of its own. `.git` is
     * a file in a linked worktree or a submodule and a directory otherwise,
     * so presence is what is tested, not type.
     */
    public const MARKERS = ['.candy-code', '.mcp.json', '.git'];

    /**
     * The git prefix of {@see \SugarCraft\Code\Context\EnvironmentBlock}'s
     * reads (audit 15d-12): the user's git config must not change the answer,
     * run a pager, or take the index lock.
     */
    private const GIT_GLOBAL_OPTIONS = ['--no-pager', '--no-optional-locks', '-c', 'color.ui=false', '-c', 'core.quotepath=false'];

    /** Bytes of `--show-toplevel` output read; a path longer than this is not trusted. */
    private const MAX_TOPLEVEL_BYTES = 8192;

    /** @var array<string, string> spelled directory => resolved root */
    private static array $memo = [];

    private function __construct() {}

    /**
     * The root `.candy-code/*` lookups use for a launch at $directory.
     *
     * An empty string, a directory that does not exist, and a launch outside
     * any work tree all answer $directory unchanged, spelled as given, so no
     * caller sees a different string where nothing was walked.
     */
    public static function resolve(string $directory): string
    {
        if ($directory === '' || !is_dir($directory)) {
            return $directory;
        }

        $root = self::$memo[$directory] ??= (new self())->walk($directory);
        self::migrateLegacyDir($root);

        return $root;
    }

    /**
     * One-time move of the pre-rename project state directory, // @rename-keep
     * `<root>/.sugar-crush` → `<root>/.candy-code`. // @rename-keep
     *
     * Hooked here because this method is the single choke point every
     * `<root>/.candy-code` consumer walks through (settings, skills, rules,
     * commands, memory, plans, worktrees all resolve their root via it), and
     * the trust lists key on the ROOT (`trustedProjectHooks` et al. store
     * root paths) — a moved directory therefore stays trusted without any
     * re-approval, exactly as the home-level move keeps `policy-snapshot.json`
     * state-neutral because hook identities name|event|command survive a
     * rename. Never merges: when both directories exist,
     * {@see LegacyPaths::migrate()} refuses and the user decides.
     *
     * The notice rides {@see RuntimeNoticeSink::record()} rather than stderr:
     * unlike {@see \SugarCraft\Code\Cli\LegacyMigration::run()} this can fire
     * mid-session (first lookup of a freshly cloned repo), where the TUI owns
     * the screen and a raw write would tear the frame. It drops while the
     * sink is unarmed — the headless paths print no transcript rows.
     */
    private static function migrateLegacyDir(string $root): void
    {
        $legacy = $root . '/.sugar-crush'; // @rename-keep
        if (!is_dir($legacy)) {
            return;
        }

        $notice = LegacyPaths::migrate($legacy, $root . '/.candy-code');
        if ($notice === null) {
            return;
        }

        // On success, the fixed advice sentence replaces the shim's
        // full-path line — the docs quote this exact sentence and the
        // relative spelling is the actionable one. A failed move passes
        // through verbatim: its reason matters more than the advice.
        RuntimeNoticeSink::record(str_starts_with($notice, 'moved ')
            ? 'moved .sugar-crush -> .candy-code (if this project tracks it in git, commit the move)' // @rename-keep
            : $notice);
    }

    /**
     * Forget every memoised answer. For tests that build a tree, resolve it,
     * then change which markers it holds.
     */
    public static function forget(): void
    {
        self::$memo = [];
    }

    private function walk(string $directory): string
    {
        if (self::holdsMarker($directory)) {
            return $directory;
        }

        $top = $this->toplevel($directory);
        $start = realpath($directory);
        if ($top === null || $start === false || !self::isAtOrBelow($start, $top) || self::coversHome($top)) {
            return $directory;
        }

        // Nearest first: the launch directory itself was tested above, so the
        // walk starts at its parent and stops AT the work-tree root.
        for ($dir = \dirname($start); self::isAtOrBelow($dir, $top) && $dir !== $top; $dir = \dirname($dir)) {
            if (self::holdsMarker($dir, withGit: false)) {
                return $dir;
            }
        }

        return $top;
    }

    /**
     * The enclosing work tree's root, canonicalised, or null when git says
     * there is none, cannot run, or does not answer in time.
     */
    private function toplevel(string $directory): ?string
    {
        if (!\function_exists('proc_open')) {
            return null;
        }

        $command = 'git';
        foreach ([...self::GIT_GLOBAL_OPTIONS, '-C', $directory, 'rev-parse', '--show-toplevel'] as $word) {
            $command .= ' ' . escapeshellarg($word);
        }

        $captured = $this->runCaptured($command, null, self::MAX_TOPLEVEL_BYTES, self::GIT_TIMEOUT_SECONDS, ['GIT_OPTIONAL_LOCKS' => '0']);
        if ($captured['timedOut'] || $captured['exitCode'] !== 0 || $captured['truncatedBytes'] > 0) {
            return null;
        }

        // Exactly one trailing newline is git's terminator; anything else is
        // part of the path.
        $out = $captured['stdout'];
        $out = str_ends_with($out, "\n") ? substr($out, 0, -1) : $out;
        if ($out === '' || !str_starts_with($out, '/')) {
            return null;
        }

        $real = realpath($out);

        return $real === false ? null : $real;
    }

    private static function holdsMarker(string $directory, bool $withGit = true): bool
    {
        foreach (self::MARKERS as $marker) {
            if (!$withGit && $marker === '.git') {
                continue;
            }
            if (file_exists($directory . '/' . $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether canonical $path is $root or one of its descendants, decided by
     * walking $path's parents rather than by a string prefix — both sides are
     * already canonical, so no resolution is needed, and a sibling sharing a
     * prefix (`/repo-old` beside `/repo`) is not a descendant.
     */
    private static function isAtOrBelow(string $path, string $root): bool
    {
        while (true) {
            if ($path === $root) {
                return true;
            }
            $parent = \dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }
    }

    /**
     * Whether $top is the home directory or one of its ancestors — see the
     * class doc-block. An unknowable home answers true: refusing the walk is
     * the pre-15d-13 (b) behaviour, never a widening.
     */
    private static function coversHome(string $top): bool
    {
        $home = HomeDirectory::resolved();
        if ($home === null) {
            return true;
        }
        $home = realpath($home);
        if ($home === false) {
            return true;
        }

        return self::isAtOrBelow($home, $top);
    }
}
