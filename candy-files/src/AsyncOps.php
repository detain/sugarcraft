<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Tick-deferred file operations wrapped in React\Promise.
 *
 * HONEST SEMANTICS (docs-fix-1023): each operation defers its work to the
 * next event-loop tick via Loop::futureTick — that lets the keypress
 * round-trip finish and the frame paint BEFORE the I/O starts. It does NOT
 * make the I/O itself non-blocking: once a tick runs a large recursive
 * copy, the loop is occupied until that copy completes. "copyManyAsync"
 * batches with a concurrency window the same way — tick-sliced sequential
 * batches, never parallel disk I/O. A worker/fork pipeline would be needed
 * for truly non-blocking bulk copies; deliberately not built here.
 *
 * Mirrors yorukot/superfile.asyncOps.
 *
 * Disclosure: copies are content-only. PHP's copy() does not preserve the
 * source file mode (destination gets default-permission bits) and copied
 * directories are created 0755 (umask-filtered) regardless of the source.
 */
final class AsyncOps
{
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
     * @return PromiseInterface<bool> Resolves true on success, false on failure
     */
    public function copyAsync(string $src, string $dst): PromiseInterface
    {
        $deferred = new Deferred();

        // Defer to next tick so the TUI loop can process events in the meantime
        \React\EventLoop\Loop::futureTick(static function () use ($src, $dst, $deferred): void {
            try {
                $result = self::doCopy($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                $deferred->resolve(false);
            }
        });

        return $deferred->promise();
    }

    /**
     * Move a file or directory asynchronously.
     *
     * @param string $src Source path
     * @param string $dst Destination path
     * @return PromiseInterface<bool> Resolves true on success, false on failure
     */
    public function moveAsync(string $src, string $dst): PromiseInterface
    {
        $deferred = new Deferred();

        \React\EventLoop\Loop::futureTick(static function () use ($src, $dst, $deferred): void {
            try {
                $result = @rename($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                $deferred->resolve(false);
            }
        });

        return $deferred->promise();
    }

    /**
     * Rename a file or directory (atomic rename in the same directory).
     *
     * @param string $src    Source path
     * @param string $newName New base name (not a full path)
     * @return PromiseInterface<bool> Resolves true on success, false on failure
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
                $deferred->resolve(false);
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
     * @return PromiseInterface<array<string, bool>> Map of source → success
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
                    function (\Throwable $_e) use ($src, &$pending, &$index, &$results, $deferred, $total, &$startNext): void {
                        $results[$src] = false;
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
     * Move multiple files/directories in parallel.
     *
     * @param array<string, string> $map Source → Destination mapping
     * @return PromiseInterface<array<string, bool>> Map of source → success
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
        if (\is_link($src)) {
            return @\symlink(\readlink($src), $dst);
        }
        if (\is_dir($src)) {
            return self::copyDir($src, $dst);
        }
        return @\copy($src, $dst);
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
        if (!@\mkdir($dst, 0755, true) && !\is_dir($dst)) {
            return false;
        }

        $items = @\scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = Pane::join($src, $item);
            $dstPath = Pane::join($dst, $item);
            if (\is_link($srcPath)) {
                @\symlink(\readlink($srcPath), $dstPath);
            } elseif (\is_dir($srcPath)) {
                if (!self::copyDir($srcPath, $dstPath, $visited, $depth + 1)) {
                    return false;
                }
            } else {
                if (!@\copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }
        return true;
    }
}
