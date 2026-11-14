<?php

declare(strict_types=1);

namespace SugarCraft\Files;

/**
 * Default `Pane` lister — reads a directory off the live
 * filesystem via `scandir` + `lstat`. The whole rest of the app
 * accepts a `Closure(string $path): list<Entry>|null` so tests can
 * substitute a deterministic in-memory fake.
 *
 * No upstream — house pattern (finding 051). superfile walks directories
 * inline in its bubbletea update path; the injectable-lister contract here
 * is the library's own.
 *
 * CF-22 (closes audit 031): null means "cannot read" (missing path,
 * not a directory, or `scandir` refused) and is NEVER collapsed to
 * `[]` — an empty list is a fact ("this directory is empty"), while
 * a silent `[]` over a 0500 dir was the UI lying that a real listing
 * had succeeded. Callers must keep the two states apart.
 *
 * CF-13 (closes 019): `false` is the third state — "readable, but too
 * many entries to list" (over {@see self::MAX_LIST_ENTRIES}). The whole
 * listing runs synchronously inside ONE loop callback (scandir, a lstat
 * per entry, Entry builds, the sort), so an unbounded scan of a bloated
 * directory (a host /tmp with 200k+ entries measured 4-40 s) starved the
 * event loop: input went unread, `q` stopped answering and SIGINT/SIGTERM
 * — handled cooperatively — never landed. The cap bites right after the
 * readdir (before any per-entry syscall), bounding the worst case to the
 * scandir itself. Like CF-22 this is a PARSE-DON'T-VALIDATE boundary
 * promise: injected test listers keep the two-state contract (they never
 * answer `false`), and every consumer of a real listing must keep the
 * three states apart — `false` is not `null` ("cannot read") and never
 * collapses to `[]`.
 *
 * Consistency disclosure: size/mtime come from `lstat()` (the link's own
 * inode for symlinks), while `isDir` deliberately FOLLOWS the link so a
 * symlinked directory stays navigable. A symlink to a directory therefore
 * reports isDir=true with the link's own size — intentional, not a mismatch
 * to "fix" by switching to stat().
 */
final class FsLister
{
    /**
     * CF-13: hard ceiling on the raw entry count a single listing will
     * process (`.dir`/`.parent` excluded). Above it the lister answers
     * `false` and the pane refuses honestly instead of handing the event
     * loop an unbounded scan+sort. POSIX-scoped like the rest of the
     * live-FS layer (CF-53): the readdir guarantee below is Unix's.
     */
    public const MAX_LIST_ENTRIES = 10000;

