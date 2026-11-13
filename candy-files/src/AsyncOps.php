<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Tick-deferred file operations wrapped in React\Promise.
 *
 * HONEST SEMANTICS: each operation defers its work to the
 * next event-loop tick via Loop::futureTick — that lets the keypress
 * round-trip finish and the frame paint BEFORE the I/O starts. It does NOT
 * make the I/O itself non-blocking: once a tick runs a large recursive
 * copy, the loop is occupied until that copy completes. "copyManyAsync"
 * batches with a concurrency window the same way — tick-sliced sequential
 * batches, never parallel disk I/O. A worker/fork pipeline would be needed
 * for truly non-blocking bulk copies; deliberately not built here.
 *
 * No upstream — house pattern. Finding 106 relabel: an earlier line
 * claimed "Mirrors yorukot/superfile.asyncOps", but asyncOps is not a
 * symbol superfile ships (its copies run through goroutines under
 * different names); this tick-sliced engine is the library's own.
 *
 * CF-07 disclosure flip: copies are NO LONGER content-only. File legs land
 * through a same-directory temp file renamed into place (an interrupted
 * copy never destroys the previous destination, and never leaves a corrupt
 * half-written new one either), and carry the source mode (chmod, umask
 * bypassed) plus source mtime across. Directories are created in the
 * source's mode — applied AFTER their children, so unwritable source dirs
 * stay writable while being filled. A failed recursive leg reverse-unlinks
 * what the frame created: a half-tree never reads as a completed copy.
 */
final class AsyncOps
{
    /** CF-17: directories a single findAsync tick may scan before re-arming. */
    public const FIND_DIRS_PER_TICK = 64;

    /**
     * @param \Closure(string,string):bool|null $engine CF-21 test seam: replaces
     *        the synchronous copy engine inside copyAsync()/copyManyAsync() so a
     *        diagnostic-bearing failure can be injected without abusing the real
     *        filesystem. Production never passes one — the default stays
     *        {@see self::copyPath()}'s cycle-guarded engine.
     */
    public function __construct(private readonly ?\Closure $engine = null)
    {
    }

