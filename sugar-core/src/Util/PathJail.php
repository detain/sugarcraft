<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * The single path-containment predicate: "is this path inside that root?"
 *
 * Stateless and static, and the root is a parameter, because the callers that
 * need containment (file tools jailed to a workspace, a plugin loader reading
 * from a checkout) each hold their own root. Every answer is fail-closed:
 * `null` means "rejected, do not touch this path", never "unknown".
 *
 * The base algorithm is candy-code's `Tools\PathJail`, which is the rule set
 * most callers already depend on. The one difference is what `resolve()`
 * refuses, and it follows owner decision D18 in UPSTREAM-PLAN.md: `resolve()`
 * also takes ContainedPath's two refusals.
 *
 *   - A DANGLING LEAF: a symlink whose target does not exist. realpath() cannot
 *     see it, and a parent-directory check would vouch for it, so a write
 *     would follow the link to wherever its target lands once created.
 *   - AN EMPTY PATH or EMPTY ROOT: realpath('') answers with the process
 *     working directory, so an empty string would silently anchor containment
 *     wherever the process happens to stand.
 *
 * Every method refuses an empty root and a NUL byte before any filesystem
 * call, because an empty root anchors on the working directory and realpath()
 * throws on NUL. The empty-path and dangling-leaf refusals are resolve()'s
 * alone. `resolveForCreate()`, `resolveDir()` and `confine()` keep the base
 * rules otherwise: `resolveForCreate()` already refuses a dangling leaf by its
 * own ancestor walk, and `resolveDir()` never accepts a missing path.
 *
 * ## The overflow hook (the one path outside the root)
 *
 * `resolve()` may accept a path that resolves OUTSIDE the root only when the
 * caller passes an overflow hook that vouches for it. candy-code uses this for
 * its saved tool-output files (ToolOutputSpill), which live in a per-user
 * store, not in the workspace. The hook receives the resolved path and returns
 * the path to accept, or null to refuse. Passing no hook refuses every outside
 * path. The hook is deliberately a parameter and not a built-in list: sugar-core
 * must not know any application's store layout. Only `resolve()` takes it, so
 * a create or a directory walk can never reach an outside path.
 *
 * ## Two questions beyond containment (ContainedPath)
 *
 * {@see within()} asks whether an ENTRY may be read given the directory it was
 * reached from. The boundary itself counts as inside, because a link back to
 * the directory is a cycle the walker stops, not an escape. {@see below()}
 * asks whether a DIRECTORY may be trusted as a curated root. The boundary does
 * not count there: a repository linking its workflows directory onto its own
 * checkout root would expose every untracked file in the working tree.
 *
 * ## POSIX paths only
 *
 * Containment compares canonical paths with a `/` separator. On Windows,
 * realpath() answers with backslashes, so every entry below the root is
 * refused. That fails closed, but it is not working Windows containment. A
 * Windows caller needs separator normalization and drive-letter absolute-path
 * detection before it relies on this class.
 *
 * ## Not covered
 *
 * A hard link inside the root to a file outside it is invisible to every check
 * here, because a hard link is a second name for the same inode and no path
 * component resolves to another path. Callers that must rule it out need a
 * trust gate on the content, not a path check.
 */
final class PathJail
{
    /**
     * Resolve a path for reading or modifying an entry that should exist, or
     * null if it escapes $root.
     *
     * A file that does not exist yet is accepted only when its immediate parent
     * already does, because that parent is the containment anchor. For paths
     * whose parents may also be missing, use {@see resolveForCreate()}.
     *
     * @param (callable(string): (string|null))|null $overflow  Accepts a path
     *        that resolves outside $root (see the class doc). Null refuses it.
     */
    public static function resolve(string $root, string $path, ?callable $overflow = null): ?string
    {
        if ($root === '' || $path === '') {
            return null;
        }

        return self::settle($root, $path, $overflow, refuseDanglingLeaf: true);
    }

