<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * The contract every *bound* path jail in sugar-crush answers to.
 *
 * ## Why there are two PathJail classes
 *
 * sugar-crush contains two path-containment types, and until crush_code.md
 * item P8.14 they had no stated relationship, so a caller had to know which
 * one it was holding to know whether a returned path had been checked:
 *
 * - {@see PathJail} (`SugarCraft\Crush\Tools\PathJail`) — the containment
 *   ALGORITHM. Stateless and static; the jail root is passed per call. It is
 *   the single place in the codebase where "is this path inside that root?"
 *   is actually decided.
 * - {@see \SugarCraft\Crush\Agents\PathJail} — a jail BOUND to one root (a
 *   sub-agent's git worktree) plus its {@see \SugarCraft\Crush\Agents\PathJailConfig}.
 *   It carries no containment logic of its own: every decision is delegated to
 *   the algorithm above, so the two can no longer answer differently.
 *
 * They are kept as separate types deliberately rather than collapsed into one.
 * The algorithm is used with a root that changes per call site (the workspace
 * root, threaded in from `--root`), while the worktree jail is a value handed
 * to a tool at construction time and outlives any single call. A static class
 * cannot also expose same-named instance methods in PHP, so the algorithm
 * cannot itself implement this interface — and should not: it has no root to
 * be bound to.
 *
 * ## Why this interface lives in `Tools\` and its implementer in `Agents\`
 *
 * That looks backwards at first glance, and it is on purpose. The contract
 * belongs beside the algorithm that satisfies it, because `Tools\` is where
 * containment is DEFINED and where every consumer lives: Read/Edit/Write/Bash/
 * Glob/Grep all sit in `Tools\BuiltIn\` and are typed against this interface,
 * whether they were handed a worktree jail or a bare `--root`. `Agents\` is
 * one PROVIDER of a bound jail — the sub-agent worktree — and if a second ever
 * appears (a sandbox root, a read-only review checkout) it implements this
 * same interface from wherever it lives, without `Tools\` having to learn
 * about it. Putting the interface in `Agents\` would invert that: the tool
 * layer, which owns the rule, would depend on the agent layer, which merely
 * supplies a root.
 *
 * ## Which method to call
 *
 * The three `resolve*()` methods are the SAFE, fail-closed API: one call, and
 * the returned string is both canonical and proven to be inside the jail —
 * `null` means "rejected, do not touch this path". Prefer them always. Pick by
 * what the caller intends to do with the path:
 *
 * - {@see resolve()}          — read or modify a file that should already exist.
 * - {@see resolveForCreate()} — create a file that may not exist yet, at any
 *                               depth of not-yet-existing parent directories.
 * - {@see resolveDir()}       — walk or search a directory that must exist.
 *
 * {@see expandPath()} and {@see isAllowed()} are the low-level primitives the
 * worktree jail has always exposed. `expandPath()` performs NO containment
 * check whatsoever — it joins and nothing more — so it is only safe when its
 * result is passed through `isAllowed()` before use. That must-be-paired split
 * is the footgun P8.14 names; `jailPath()` was renamed to `expandPath()` so the
 * unchecked nature is legible at the call site, and the old name is retained as
 * a deprecated delegating alias so no out-of-tree caller breaks. Every in-tree
 * caller has since moved OFF the pairing entirely: Read/Edit/Write resolve, and
 * Bash asks {@see root()} for the directory it wants to `cd` into. That matters
 * beyond naming — the pairing checked the realpath() of a path and then opened
 * the UNRESOLVED one, so a symlink component swapped in between was read from a
 * different file than the one containment was proved on.
 *
 * ## Guardrail
 *
 * `tests/Tools/PathJailContractParityTest.php` runs one shared escape corpus
 * through BOTH the static algorithm and a bound jail rooted at the same
 * directory and asserts identical verdicts. Any future divergence — a second
 * containment implementation, a loosened check on one side — fails there rather
 * than silently handing one subsystem a weaker jail than the other.
 */
interface PathJailInterface
{
    /**
     * The directory this jail is bound to, exactly as it was configured.
     *
     * Not necessarily canonical — callers that need a canonical root should
     * take it from a `resolve*()` return value instead.
     */
    public function root(): string;

    /**
     * Join a relative path onto the jail root WITHOUT checking containment.
     *
     * Absolute paths are returned untouched, `..` segments are left in place,
     * and symlinks are not resolved. The result is a candidate, not a verdict:
     * it is only safe once {@see isAllowed()} has accepted it, or when it is
     * being used purely as a display/`cd` target rather than as a file to open.
     */
    public function expandPath(string $path): string;

    /**
     * Whether an EXISTING path resolves to a location inside the jail.
     *
     * Existence-strict by design: a path that does not exist answers false,
     * because there is nothing to `realpath()` and therefore nothing to prove.
     * That makes this predicate wrong for file creation — use
     * {@see resolveForCreate()} there rather than probing ancestors by hand.
     */
    public function isAllowed(string $path): bool;

    /**
     * Resolve a path for reading or modifying, or null if it escapes the jail.
     *
     * Accepts a file that does not exist yet only when its immediate parent
     * does — that parent is the containment anchor.
     */
    public function resolve(string $path): ?string;

    /**
     * Resolve a path that may not exist yet, at any depth, or null if it escapes.
     *
     * Missing parent directories are permitted: `..` is collapsed lexically
     * before any filesystem lookup, so traversal cannot be laundered through a
     * directory that does not exist.
     */
    public function resolveForCreate(string $path): ?string;

    /**
     * Resolve an existing directory, or null if it escapes the jail.
     */
    public function resolveDir(string $path): ?string;
}
