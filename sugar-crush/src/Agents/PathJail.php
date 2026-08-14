<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Tools\PathJail as PathContainment;
use SugarCraft\Crush\Tools\PathJailInterface;

/**
 * Path isolation layer that constrains file operations to an agent's worktree.
 *
 * This is the BOUND half of sugar-crush's two-part path-containment design: it
 * holds one root (the sub-agent's git worktree) plus its {@see PathJailConfig},
 * and delegates every containment decision to the stateless algorithm in
 * {@see PathContainment} (`SugarCraft\Crush\Tools\PathJail`). It deliberately
 * carries no containment logic of its own, so the worktree jail and the
 * workspace jail can never drift apart — see {@see PathJailInterface} for the
 * full rationale on why the two types stay separate, and for which method to
 * reach for.
 *
 * Prefer {@see resolve()}/{@see resolveForCreate()}/{@see resolveDir()}: one
 * call, and a non-null result is already proven to be inside the worktree.
 * {@see expandPath()} (formerly `jailPath()`) only joins — it proves nothing.
 *
 * @see https://github.com/sugarcraft/sugar-crush/blob/master/crush_code_plan.md#path-isolation-layer
 */
final class PathJail implements PathJailInterface
{
    public function __construct(
        private readonly string $agentWorktreePath,
        private readonly PathJailConfig $config,
    ) {}

    public function root(): string
    {
        return $this->agentWorktreePath;
    }

    /**
     * The jail's configuration — an INTENTIONALLY DORMANT seam, no caller yet.
     *
     * {@see PathJailConfig} is currently an empty marker, so this accessor
     * answers with something that carries no information, and it would be easy
     * to read the pair as dead code and delete it. It is not: the constructor
     * has always required a config, every construction site already passes one,
     * and this is the only way to read it back. It is reserved for the
     * per-agent policy that belongs on the jail rather than in the containment
     * algorithm — read-only worktrees, allow/deny path globs layered on top of
     * the root, a size ceiling — i.e. the knobs that vary per sub-agent and so
     * cannot live in the stateless {@see PathContainment}. Removing the
     * accessor would mean re-plumbing the config through every call site the
     * day the first knob lands, for no gain today.
     */
    public function config(): PathJailConfig
    {
        return $this->config;
    }

    /**
     * Prepend the worktree path if the given path is relative — UNCHECKED.
     *
     * Absolute paths are returned unchanged and `..` segments are left intact,
     * so the result is a candidate path and not a permission. Pair it with
     * {@see isAllowed()}, or skip both and call {@see resolve()} instead.
     */
    public function expandPath(string $path): string
    {
        if ($path === '') {
            return $this->agentWorktreePath;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->agentWorktreePath . '/' . $path;
    }

    /**
     * Historical name for {@see expandPath()}, retained for existing callers.
     *
     * `jailPath()` reads like it enforces the jail; it does not, which is the
     * footgun crush_code.md P8.14 flagged. The behaviour is unchanged — only
     * the honest name is new. Every in-tree call site has since migrated
     * (Read/Edit/Write to {@see resolve()}/{@see resolveForCreate()}, Bash to
     * {@see root()}), so this survives purely as a compatibility alias for
     * out-of-tree callers; it is deliberately NOT deleted, because deleting it
     * would break them for a rename that gains them nothing.
     *
     * @deprecated Use {@see expandPath()} for the unchecked join, or better,
     *             {@see resolve()}/{@see resolveForCreate()}/{@see resolveDir()},
     *             which prove containment and return a canonical path in one call.
     */
    public function jailPath(string $path): string
    {
        return $this->expandPath($path);
    }

    /**
     * Check whether the given EXISTING path resolves to a location inside the
     * worktree.
     *
     * Existence-strict: a path that does not exist answers false, because
     * `realpath()` has nothing to resolve and containment therefore cannot be
     * proven. For file creation use {@see resolveForCreate()}, which anchors on
     * the nearest ancestor that does exist instead of probing by hand.
     *
     * The containment test itself is {@see PathContainment::resolve()}, which
     * also canonicalises the worktree root — so a worktree reached through a
     * symlinked temp directory is judged correctly, where the previous raw
     * string prefix comparison would have rejected everything inside it.
     *
     * ## What "equivalent to the old check" is scoped to
     *
     * Replayed against the hand-rolled `realpath()`-vs-raw-prefix predicate
     * this replaced, the pair `resolve() !== null && file_exists()` gives
     * IDENTICAL verdicts on every path of a 44-path corpus — but only when the
     * configured root is ALREADY CANONICAL. Read that number as scoped, not as
     * unconditional: with a root reached through a symlink, or one carrying a
     * trailing slash, the same corpus diverges on 17 paths each, every one of
     * them OLD=DENY → NEW=ALLOW, and every newly-allowed target canonicalising
     * inside `realpath($root)`. That is the strengthening, not a loosening: the
     * old predicate compared against the raw configured string, so a jail
     * spelled `/tmp/link` (→ `/tmp/real`) denied every file inside its OWN
     * worktree. Nothing outside `realpath($root)` was newly allowed in either
     * direction.
     */
    public function isAllowed(string $path): bool
    {
        $resolved = PathContainment::resolve($this->agentWorktreePath, $path);

        // resolve() lets a not-yet-existing file through when its parent is in
        // the jail; isAllowed()'s older, narrower contract does not, and Write
        // depends on that distinction to route creation at resolveForCreate().
        return $resolved !== null && file_exists($resolved);
    }

    public function resolve(string $path): ?string
    {
        return PathContainment::resolve($this->agentWorktreePath, $path);
    }

    public function resolveForCreate(string $path): ?string
    {
        return PathContainment::resolveForCreate($this->agentWorktreePath, $path);
    }

    public function resolveDir(string $path): ?string
    {
        return PathContainment::resolveDir($this->agentWorktreePath, $path);
    }
}