    /**
     * @param int $maxEntries CF-13 ceiling seam (tests fabricate small caps)
     * @return \Closure(string): (list<Entry>|null|false)
     */
    public static function lister(int $maxEntries = self::MAX_LIST_ENTRIES): \Closure
    {
        return static function (string $path) use ($maxEntries): array|false|null {
            if (!\is_dir($path)) {
                return null;
            }
            $names = @\scandir($path);
            if ($names === false) {
                return null;
            }
            // CF-13: refuse BEFORE the per-entry lstat/Entry-build/usort —
            // scandir always carries the '.'/'..' pair, so the real count
            // is count($names) - 2. Comparing here keeps the worst-case
            // synchronous loop cost bounded to one readdir.
            if (count($names) - 2 > $maxEntries) {
                return false;
            }
            $out = [];
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = rtrim($path, '/') . '/' . $name;
                $stat = @lstat($full);
                if ($stat === false) {
                    continue;
                }
                $mode = $stat['mode'];
                // lstat() does not follow symlinks, so isLink comes straight
                // from the mode bits with no extra syscall. isDir, however,
                // must FOLLOW the link: a symlink whose target is a directory
                // has to report isDir=true, or navigate() treats it as a file
                // and refuses to enter it. Only pay the extra is_dir() syscall
                // for the (rare) symlink case; plain files/dirs stay bitmask-only.
                // S_IFMT=0170000 mask, S_IFLNK=0120000 symlink, S_IFDIR=0040000 directory
                $isLink = ($mode & 0170000) === 0120000;
                $isDir  = $isLink
                    ? \is_dir($full)
                    : ($mode & 0170000) === 0040000;
                // CF-45 (closes 081): carry the raw target and whether it
                // resolves, so the row can show `name -> target` and mark
                // dead links visibly instead of silently claiming DIR.
                // stat() follows links, so its failure IS the brokenness
                // answer — and a resolvable-but-unreadable target is live,
                // not broken.
                $linkTarget = '';
                $linkBroken = false;
                if ($isLink) {
                    $target     = @readlink($full);
                    $linkTarget = $target === false ? '' : $target;
                    $linkBroken = @stat($full) === false;
                }
                $out[] = new Entry(
                    name:       $name,
                    isDir:      $isDir,
                    size:       (int) $stat['size'],
                    mtime:      (int) $stat['mtime'],
                    isLink:     $isLink,
                    isHidden:   str_starts_with($name, '.'),
                    linkTarget: $linkTarget,
                    linkBroken: $linkBroken,
                    mode:       (int) $mode,
                    uid:        (int) $stat['uid'],
                    gid:        (int) $stat['gid'],
                );
            }
            return $out;
        };
    }

    /**
     * CF-39 (closes audit 063): memoized decorator over any lister —
     * keyed by path, invalidated by the directory's own mtime. The
     * injectable-lister contract is untouched (this just wraps whatever
     * closure it is handed), so tests keep their deterministic fakes and
     * the counter seam is the fake's own call tally.
     *
     * WHY mtime IS ENOUGH: a directory's mtime moves whenever its
     * ENTRY SET moves (create, unlink, rename in or out) — exactly the
     * changes the file manager itself performs. Navigation round-trips
     * (goUp-and-back, re-open the same path) and the both-panes re-reads
     * after every op complete are then cache hits: a delete of one file
     * in a 170k-entry pane no longer pays a full parent re-scan.
     *
     * DISCLOSED LIMIT: rewriting a file IN PLACE changes the file, not
     * the directory — its size column can read stale until the entry set
     * changes. That is the price of zero-syscall repeat navigation; mc's
     * re-opens pay a scan per keystroke instead.
     *
     * CF-22 contract kept THROUGH the cache: null (unreadable) listings
     * are stored but never trusted over a live stat — a null paired with
     * a readable mtime (the chmod'd-wall shape: filemtime answered,
     * scandir refused) always re-lists, so `r` recovers a permission that
     * came back without any entry-set change. A null stored while the
     * directory itself is unstatable re-hits (re-scanning a vanished dir
     * answers null again anyway) and self-invalidates the moment stat
     * returns.
     *
     * CF-13's `false` (too large) rides the cache as a plain stored
     * value: an unchanged directory keeps answering `false` for free,
     * and a directory whose entry set moved (mtime shift) re-lists, so
     * shrinking below the cap recovers on the next read without any
     * explicit invalidation.
     *
     * @param \Closure(string): (list<Entry>|null|false) $lister
     * @param (callable(string): ?int)|null              $mtime mtime seam for tests
     */
    public static function memoize(\Closure $lister, ?callable $mtime = null): \Closure
    {
        return self::memo($lister, $mtime)[0];
    }

    /**
     * @return array{0: \Closure(string): (list<Entry>|null|false), 1: \Closure(string): void}
     *         [memoized lister, slot invalidator]
     */
    public static function memo(\Closure $lister, ?callable $mtime = null): array
    {
        $stat = $mtime ?? static function (string $path): ?int {
            $m = @\filemtime($path);
            return $m === false ? null : $m;
        };
        /** @var array<string, array{0: ?int, 1: list<Entry>|null|false}> $cache */
        $cache = [];
        $read = static function (string $path) use ($lister, $stat, &$cache): array|false|null {
            $m = $stat($path);
            if (array_key_exists($path, $cache) && $cache[$path][0] === $m) {
                [$cachedM, $cached] = $cache[$path];
                if ($cached !== null || $cachedM === null) {
                    return $cached;
                }
            }
            $entries = $lister($path);
            $cache[$path] = [$m, $entries];
            // FIFO bound: a long browse must not retain every directory
            // it ever glanced at.
            if (count($cache) > 256) {
                unset($cache[array_key_first($cache)]);
            }
            return $entries;
        };
        $invalidate = static function (string $path) use (&$cache): void {
            unset($cache[$path]);
        };
        return [$read, $invalidate];
    }
}
