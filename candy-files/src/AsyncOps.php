<?php

declare(strict_types=1);

namespace SugarCraft\Files;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Async file operations via React\Promise.
 *
 * Wraps copy/move/rename operations in promises so long-running
 * file I/O does not block the TUI event loop.
 *
 * Mirrors charmbracelet/superfile.asyncOps.
 */
final class AsyncOps
{
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
                $dst = Pane::join($dir, $newName);
                $result = @rename($src, $dst);
                $deferred->resolve($result);
            } catch (\Throwable $e) {
                $deferred->resolve(false);
            }
        });

        return $deferred->promise();
    }

    /**
     * Copy multiple files/directories in parallel.
     *
     * @param array<string, string> $map Source → Destination mapping
     * @return PromiseInterface<array<string, bool>> Map of source → success
     */
    public function copyManyAsync(array $map): PromiseInterface
    {
        if ($map === []) {
            $deferred = new Deferred();
            $deferred->resolve([]);
            return $deferred->promise();
        }

        $promises = [];

        foreach ($map as $src => $dst) {
            $promises[] = $this->copyAsync($src, $dst);
        }

        // Wrap all promises and map back to source→result
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
        if (is_dir($src)) {
            return self::copyDir($src, $dst);
        }
        return @copy($src, $dst);
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $src
     * @param string $dst
     * @return bool
     */
    private static function copyDir(string $src, string $dst): bool
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            return false;
        }

        $items = @scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = Pane::join($src, $item);
            $dstPath = Pane::join($dst, $item);
            if (is_dir($srcPath)) {
                if (!self::copyDir($srcPath, $dstPath)) {
                    return false;
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    return false;
                }
            }
        }
        return true;
    }
}