    /**
     * The ONE synchronous copy engine: file, symlink, or depth-capped and
     * cycle-guarded directory tree. Shared by every path that copies —
     * the async tick and Manager::copy's sync face alike. (Manager used to
     * carry a second hand-copied copyDir; the copies could drift.)
     *
     * @return bool True on success; false on any error
     */
    public static function copyPath(string $src, string $dst): bool
    {
        return self::doCopy($src, $dst);
    }
    /**
     * Copy a file or directory asynchronously.
     *
     * @param string $src Source path
     * @param string $dst Destination path
     * @return PromiseInterface<bool|string> true = landed; false = disk refused; string = exception diagnostic (class: message), CF-21
     */
    public function copyAsync(string $src, string $dst): PromiseInterface
    {
        $deferred = new Deferred();

        // Defer to next tick so the TUI loop can process events in the meantime
        \React\EventLoop\Loop::futureTick(function () use ($src, $dst, $deferred): void {
            try {
                $result = $this->engine !== null ? ($this->engine)($src, $dst) : self::doCopy($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                // CF-21 (audit 029): bare false annihilated diagnostics — a
                // TypeError from the leg code was indistinguishable from
                // 'disk said no'. The reason rides the result; false is now
                // reserved for honest disk refusals.
                $deferred->resolve($e::class . ': ' . $e->getMessage());
            }
        });

        return $deferred->promise();
    }

    /**
     * CF-17 (closes 013): a budgeted recursive walk, tick-sliced the same
     * way every other engine here is — finding 011's law is that the UI
     * loop never blocks on disk, so the queue drains {@see self::FIND_DIRS_PER_TICK}
     * directories per tick and re-arms itself. Breadth-first, so the hit
     * cap fills with the shallowest matches first and a deep junk tree
     * cannot starve a near hit.
     *
     * The budgets are the honesty of the feature: depth stops at
     * $maxDepth (a `~/` walk going 40 levels is never the point), total
     * seen names at $maxSeen, and kept hits at $maxHits — each cap that
     * fires flips `truncated`, so the status line can say "partial"
     * rather than silently presenting a lie. Hidden entries are pruned
     * at the walk itself (search must not surface what the UI hides),
     * and unreadable directories are skipped, never fatal. Symlinks are
     * recorded as opaque files — a search walk that follows them can
     * loop forever through bind-mounts.
     *
     * @param string $root Directory to walk (absolute)
     * @param int $maxDepth Levels below root still descended
     * @param int $maxSeen Total entry names examined before giving up
     * @param int $maxHits Kept (accepted) entries before giving up
     * @param \Closure(string): bool $accept Name predicate — only names it accepts are kept, so a huge directory costs nothing beyond the stat-free name check
     * @return PromiseInterface<array{hits: list<array{dir: string, name: string, isDir: bool}>, truncated: bool}>
     */
    public function findAsync(string $root, int $maxDepth, int $maxSeen, int $maxHits, \Closure $accept): PromiseInterface
    {
        $deferred = new Deferred();

        $queue = [[$root, 0]];
        $hits  = [];
        $seen  = 0;
        $truncated = false;

        $slice = static function () use (&$queue, &$hits, &$seen, &$truncated, $deferred, $maxDepth, $maxSeen, $maxHits, $accept): void {
            $budget = self::FIND_DIRS_PER_TICK;
            while ($queue !== [] && $budget-- > 0) {
                [$dir, $depth] = array_shift($queue);
                $entries = @scandir($dir);
                if ($entries === false) {
                    continue; // unreadable directory: pruned, not fatal
                }
                foreach ($entries as $name) {
                    if ($name === '.' || $name === '..' || $name[0] === '.') {
                        continue; // chrome + hidden pruning (CF-09's visibility law)
                    }
                    if (++$seen > $maxSeen || count($hits) >= $maxHits) {
                        $truncated = true;
                        break 2;
                    }
                    $full = $dir . DIRECTORY_SEPARATOR . $name;
                    if (\is_link($full)) {
                        if ($accept($name)) {
                            $hits[] = ['dir' => $dir, 'name' => $name, 'isDir' => false];
                        }
                        continue; // never descended
                    }
                    $isDir = \is_dir($full);
                    if ($accept($name)) {
                        $hits[] = ['dir' => $dir, 'name' => $name, 'isDir' => $isDir];
                    }
                    if ($isDir) {
                        if ($depth < $maxDepth) {
                            $queue[] = [$full, $depth + 1];
                        } else {
                            $truncated = true; // deeper tree exists; the cap said stop
                        }
                    }
                }
            }
            if ($queue === [] || $truncated) {
                $deferred->resolve(['hits' => $hits, 'truncated' => $truncated]);
                return;
            }
            \React\EventLoop\Loop::futureTick($slice);
        };

        \React\EventLoop\Loop::futureTick($slice);
        return $deferred->promise();
    }

    /**
     * Move a file or directory asynchronously.
     *
     * @internal No production caller: Manager stages moves and resolves them
     *           through performMove()'s synchronous @rename (M-9 truth-stamp).
     *           Kept per CF-26 — the route-or-delete decision rides CF-11's
     *           landing review (an EXDEV-fallback pipeline would make this
     *           the engine of record).
     *
     * @param string $src Source path
     * @param string $dst Destination path
     * @return PromiseInterface<bool|string> true = landed; false = disk refused; string = exception diagnostic (class: message), CF-21
     */
    public function moveAsync(string $src, string $dst): PromiseInterface
    {
        $deferred = new Deferred();

        \React\EventLoop\Loop::futureTick(static function () use ($src, $dst, $deferred): void {
            try {
                $result = @rename($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                // CF-21: reason-bearing resolution (see copyAsync).
                $deferred->resolve($e::class . ': ' . $e->getMessage());
            }
        });

        return $deferred->promise();
    }

    /**
     * Rename a file or directory (atomic rename in the same directory).
     *
     * @internal No production caller: Manager::performRename() completes with
     *           a synchronous @rename (M-9 truth-stamp). Kept per CF-26 for
     *           the same CF-11 follow-up as {@see self::moveAsync()}.
     *
     * @param string $src    Source path
     * @param string $newName New base name (not a full path)
     * @return PromiseInterface<bool|string> true = landed; false = disk refused; string = exception diagnostic (class: message), CF-21
     */
    public function renameAsync(string $src, string $newName): PromiseInterface
    {
        $deferred = new Deferred();

        \React\EventLoop\Loop::futureTick(static function () use ($src, $newName, $deferred): void {
            try {
                $dir = dirname($src);
                $dst = $dir . DIRECTORY_SEPARATOR . $newName;
                $result = @rename($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                // CF-21: reason-bearing resolution (see copyAsync).
                $deferred->resolve($e::class . ': ' . $e->getMessage());
            }
        });

        return $deferred->promise();
    }

    /**
     * Copy multiple files/directories through a bounded tick pipeline.
     *
     * The $concurrency window caps how many copyAsync operations are
     * in-flight at once, but because each one runs its whole I/O synchronously
     * inside a futureTick, batches execute SEQUENTIALLY across ticks — they do
     * not interleave disk work in parallel. See the class docblock.
     *
     * @param array<string, string> $map Source → Destination mapping
     * @param int $concurrency Maximum queued copy operations (default 4)
     * @return PromiseInterface<array<string, bool|string>> source → true | false | exception diagnostic (CF-21)
     */
    public function copyManyAsync(array $map, int $concurrency = 4): PromiseInterface
    {
        if ($map === []) {
            $deferred = new Deferred();
            $deferred->resolve([]);
            return $deferred->promise();
        }

        $concurrency = max(1, $concurrency);
        $keys = array_keys($map);
        $total = \count($keys);
        $results = [];
        $pending = 0;
        $index = 0;

        $deferred = new Deferred();
        $self = $this;

        // Start up to $concurrency copies; when each completes, start next if any remain
        $startNext = function () use (&$pending, &$index, $keys, $map, $concurrency, &$results, $deferred, $total, $self, &$startNext): void {
            while ($pending < $concurrency && $index < $total) {
                $src = $keys[$index++];
                $dst = $map[$src];
                ++$pending;

                $self->copyAsync($src, $dst)->then(
                    function ($value) use ($src, &$pending, &$index, &$results, $deferred, $total, &$startNext): void {
                        $results[$src] = $value;
                        --$pending;
                        // Continue the pipeline by starting next pending copy
                        $startNext();
                        if ($index >= $total && $pending === 0) {
                            $deferred->resolve($results);
                        }
                    },
                    function (\Throwable $e) use ($src, &$pending, &$index, &$results, $deferred, $total, &$startNext): void {
                        // Defensive: copyAsync() catches in-tick and resolves
                        // a reason instead of rejecting; this arm keeps the
                        // same CF-21 contract if that polarity ever changes.
                        $results[$src] = $e::class . ': ' . $e->getMessage();
                        --$pending;
                        // Continue the pipeline by starting next pending copy
                        $startNext();
                        if ($index >= $total && $pending === 0) {
                            $deferred->resolve($results);
                        }
                    }
                );
            }
        };

        $startNext();

        return $deferred->promise();
    }

    /**
     * Move multiple files/directories, mapping source → destination.
     *
     * CF-26 truth-stamp: this does NOT share copyManyAsync's contract.
     * copyManyAsync keeps a bounded window of legs in flight and enqueues
     * each successor only when one completes; moveManyAsync pre-enqueues
     * ALL legs at once (every moveAsync futureTick lands in the same tick
     * queue), so a large map executes as one unbounded synchronous burst
     * on the loop. Cheap @rename calls make that tolerable today; if CF-11
     * ever routes real traffic here, bound it first.
     *
     * @internal No production caller (M-9 truth-stamp; Manager stages moves
     *           and resolves them through performMove()). Kept per CF-26
     *           for the CF-11 follow-up.
     *
     * @param array<string, string> $map Source → Destination mapping
     * @return PromiseInterface<array<string, bool|string>> source → true | false | exception diagnostic (CF-21)
     */
    public function moveManyAsync(array $map): PromiseInterface
    {
        if ($map === []) {
            $deferred = new Deferred();
            $deferred->resolve([]);
            return $deferred->promise();
        }

        $promises = [];

        foreach ($map as $src => $dst) {
            $promises[] = $this->moveAsync($src, $dst);
        }

        return \React\Promise\all($promises)
            ->then(static function (array $values) use ($map): array {
                $result = [];
                $keys = array_keys($map);
                foreach ($keys as $i => $src) {
                    $result[$src] = $values[$i] ?? false;
                }
                return $result;
            });
    }

    /**
     * Perform the actual recursive copy.
     *
     * @param string $src
     * @param string $dst
     * @return bool
     */
    private static function doCopy(string $src, string $dst): bool
    {
        $visited = [];

        return self::copyEntry($src, $dst, $visited, 0);
    }

    /**
     * One copy leg with the recursion context threaded through. CF-07(e):
     * a SINGLE lstat answers link/dir/file — the old walk asked is_link +
     * file_exists + readlink + is_dir + copy's own stat, six syscalls where
     * one suffices, and each extra probe was another TOCTOU window.
     *
     * @param array<string,true> $visited
     */
    private static function copyEntry(string $src, string $dst, array &$visited, int $depth): bool
    {
        $st = @\lstat($src);
        if ($st === false) {
            return false; // source vanished or unreadable: honest failed leg
        }
        $type = $st['mode'] & 0170000;
        if ($type === 0120000) {
            return self::copySymlinkLeg($src, $dst);
        }
        if ($type === 0040000) {
            return self::copyDir($src, $dst, $visited, $depth);
        }
        return self::copyFileLeg($src, $dst, (int) $st['mode'], (int) $st['mtime']);
    }

    /**
     * CF-07(b): the old leg called symlink(readlink($src)) — when readlink
     * failed PHP's strict_types turned the false into a TypeError that
     * escaped copyAsync's catch and, on an unprivileged Windows, silently
     * dropped whole link layers as "successful". A failed readlink now
     * fails the leg; it is counted like every other failed leg.
     */
    private static function copySymlinkLeg(string $src, string $dst): bool
    {
        $target = @\readlink($src);
        if ($target === false) {
            return false;
        }
        // M-10: symlink() onto an existing destination FAILS silently, so
        // unlink first — same replace semantics as the file leg (files
        // replace; a directory destination fails both ways and reports false).
        if (\file_exists($dst) || \is_link($dst)) {
            @\unlink($dst);
        }

        return @\symlink($target, $dst);
    }

    /**
     * CF-07(a,c): land the payload in a temp sibling FIRST, stamp mode and
     * mtime on it while it is still invisible to the destination name, then
     * rename it into place. An interrupted copy therefore never destroys the
     * previous destination and never leaves a corrupt half-written new one;
     * the rename is the atomic commit point on POSIX.
     */
    private static function copyFileLeg(string $src, string $dst, int $mode, int $mtime): bool
    {
        $tmp = $dst . '.candyfiles-tmp-' . \bin2hex(\random_bytes(6));
        if (!@\copy($src, $tmp)) {
            @\unlink($tmp);
            return false;
        }
        // metadata before the commit — arriving with the file, not after it
        @\chmod($tmp, $mode & 07777);
        if ($mtime > 0) {
            @\touch($tmp, $mtime);
        }
        if (self::moveIntoPlace($tmp, $dst)) {
            return true;
        }

        return false; // moveIntoPlace cleaned the temp up
    }

    /**
     * rename(2) replaces an existing destination on POSIX; on Windows it
     * refuses, so there the victim is unlinked immediately before (not
     * atomic, but never silent). Every losing path removes the temp file —
     * no residue after a failed leg.
     */
    private static function moveIntoPlace(string $tmp, string $dst): bool
    {
        if (@\rename($tmp, $dst)) {
            return true;
        }
        if (\DIRECTORY_SEPARATOR === '\\') {
            if ((\file_exists($dst) || \is_link($dst)) && !@\unlink($dst)) {
                @\unlink($tmp);
                return false;
            }
            if (@\rename($tmp, $dst)) {
                return true;
            }
        }
        @\unlink($tmp);

        return false;
    }

    /**
     * Remove a path AsyncOps itself created (file, symlink, or whole tree).
     * The counterpart of the failed-leg cleanup in copyDir — a recursive
     * copy that died halfway must not read as a completed tree.
     */
    private static function removeTree(string $path): void
    {
        if (\is_link($path)) {
            @\unlink($path);
            return;
        }
        if (\is_dir($path)) {
            foreach (@\scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::removeTree(Pane::join($path, $item));
            }
            @\rmdir($path);
            return;
        }
        if (\file_exists($path)) {
            @\unlink($path);
        }
    }

    /**
     * Maximum directory-nesting depth for a single recursive copy.
     *
     * Bounds runaway recursion — copying a directory into its own subtree,
     * or a hardlink/bind-mount cycle — before it can exhaust the call stack
     * or flood the filesystem (DoS). This is the single home of the cap;
     * Manager::copy() delegates here.
     */
    public const MAX_COPY_DEPTH = 256;

    /**
     * Recursively copy a directory.
     *
     * Guards against filesystem cycles two ways: a visited set of
     * realpath()-resolved source directories (catches a directory that
     * resolves back onto one already entered) and a hard depth cap. Either
     * trips to `false`.
     *
     * @param array<string,true> $visited realpath()s already entered this copy
     */
    private static function copyDir(string $src, string $dst, array &$visited = [], int $depth = 0): bool
    {
        if ($depth > self::MAX_COPY_DEPTH) {
            return false;
        }
        $real = \realpath($src);
        if ($real !== false) {
            if (isset($visited[$real])) {
                return false; // cycle: this real directory was already entered
            }
            $visited[$real] = true;
        }
        $st = @\lstat($src);
        if ($st === false) {
            return false;
        }
        // CF-07(c): the placeholder mode is 0700 (mine, private); the SOURCE
        // mode is applied AFTER the walk below, so an unwritable source dir
        // (0500) is still writable while being filled.
        $madeHere = @\mkdir($dst, 0700, true);
        if (!$madeHere && !\is_dir($dst)) {
            return false; // mkdir failed AND the destination is not a usable dir
        }

        $items = @\scandir($src);
        if ($items === false) {
            if ($madeHere) {
                self::removeTree($dst);
            }
            return false; // an unreadable source dir is a FAILURE, not an empty copy
        }
        $fresh = []; // destination names this frame created (no pre-existing victim)
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = Pane::join($src, $item);
            $dstPath = Pane::join($dst, $item);
            $preExisted = \file_exists($dstPath) || \is_link($dstPath);
            if (!self::copyEntry($srcPath, $dstPath, $visited, $depth + 1)) {
                // CF-07(d): a half-tree must never read as a real copy.
                // Remove what this frame CREATED; a same-named pre-existing
                // destination was already replaced leg-by-leg (house replace
                // semantics) — leave that name holding the new bytes.
                if ($madeHere) {
                    self::removeTree($dst);
                } else {
                    foreach ($fresh as $name) {
                        self::removeTree(Pane::join($dst, $name));
                    }
                }
                return false;
            }
            if (!$preExisted) {
                $fresh[] = $item;
            }
        }
        @\chmod($dst, (int) $st['mode'] & 07777);

        return true;
    }
}
