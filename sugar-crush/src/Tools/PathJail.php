<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * The path-containment ALGORITHM — the one place that decides whether a path
 * is inside a given root.
 *
 * Stateless and static: the root is a parameter, because the callers that use
 * it (Read/Edit/Write/Glob/Grep, jailed to the workspace `--root`) receive
 * that root per invocation rather than holding a jail object.
 *
 * Its counterpart is {@see \SugarCraft\Crush\Agents\PathJail}, a jail BOUND to
 * a sub-agent's git worktree, which implements {@see PathJailInterface} and
 * delegates every one of its decisions back here. Read `PathJailInterface`'s
 * class doc-comment before adding a third path check anywhere: it records why
 * the two types exist, which method to call for which intent, and the parity
 * test that keeps them from diverging.
 *
 * Every method here is fail-closed and returns an absolute path proven to be
 * inside `$root`, or `null` for "rejected". `null` is never "unknown" — a
 * caller may treat it as a hard denial.
 *
 * ## On PHP's realpath cache (deliberately NOT flushed here)
 *
 * `realpath()` answers out of a process-wide cache with a 120s default TTL, so
 * in a long-lived session it can describe a symlink as it was up to two minutes
 * ago. Every entry point below could open with `clearstatcache(true)`; none
 * does, and that is a decision rather than an oversight:
 *
 * 1. A stale answer cannot break containment of the RETURNED string. Each
 *    method hands back a path derived from what `realpath()` said, and a cached
 *    answer was inside the root at the moment it was computed — so staleness
 *    yields a stale but still-contained path, or a spurious denial. Never an
 *    escape.
 * 2. Nothing on the jailed surface can go stale in the first place. The five
 *    tools this guards (Read/Edit/Write/Glob/Grep) cannot create or retarget a
 *    symlink. The tool that can is `Bash`, which `PathJail` does not confine at
 *    all — it is handed a working directory, not a boundary — so an agent able
 *    to invalidate the cache can already read anything the process can.
 * 3. It is not free. `clearstatcache(true)` flushes the cache for the WHOLE
 *    process, not just for this call. Measured on this repo: the flush itself
 *    is ~0.8us, but it also costs Glob's own follow-up `realpath()` pass over
 *    its match list ~2.4ms per call at the 1,000-match cap (0.35ms warm vs
 *    2.78ms cold) — for a threat item 2 says is not reachable from here.
 *
 * If a future caller CAN retarget a component (a sandbox that shells out, a
 * jail handed to untrusted plugin code), revisit this: the cheap fix is a
 * `clearstatcache(true)` at the top of the three entry points, and the numbers
 * above are what it costs.
 */
final class PathJail
{
    /**
     * Whether a root/path pair is unusable as a filesystem path at all.
     *
     * A NUL byte can never appear in a real path, and `realpath()`/`is_dir()`
     * THROW a `ValueError` on one instead of returning false — so an unguarded
     * NUL turned a containment question into an uncaught crash inside whichever
     * tool asked it. Screening here keeps the algorithm fail-closed for every
     * caller, present and future, rather than making each one remember to
     * pre-validate. `null` here means the same as everywhere else in this
     * class: rejected, do not touch this path.
     */
    private static function unusable(string $root, string $path): bool
    {
        return str_contains($root, "\0") || str_contains($path, "\0");
    }

