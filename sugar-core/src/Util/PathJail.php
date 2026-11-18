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
 * most callers already depend on. Each method refuses somewhat more than that
 * base, and the lists below are the contract a caller moving off
 * `Tools\PathJail` must check for the method it uses.
 *
 * `resolve()` also takes ContainedPath's two refusals, per owner decision D18
 * in UPSTREAM-PLAN.md:
 *
 *   - A DANGLING LEAF: a symlink whose target does not exist. realpath() cannot
 *     see it, and a parent-directory check would vouch for it, so a write
 *     would follow the link to wherever its target lands once created.
 *   - AN EMPTY PATH or EMPTY ROOT: realpath('') answers with the process
 *     working directory, so an empty string would silently anchor containment
 *     wherever the process happens to stand.
 *
 * `resolveForCreate()` refuses three shapes the base admits:
 *
 *   - a `..` whose preceding prefix is a symlink, or is moved by realpath(),
 *     even when the collapsed path would stay inside the root, because the
 *     kernel steps up from the link's target and the collapse from its parent;
 *   - a symlink in the missing-path walk that does not point at an existing
 *     directory, including a dangling one;
 *   - every new path on Windows, whose lexical walk has no drive-aware form.
 *
 * `confine()` judges an existing path by `resolve()`'s rule and a missing one
 * by `resolveForCreate()`, so it refuses everything `resolveForCreate()`
 * refuses. `resolveDir()` never accepts a missing path.
 *
 * Every method refuses an empty root, an empty path and a NUL byte before any
 * filesystem call. An empty root or path anchors on the working directory,
 * because realpath('') answers with it, and realpath() throws on NUL. The
 * dangling-leaf refusal belongs to resolve() and confine(); resolveForCreate()
 * already refuses a dangling link by its own ancestor walk, and resolveDir()
 * never accepts a missing path.
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
 * ## Platform separators
 *
 * Containment compares canonical paths against the platform separator
 * (`DIRECTORY_SEPARATOR`), which is what realpath() answers with: `/` on POSIX,
 * `\\` on Windows. Direct and nested entries are judged by the same rule
 * there.
 *
 * On Windows, an input path is first normalised lexically by {@see WindowsPath}:
 * drive letters (`c:` and `C:`), UNC prefixes (`\\server\share`), mixed `/` and
 * `\` separators, and `..` after normalisation. A path it cannot normalise with
 * certainty is refused before any filesystem call (see WindowsPath for the list).
 * A `..` that follows a symbolic link or junction is refused too, since the
 * lexical collapse would apply it to the wrong directory. Paths that already
 * exist are then judged by realpath(). A path that does NOT exist yet is still
 * refused by {@see resolveForCreate()} and so by {@see confine()} on Windows:
 * its walk has no tested Windows form. {@see windowsLexical()} is the pure
 * half, and runs on any platform. The filesystem half has not been run on a
 * Windows host, so a Windows caller must test it before it relies on this class.
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

        $fullPath = self::candidate($rootReal, $path);
        if ($fullPath === null) {
            return null;
        }

        $resolved = realpath($fullPath);
        if ($resolved !== false) {
            return self::inside($resolved, $rootReal) ? $resolved : null;
        }

        // The walk below anchors a missing path with a leading `/`, which turns a
        // drive-letter root into `/C:\...` and answers no ancestor. Refuse instead.
        if (!self::lexicalWalkSupported(DIRECTORY_SEPARATOR)) {
            return null;
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

        $fullPath = self::candidate($rootReal, $path);
        if ($fullPath === null) {
            return null;
        }
        $resolved = realpath($fullPath);
        if ($resolved === false) {
            return null;
        }

        return self::inside($resolved, $rootReal) ? $resolved : null;
    }

    /**
     * The pure, platform-independent Windows verdict: the collapsed candidate
     * for $path under $root when it lies at or below $root, or null.
     *
     * It takes no filesystem input, so Windows fixtures run on any platform.
     * The FS-backed methods call the same normaliser on Windows, and their link
     * checks are the part this does not cover.
     */
    public static function windowsLexical(string $root, string $path): ?string
    {
        return WindowsPath::within($root, $path);
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

        // The raw path, not a lexically collapsed one: `s/../dl` reaches the link
        // through the kernel's `..`, which the collapse would drop.
        $probe = self::candidate($rootReal, $path);
        if ($probe === null) {
            return null;
        }
        if (realpath($probe) === false) {
            // A missing leaf takes the writer's verdict. settle() would accept
            // `link/../new.txt` on its parent check alone, which resolveForCreate()
            // refuses, so a preview would show a diff the writer then refuses.
            $fresh = self::resolveForCreate($root, $path);

            return $fresh !== null && self::inside($fresh, $rootReal) ? $fresh : null;
        }

        $existing = self::settle($root, $path, null, refuseDanglingLeaf: true);

        return $existing !== null && self::inside($existing, $rootReal) ? $existing : null;
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
     * $refuseDanglingLeaf is the one rule {@see resolve()} and {@see confine()}
     * add. It must test the raw path: a link reached through `..` after a
     * symlinked directory is invisible to a check on the lexically collapsed path.
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

        $fullPath = self::candidate($rootReal, $path);
        if ($fullPath === null) {
            return null;
        }
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
        return self::insideBeneath($candidate, $rootReal, DIRECTORY_SEPARATOR);
    }

    /**
     * The boundary test behind {@see inside()}, with the separator passed in so
     * the Windows rule is testable on POSIX. Checking only `/` made a canonical
     * Windows path (backslashes) refuse every existing entry below the root,
     * while a new direct child passed through the directory branch.
     */
    private static function insideBeneath(string $candidate, string $rootReal, string $separator): bool
    {
        return $candidate === $rootReal
            || str_starts_with($candidate, rtrim($rootReal, $separator) . $separator);
    }

    /**
     * Whether resolveForCreate() may walk a path that does not exist yet.
     *
     * The walk normalizes the path lexically with a leading `/`, which holds only
     * on POSIX. Windows is refused, fail closed, until the walk has a drive-aware
     * form that is tested on Windows. The separator is passed in
     * so the refusal is testable on POSIX, as insideBeneath() is.
     */
    private static function lexicalWalkSupported(string $separator): bool
    {
        return $separator === '/';
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
     * The path to examine for $path under the canonical $rootReal, or null when
     * it cannot be proven.
     *
     * On POSIX this is the historical join. On Windows the path is normalised by
     * {@see WindowsPath}, and a `..` that follows a link is refused before the
     * collapse, because the kernel would step up from the link's target.
     */
    private static function candidate(string $rootReal, string $path): ?string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return str_starts_with($path, '/') ? $path : self::under($rootReal, $path);
        }

        $parts = WindowsPath::resolve($rootReal, $path);
        if ($parts === null || self::windowsDotDotCrossesLink($parts)) {
            return null;
        }

        $normal = WindowsPath::normalise($parts);

        return $normal === null ? null : WindowsPath::render($normal[0], $normal[1]);
    }

    /**
     * Whether a `..` in the Windows path $parts would be applied by the
     * filesystem to a different directory from the lexical collapse.
     *
     * The shape of {@see parentWalkCrossesLink()}, written over WindowsPath's
     * anchor and segments, so the drive and UNC prefixes render correctly. Any
     * prefix that is a link, or that realpath() would move, is a refusal. Unlike
     * parentWalkCrossesLink(), the move test is case-insensitive (see
     * {@see windowsRealpathMoves()}), so the two checks are not interchangeable:
     * routing this through parentWalkCrossesLink() would refuse a `..` after a
     * directory typed in other than its on-disk case.
     *
     * @param array{0: string, 1: list<string>} $parts
     */
    private static function windowsDotDotCrossesLink(array $parts): bool
    {
        [$anchor, $segments] = $parts;

        $stack = [];
        foreach ($segments as $segment) {
            if ($segment !== '..') {
                $stack[] = $segment;
                continue;
            }
            if ($stack !== []) {
                $current = WindowsPath::render($anchor, $stack);
                $real = realpath($current);
                if (is_link($current) || ($real !== false && self::windowsRealpathMoves($current, $real))) {
                    return true;
                }
            }
            array_pop($stack);
        }

        return false;
    }

    /**
     * Whether realpath() answered with a different path for the same prefix.
     *
     * NTFS ignores case by default, and realpath() answers with the on-disk
     * spelling, so a case-only difference names the same entry and is not a
     * redirect. Comparing case-sensitively refused a `..` after any directory
     * typed in other than its on-disk case, which is a false refusal.
     */
    private static function windowsRealpathMoves(string $typed, string $real): bool
    {
        return strcasecmp($typed, $real) !== 0;
    }

    /**
     * Whether a root/path pair is unusable as a filesystem path at all.
     *
     * An empty root or path is refused because realpath('') answers with the
     * process working directory, which would make the working directory the jail
     * (or the target). A NUL byte can never appear in a real path, and realpath()
     * and is_dir() THROW a ValueError on one instead of answering false. An
     * unguarded NUL would turn a containment question into an uncaught crash in
     * whichever caller asked it, so both refusals live here, before any
     * filesystem call.
     */
    private static function unusable(string $root, string $path): bool
    {
        return $root === '' || $path === '' || str_contains($root, "\0") || str_contains($path, "\0");
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

        return self::relation($realPath, $realBoundary, $exactCounts, DIRECTORY_SEPARATOR);
    }

    /**
     * The canonical-path half of {@see compare()}, with the separator passed in
     * so the Windows rule is testable on POSIX, as {@see insideBeneath()} is. The
     * separator must match the one realpath() answers with: a `/`-only check
     * refused every entry under a Windows boundary while {@see resolve()} accepted
     * the same entry.
     */
    private static function relation(string $realPath, string $realBoundary, bool $exactCounts, string $separator): bool
    {
        if ($realPath === $realBoundary) {
            return $exactCounts;
        }

        // The trailing separator on both sides stops `/a/b` from being read as
        // containing `/a/bevil`. A boundary of `/` leaves the empty string after
        // rtrim, and that is correct: every absolute path is inside `/`.
        return str_starts_with($realPath . $separator, rtrim($realBoundary, $separator) . $separator);
    }
}
