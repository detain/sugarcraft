<?php

declare(strict_types=1);

namespace SugarCraft\Code\Context;

use SugarCraft\Code\Support\Daemonize;
use SugarCraft\Code\Support\ForkedChild;

/**
 * The background half of {@see SymbolMapBlock}'s stand-in rebuild (S-O23,
 * owner ruling G-07=B).
 *
 * A changed tree used to cost the session's first turn a synchronous
 * tokenize + PageRank pass (≈5.5 s warm, ≈35 s cold on candy-code). When a
 * previous map for the project exists, the capture now serves THAT map for the
 * session and hands the rebuild to a detached process that stores the fresh
 * map in the project's tag cache; the next session reads it as a plain cache
 * hit. The standing session never sees the bytes move (PerSession
 * byte-stability is untouched).
 *
 * THE DETACHED CHILD is the double fork with `setsid()` between, ending in
 * {@see ForkedChild::exitNow()} — never `exit()`: it carries a copy of the
 * engine parent's object graph, including the `Tty` whose destructor would put
 * the shared terminal back in cooked mode. One rebuild at a time per tag
 * cache: the child takes a non-blocking `flock` on {@see lockPath()} and a
 * second one leaves at once, so many sessions opening during a long rebuild
 * start one worker, not a herd. A checkout the worker cannot finish within
 * {@see BUDGET_SECONDS} keeps the tokenizing it did in the tag cache, so the
 * following rebuild resumes rather than restarts.
 */
final class SymbolMapRebuilder
{
    /** Wall-clock allowance for the background tokenizing pass (a session's own is 4 s). */
    public const BUDGET_SECONDS = 180.0;

    private function __construct()
    {
    }

    /** The rebuild mutex beside the tag cache. */
    public static function lockPath(string $cachePath): string
    {
        return $cachePath . '.symbol-map.lock';
    }

    /** Whether this PHP can run a detached rebuild at all. */
    public static function available(): bool
    {
        return \function_exists('pcntl_fork') && \function_exists('pcntl_waitpid') && \function_exists('posix_setsid');
    }

    /**
     * Make sure a rebuild of $root is running: true when one was started or
     * is already in flight, false when none could be (no pcntl, a failed fork,
     * an unwritable lock) so the caller falls back to building in-line.
     */
    public static function start(string $root, string $cachePath): bool
    {
        if (!self::available() || $cachePath === ':memory:' || $cachePath === '') {
            return false;
        }

        $probe = @\fopen(self::lockPath($cachePath), 'c');
        if ($probe === false) {
            return false;
        }
        if (!\flock($probe, \LOCK_EX | \LOCK_NB)) {
            \fclose($probe);

            return true; // another process is already rebuilding this project's map
        }
        \flock($probe, \LOCK_UN);
        \fclose($probe);

        $first = \pcntl_fork();
        if ($first === -1) {
            return false;
        }
        if ($first === 0) {
            \posix_setsid();
            $second = \pcntl_fork();
            if ($second === 0) {
                while (\ob_get_level() > 0) {
                    \ob_end_clean();
                }
                ForkedChild::closeInheritedServerFds();
                Daemonize::redirectStdio('/dev/null');
                ForkedChild::exitNow(self::run($root, $cachePath));
            }
            ForkedChild::exitNow($second === -1 ? 1 : 0);
        }

        \pcntl_waitpid($first, $status);

        return true;
    }

    /**
     * The rebuild itself, in THIS process: take the mutex, build the map with
     * the long budget, store it. Exit code 0 when it stored a map or another
     * worker holds the mutex, 1 otherwise. Public so the detached child and
     * the tests run the same code.
     */
    public static function run(string $root, string $cachePath): int
    {
        $lock = @\fopen(self::lockPath($cachePath), 'c');
        if ($lock === false) {
            return 1;
        }
        if (!\flock($lock, \LOCK_EX | \LOCK_NB)) {
            return 0;
        }

        try {
            // The no-rebuild closure keeps this build in-line: a worker must
            // never start another worker.
            $block = SymbolMapBlock::capture($root, $cachePath, null, self::BUDGET_SECONDS, static fn (): bool => false);

            return $block->map() !== '' ? 0 : 1;
        } catch (\Throwable) {
            return 1;
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }
    }
}