    /**
     * Resolve a path for reading or modifying, or null if it escapes `$root`.
     *
     * A file that does not exist yet is accepted only when its immediate parent
     * already does; that parent is the containment anchor. For paths whose
     * parents may also be missing, use {@see resolveForCreate()}.
     */
    public static function resolve(string $root, string $path): ?string
    {
        if (self::unusable($root, $path)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $fullPath = $path;
        } else {
            $fullPath = $rootReal . '/' . ltrim($path, '/');
        }
        $resolved = realpath($fullPath);

        if ($resolved === false) {
            $resolvedDir = realpath(dirname($fullPath));
            if ($resolvedDir !== false && (str_starts_with($resolvedDir, $rootReal . '/') || $resolvedDir === $rootReal)) {
                return $fullPath;
            }
            return null;
        }

        // The jail root itself is a valid in-jail path — accept an exact match
        // as well as any descendant. Requiring only the `$rootReal . '/'` prefix
        // rejected the root, which is an off-by-one against the jail boundary.
        if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal . '/')) {
            return null;
        }

        return $resolved;
    }

    /**
     * Resolve a path that is allowed NOT TO EXIST YET, including any number of
     * missing parent directories (crush_code.md Phase 8 item 12).
     *
     * {@see resolve()} accepts a missing FILE, but only when its immediate
     * parent already exists — `realpath(dirname($fullPath))` is its containment
     * anchor. That is exactly right for {@see \SugarCraft\Crush\Tools\BuiltIn\Edit},
     * which can only ever touch a file that is already there, and exactly wrong
     * for {@see \SugarCraft\Crush\Tools\BuiltIn\Write}: creating `docs/new/x.md`
     * would be reported as "outside workspace root" when it is plainly inside it.
     *
     * Containment is settled by normalizing `.`/`..` LEXICALLY first (so
     * `../../etc/passwd` cannot be laundered through a directory that does not
     * exist) and then realpath()ing the nearest ancestor that DOES exist. The
     * segments below that ancestor are safe to trust unresolved for the simple
     * reason that they do not exist, and a path that does not exist cannot be a
     * symlink pointing out of the jail — with one exception the walk itself has
     * to rule out, a DANGLING symlink, which does exist but is invisible to
     * is_dir() and realpath(). See the walk's own comment below.
     *
     * ## The walk starts at the LEAF, not at its parent
     *
     * That is a second, independent behaviour change from the version this
     * method replaced, and it is worth naming because the dangling-symlink
     * story above does not explain it. Starting at `dirname($normalized)`
     * skipped the leaf entirely, so the leaf was never examined by is_dir() or
     * is_link() at all. Starting AT the leaf denies five extra shapes, measured
     * against the same corpus (0 extra allows — the change is strictly
     * fail-closed):
     *
     * - `broke` — the dangling symlink named as the creation target itself,
     *   rather than as a component of a longer path.
     * - `nope/../sub`, `nope/../dir2`, `nope/../insym` — an EXISTING in-jail
     *   directory reached through a directory that does not exist. Lexical
     *   normalization lands the walk on the directory, which then anchors on
     *   itself and leaves an empty tail, and an empty tail is rejected. These
     *   were never legitimate Write targets anyway: the target is a directory.
     * - `nope/../broke` — the same route onto a dangling symlink.
     *
     * @return string|null The absolute path to write, or null if it escapes $root.
     */
    public static function resolveForCreate(string $root, string $path): ?string
    {
        if (self::unusable($root, $path)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false || $path === '') {
            return null;
        }

        $fullPath = str_starts_with($path, '/')
            ? $path
            : $rootReal . '/' . ltrim($path, '/');

        $resolved = realpath($fullPath);
        if ($resolved !== false) {
            // Already exists: settle it with the strict, fully-resolved check.
            return $resolved === $rootReal || str_starts_with($resolved, $rootReal . '/')
                ? $resolved
                : null;
        }

        $normalized = self::normalizeLexically($fullPath);

        // Walk up to the nearest ancestor that really is a directory; that is
        // the containment anchor, and everything below it is trusted only
        // because it does not exist (see the doc-comment above).
        //
        // "Does not exist" and "is a DANGLING symlink" are not the same thing,
        // and only the first is safe to trust. A broken link is invisible to
        // both is_dir() and realpath(), so the walk used to step straight over
        // it, anchor on a parent that is legitimately in the jail, and hand
        // back `<root>/dangling/x.txt` — a path whose write follows the link
        // clean out of the jail the moment the link's target is created.
        // is_link() is true for a broken link, which is precisely why it is
        // the check that sees this. is_dir() is tested FIRST so a symlink to
        // an EXISTING directory still stops the walk and is settled the honest
        // way, by realpath()ing through it and checking the result against the
        // root — an in-jail symlinked directory stays usable.
        //
        // The clause is about BROKEN links, not about ESCAPING ones: a link
        // whose target is missing but would land INSIDE the jail (`<root>/link
        // -> <root>/notyet.txt`) is denied too. That is deliberate and
        // fail-closed — nothing here can prove where a missing target will end
        // up once something creates it, and the honest move for a path that
        // cannot be settled is to refuse it. Callers that want the file simply
        // name it directly.
        $ancestor = $normalized;
        while ($ancestor !== \dirname($ancestor)) {
            if (is_dir($ancestor)) {
                break;
            }
            if (is_link($ancestor)) {
                return null;
            }
            $ancestor = \dirname($ancestor);
        }

        $ancestorReal = realpath($ancestor);
        if ($ancestorReal === false) {
            return null;
        }
        if ($ancestorReal !== $rootReal && !str_starts_with($ancestorReal, $rootReal . '/')) {
            return null;
        }

        $tail = ltrim(substr($normalized, strlen(rtrim($ancestor, '/'))), '/');
        if ($tail === '') {
            return null;
        }

        return rtrim($ancestorReal, '/') . '/' . $tail;
    }

    /**
     * Collapse `.` and `..` segments without touching the filesystem, so a
     * traversal through a not-yet-existing directory still lands where it
     * really points. `..` at the top is dropped rather than escaping `/`,
     * matching how the kernel treats `/..`.
     */
    private static function normalizeLexically(string $absolutePath): string
    {
        $out = [];
        foreach (explode('/', $absolutePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        return '/' . implode('/', $out);
    }

    /**
     * Resolve an EXISTING directory, or null if it escapes `$root`.
     *
     * Unlike {@see resolve()} this never accepts a missing path: the callers
     * (Glob/Grep) are about to walk the result, and there is nothing to walk.
     */
    public static function resolveDir(string $root, string $path): ?string
    {
        if (self::unusable($root, $path)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        if (str_starts_with($path, '/')) {
            $fullPath = $path;
        } else {
            $fullPath = $rootReal . '/' . ltrim($path, '/');
        }
        $resolved = realpath($fullPath);

        if ($resolved === false) {
            return null;
        }

        // Same jail-boundary rule as resolve(): the root itself is in-jail.
        if ($resolved !== $rootReal && !str_starts_with($resolved, $rootReal . '/')) {
            return null;
        }

        return $resolved;
    }
}