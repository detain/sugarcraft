<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Permissions;

use SugarCraft\Crush\Tools\PathJail;

/**
 * Where a write would land, relative to the project root — the one judgement
 * both {@see PermissionGate}'s `accept-edits` grant and {@see SafetyClassifier}'s
 * `auto` mode make about a write target.
 *
 * Three answers, and the middle one is the reason this is not a bool:
 *
 * - {@see INSIDE}: strictly below the root and not on a protected segment —
 *   an ordinary edit.
 * - {@see PROTECTED}: below the root by spelling or resolution, but under
 *   `.git`, `.sugar-crush` or `.mcp.json` ({@see PROTECTED_SEGMENTS}). Inside
 *   the directory is not the same as ordinary (audit F-J4).
 * - {@see OUTSIDE}: everything else — a path that escapes the root, the root
 *   itself, an absolute path when there is no root to compare it with, and a
 *   target that is absent, empty or not a string. Fail-closed: "cannot show it
 *   is inside" is reported as outside, never as inside.
 *
 * ONE COPY, TWO CALLERS, and that is why it moved out of `PermissionGate`.
 * Before audits F-P4/F-P3(b) only the `accept-edits` shell grant
 * (`mkdir`/`touch`/`rmdir` via `Bash`) asked this question, privately. Once the
 * `Edit`/`Write` tools and the `auto` classifier ask it too, a second private
 * copy of the protected-segment list is a policy that can drift: a segment
 * added for one mode and not the other would be a write the user believes is
 * guarded and is not in exactly one mode.
 *
 * HOW A PATH IS READ depends on whether the caller knows the root:
 *
 * - WITH a root (the live hook chain hands one in, from
 *   `HookContext::$projectRoot`), the path is resolved the way the
 *   `Write`/`Edit` tools resolve it — {@see PathJail::resolveForCreate()},
 *   anchored at the root, symlinks followed, a missing tail trusted only below
 *   a real ancestor — and the protected-segment check runs on BOTH the spelling
 *   and the resolved path relative to the root, so neither `./.git/x` nor a
 *   symlink `notes -> .git` passes as ordinary.
 * - WITHOUT a root (a bare embedder's gate), only the SPELLING can
 *   be judged: a relative path that stays strictly below the working directory
 *   LEXICALLY is inside, and an absolute or `~` path is outside, since nothing
 *   here knows which directory it would have to be inside of. Symlinks are not
 *   resolved on this branch — the same honest limit the `accept-edits` shell
 *   grant has always carried, and the tool's own path jail is what contains the
 *   write where it runs.
 */
final class WritePathScope
{
    public const INSIDE = 'inside';
    public const PROTECTED = 'protected';
    public const OUTSIDE = 'outside';

    /**
     * Path segments that are inside the working directory by spelling and
     * still not "an ordinary write" (audit F-J4) — matched against EVERY
     * segment, so `.git`, `./.git/hooks/x`, `a/.git/b` and `./sub/.git` all
     * count.
     *
     *  - `.git`: the repository's own machinery. `cp ./payload.sh
     *    ./.git/hooks/pre-commit` auto-ran under `accept-edits` and planted
     *    code that executes on the user's next `git commit`, outside any
     *    sugar-crush session; `rm ./.git/index`, `mv ./x ./.git/HEAD` and a
     *    nested `mkdir ./sub/.git` are no more "an edit" than that is.
     *    {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} denies the
     *    hooks/info half outright in every mode; this is the wider, cheaper
     *    half — one prompt for anything that touches `.git` at all.
     *  - `.sugar-crush`: hooks, agent presets, rules, skills and the
     *    permission settings tiers all load from it. The hook's write-only
     *    patterns name individual files, so `cp ./hooks.yaml ./.sugar-crush/`
     *    (a DIRECTORY target) and `cp ./x ./.sugar-crush/settings.json` slipped
     *    past both layers.
     *  - `.mcp.json`: every entry is a command the next launch spawns as a
     *    stdio MCP server.
     *
     * NOT LISTED, AND STILL GRANTED: `.claude/` and `.opencode/` (foreign agent
     * presets and skills are discovered from them) and anything else in the
     * policy-file surface the audit tracks as known #9 — closing that surface
     * is a policy-file inventory, not a line in this list. The grant here is
     * not the last word for their skill, agent and command directories:
     * {@see \SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook} asks before
     * every write to those in every permission mode (step 0.8b).
     *
     * Matched with `fnmatch()` and FNM_PERIOD, the way bash matches a glob to a
     * dotfile (writing STAR for the asterisk so this docblock does not close on
     * itself): `./.gSTAR/hooks/x` and `./.gi?/x` name `.git` to the shell —
     * globs are NOT expanded before this check — while `./STAR/x` and
     * `./?git/x` do not, so `touch ./STAR` keeps its grant. Lowercased first
     * because on a case-insensitive filesystem `.GIT` is the repository; on
     * Linux that widening costs one prompt for a name nobody uses. A QUOTED
     * glob is literal to bash but arrives here quote-stripped, so it is refused
     * too — the fail-closed direction. The `Write`/`Edit` tools never glob, so
     * for them a segment like `.g*` over-protects a file literally named that;
     * the same fail-closed direction.
     *
     * @var list<string>
     */
    public const PROTECTED_SEGMENTS = ['.git', '.sugar-crush', '.mcp.json'];