    /**
     * Resolve a path that is allowed NOT TO EXIST YET, including any number of
     * missing parent directories, or null if it escapes $root.
     *
     * `.` and `..` are collapsed LEXICALLY before any lookup, so a traversal
     * cannot be laundered through a directory that does not exist. A `..` that
     * follows a symlink is refused instead, because the kernel applies it to the
     * link's target and the lexical collapse would apply it to the link's own
     * parent. The walk then anchors on the nearest ancestor that really is a
     * directory. A symlink in the walk is refused unless it points to an existing
     * directory, because a broken link is invisible to is_dir() and realpath() and
     * would otherwise let the walk step over it onto a legitimate in-root parent.
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
            : self::under($rootReal, $path);

        $resolved = realpath($fullPath);
        if ($resolved !== false) {
            return self::inside($resolved, $rootReal) ? $resolved : null;
        }

        // The lexical collapse below and the kernel disagree about `link/..`: the
        // kernel follows the link first and steps up from its target. Refuse that
        // shape before collapsing, or the approved path lands outside the root.
        if (self::parentWalkCrossesLink($fullPath)) {
            return null;
        }

        $normalized = self::normalizeLexically($fullPath);

        // Walk up to the nearest existing directory. Everything below it is
        // trusted only because it does not exist. A missing target is refused
        // when it is a dangling link, since nothing can prove where it will
        // land once created. is_dir() is tested first, so a symlink to an
        // existing directory stops the walk and is checked by realpath() instead.
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
        if ($ancestorReal === false || !self::inside($ancestorReal, $rootReal)) {
            return null;
        }

        $tail = ltrim(substr($normalized, strlen(rtrim($ancestor, '/'))), '/');
        if ($tail === '') {
            return null;
        }

        return rtrim($ancestorReal, '/') . '/' . $tail;
    }

    /**
     * Resolve an EXISTING directory, or null if it escapes $root.
     *
     * Unlike {@see resolve()} this never accepts a missing path, because a
     * caller about to walk the result has nothing to walk otherwise.
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

        $fullPath = str_starts_with($path, '/')
            ? $path
            : self::under($rootReal, $path);
        $resolved = realpath($fullPath);
        if ($resolved === false) {
            return null;
        }

        return self::inside($resolved, $rootReal) ? $resolved : null;
    }

    /**
     * Whether a WRITE of $path belongs under $root. A preview calls this to
     * answer the containment question without a third copy of the algorithm.
     *
     * The verdict is taken from the base rule behind {@see resolve()} when the
     * path exists, and from {@see resolveForCreate()} when it does not. The
     * answer is accepted only
     * when the path it vouched for really sits under the real root, so a spill
     * path outside every root never counts as a write target.
     */
    public static function confine(string $root, string $path): ?string
    {
        if (self::unusable($root, $path)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        // A dangling leaf is invisible to realpath(), so the resolve() verdict
        // below would vouch for it. Refuse it first, on the normalized path.
        $full = str_starts_with($path, '/') ? $path : self::under($rootReal, $path);
        $leaf = self::normalizeLexically($full);
        if (is_link($leaf) && realpath($leaf) === false) {
            return null;
        }

        $existing = self::settle($root, $path, null, refuseDanglingLeaf: false);
        if ($existing !== null && self::inside($existing, $rootReal)) {
            return $existing;
        }

        $fresh = self::resolveForCreate($root, $path);

        return $fresh !== null && self::inside($fresh, $rootReal) ? $fresh : null;
    }

    /**
     * Whether $path resolves inside $boundary, or ONTO it.
     *
     * The entry-level question: may this entry be read, given the directory it
     * was reached from? An unresolvable path (a dangling link, a target this
     * process cannot reach) answers false, because it is not something to read.
     */
    public static function within(string $path, string $boundary): bool
    {
        return self::compare($path, $boundary, exactCounts: true);
    }

    /**
     * Whether $path resolves STRICTLY inside $boundary.
     *
     * The trust-anchor question: may this directory be used as a curated root?
     * Equality is refused, so a link onto the boundary itself fails.
     */
    public static function below(string $path, string $boundary): bool
    {
        return self::compare($path, $boundary, exactCounts: false);
    }

    /**
     * The base algorithm behind {@see resolve()} and {@see confine()}.
     *
     * $refuseDanglingLeaf is the one rule {@see resolve()} adds. {@see confine()}
     * passes false because it refuses a dangling leaf before it gets here.
     */
    private static function settle(
        string $root,
        string $path,
        ?callable $overflow,
        bool $refuseDanglingLeaf,
    ): ?string {
        if (self::unusable($root, $path)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal === false) {
            return null;
        }

        $fullPath = str_starts_with($path, '/')
            ? $path
            : self::under($rootReal, $path);
        $resolved = realpath($fullPath);

        if ($resolved === false) {
            // A dangling leaf exists but has no target, so realpath() cannot
            // settle it. The parent check below would accept it, and a write
            // would then follow the link wherever its target lands.
            if ($refuseDanglingLeaf && is_link($fullPath)) {
                return null;
            }

            $resolvedDir = realpath(dirname($fullPath));

            return $resolvedDir !== false && self::inside($resolvedDir, $rootReal)
                ? $fullPath
                : null;
        }

        if (self::inside($resolved, $rootReal)) {
            return $resolved;
        }

        if ($overflow === null) {
            return null;
        }

        $accepted = $overflow($resolved);

        return is_string($accepted) ? $accepted : null;
    }

    /**
     * Whether $candidate is $root or lies beneath it. Both sides must already
     * be canonical.
     */
    private static function inside(string $candidate, string $rootReal): bool
    {
        return $candidate === $rootReal || str_starts_with($candidate, rtrim($rootReal, '/') . '/');
    }

    /**
     * Join a relative path onto a canonical root. The trailing slash is trimmed
     * first, so the filesystem root `/` does not become `//`.
     */
    private static function under(string $rootReal, string $path): string
    {
        return rtrim($rootReal, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Whether a root/path pair is unusable as a filesystem path at all.
     *
     * An empty root is refused because realpath('') answers with the process
     * working directory, which would make the working directory the jail. A NUL
     * byte can never appear in a real path, and realpath() and is_dir() THROW a
     * ValueError on one instead of answering false. An unguarded NUL would turn a
     * containment question into an uncaught crash in whichever caller asked it,
     * so both refusals live here, before any filesystem call.
     */
    private static function unusable(string $root, string $path): bool
    {
        return $root === '' || str_contains($root, "\0") || str_contains($path, "\0");
    }

    /**
     * Whether a `..` in $absolutePath would be read by the kernel differently
     * from {@see normalizeLexically()}.
     *
     * The kernel resolves each component in turn, so when a component is a
     * symlink it steps up from the link's target, while the lexical collapse
     * steps up from the link's own position. The two only agree while the
     * prefix before each `..` is a real directory that is not itself a link, so
     * any `..` reached through a symlink, or through a prefix that realpath()
     * would move, is refused. A prefix that does not exist ends the question
     * without refusing: the kernel cannot walk through a missing component, so
     * the write fails instead of escaping.
     *
     * The rule is deliberately stricter than the one bug it fixes. A symlinked
     * ancestor that stays inside the root is refused too, and so is an absolute
     * path that reaches a symlinked system prefix such as macOS `/var`. Both are
     * fail-closed refusals of a shape no caller needs.
     */
    private static function parentWalkCrossesLink(string $absolutePath): bool
    {
        $stack = [];
        foreach (explode('/', $absolutePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment !== '..') {
                $stack[] = $segment;
                continue;
            }
            if ($stack !== []) {
                $current = '/' . implode('/', $stack);
                $real = realpath($current);
                if (is_link($current) || ($real !== false && $real !== $current)) {
                    return true;
                }
            }
            array_pop($stack);
        }

        return false;
    }

    /**
     * Collapse `.` and `..` without touching the filesystem. A `..` at the top
     * is dropped rather than escaping `/`, which is how the kernel treats `/..`.
     * Callers must have ruled out a `..` that crosses a symlink first; see
     * {@see parentWalkCrossesLink()}.
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
     * Shared body of {@see within()} and {@see below()}.
     */
    private static function compare(string $path, string $boundary, bool $exactCounts): bool
    {
        // The empty string is refused here rather than left to realpath(), which
        // would answer it with the process working directory.
        // NUL is refused here too: realpath() throws a ValueError on it.
        if ($path === '' || $boundary === '' || str_contains($path, "\0") || str_contains($boundary, "\0")) {
            return false;
        }

        $realBoundary = realpath($boundary);
        $realPath = realpath($path);
        if ($realBoundary === false || $realPath === false) {
            return false;
        }

        if ($realPath === $realBoundary) {
            return $exactCounts;
        }

        // The trailing separator on both sides stops `/a/b` from being read as
        // containing `/a/bevil`. A boundary of `/` leaves the empty string after
        // rtrim, and that is correct: every absolute path is inside `/`.
        return str_starts_with($realPath . '/', rtrim($realBoundary, '/') . '/');
    }
}