    /**
     * Classify a write target. See the class doc-block for the three answers
     * and for how the root changes the reading.
     *
     * @param mixed $path the raw `file_path` argument — deliberately untyped,
     *        because it comes straight off a model-emitted call and a non-string
     *        is a real input this must answer (OUTSIDE), not a TypeError.
     */
    public static function of(mixed $path, ?string $projectRoot): string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            return self::OUTSIDE;
        }

        // Containment is settled FIRST so an absolute path elsewhere that
        // happens to cross a `.git` (`/other/repo/.git/x`) reads as what it
        // is — outside — rather than as a protected file of this project.
        if ($projectRoot === null || $projectRoot === '') {
            if (!self::isContainedRelativePath($path)) {
                return self::OUTSIDE;
            }

            return self::namesProtectedSegment($path) ? self::PROTECTED : self::INSIDE;
        }

        // `~` is not expanded by the tools (PathJail reads `~/x` as a relative
        // `<root>/~/x`), but a model writing `~/…` means the home directory and
        // the shell grant has always refused the spelling; agree with it.
        if (str_starts_with($path, '~')) {
            return self::OUTSIDE;
        }

        $rootReal = realpath($projectRoot);
        $resolved = PathJail::resolveForCreate($projectRoot, $path);
        if ($rootReal === false || $resolved === null || $resolved === $rootReal) {
            return self::OUTSIDE;
        }

        // Both readings: a RELATIVE spelling (`./.g*/x`, a glob no real
        // directory resolves) and the resolved path below the root (a symlink
        // `notes -> .git`, or `sub/../.git/x`). An absolute spelling is judged
        // only through the resolved remainder: its leading segments are the
        // root's own, and a root that happens to sit under a directory named
        // `.git` must not make every file in the project protected.
        $relative = substr($resolved, strlen(rtrim($rootReal, '/')) + 1);
        $spellingProtected = !self::isAbsolutePath($path) && self::namesProtectedSegment($path);

        return $spellingProtected || self::namesProtectedSegment($relative)
            ? self::PROTECTED
            : self::INSIDE;
    }

    /**
     * Does any segment of `$path` name a {@see PROTECTED_SEGMENTS} entry, under
     * bash's dotfile glob semantics? See that constant.
     */
    public static function namesProtectedSegment(string $path): bool
    {
        foreach (explode('/', strtolower($path)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            foreach (self::PROTECTED_SEGMENTS as $name) {
                if (fnmatch($segment, $name, FNM_PERIOD)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does `$path` name something strictly below the working directory?
     *
     * Resolved LEXICALLY — no filesystem access — because the paths this
     * approves routinely do not exist yet (`mkdir ./x` is the whole point) and
     * `realpath()` returns false for those. Each `..` pops one segment; a `..`
     * with nothing to pop means the path escapes (even if it later re-descends:
     * `../sibling/x` is outside), and a path that resolves to depth 0 IS the
     * working directory rather than something inside it, so `rmdir .` prompts.
     */
    public static function isContainedRelativePath(string $path): bool
    {
        if ($path === '' || self::isAbsolutePath($path)) {
            return false;
        }

        $depth = 0;

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($depth === 0) {
                    return false;
                }
                --$depth;
                continue;
            }

            ++$depth;
        }

        return $depth > 0;
    }

    /**
     * `/…` and `~…` (which bash expands to a home directory before the command
     * runs) both name somewhere this lexical check cannot place below the root.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '~');
    }
}
