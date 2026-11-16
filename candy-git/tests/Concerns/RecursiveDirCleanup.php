<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests\Concerns;

/**
 * Provides recursive directory cleanup for tests that create temp directories.
 *
 * ci-wave-5 5C: the Windows job exited 1 under failOnWarning with ZERO test
 * failures - unlink() 'Permission denied' and rmdir() 'Directory not empty'
 * diagnostics leaked out of this trait's teardown pass. Two fault classes:
 *
 *  - Git marks object files it writes read-only, and NTFS refuses to delete
 *    read-only files; chmod() is the only way PHP clears that attribute. (On
 *    POSIX a file's mode never gates unlink - the parent DIRECTORY's write
 *    bit does - which is why the Linux suite never showed the attribute
 *    class; the recursive pins below construct the same condition through
 *    the directory mode and prove the repair path consumes it.)
 *  - Transient handles: an indexer, AV scanner, or kernel reap lag can hold a
 *    directory entry so its rmdir reports 'Directory not empty' after every
 *    visible child is already gone. The consuming fixtures reap their spawn
 *    children before teardown (GitSpawn.php and the Process.php drain funnel
 *    both block in proc_close), so this is reaping-lag noise, not a live
 *    child racing the walk.
 *
 * The cure: pass 1 removes plainly; every failed path is made writable via
 * chmod and the tree re-scanned on the next attempt, up to a bounded budget
 * with small sleeps between attempts. If anything still survives the budget,
 * removeDir() throws a RuntimeException naming each leftover path - a stray
 * PHP warning is never the error channel, and nothing is silently suppressed
 * with '@': each operation's diagnostics are captured by a scoped error
 * handler and reported on the exception.
 */
trait RecursiveDirCleanup
{
    /** Total cleanup passes over a tree; pass 1 removes plainly, repairs ride the retries. */
    private const CLEANUP_PASSES = 6;

    /** Sleep between passes, in microseconds - a transient NTFS handle frees in tens of ms. */
    private const CLEANUP_PASS_DELAY_US = 20_000;

    /** Repair mode: owner read/write/execute, enough to delete a file or descend a directory on both platforms. */
    private const CLEANUP_REPAIR_MODE = 0o700;

    /**
     * Recursively remove a directory and all its contents.
     *
     * @throws \RuntimeException listing every named path that survived the full budget
     */
    protected function removeDir(string $dir): void
    {
        // Early exit: a tree that is already gone is in its final state.
        if (!is_dir($dir)) {
            return;
        }

        $survivors = [];
        for ($pass = 1; $pass <= self::CLEANUP_PASSES; $pass++) {
            if ($pass > 1) {
                usleep(self::CLEANUP_PASS_DELAY_US);
                foreach (array_keys($survivors) as $path) {
                    // chmod-to-writable BEFORE the retry's unlink/rmdir: on
                    // Windows this clears the read-only attribute, and on
                    // POSIX the directory write bit is what gates unlink at
                    // all. Chmod is best-effort - if it cannot repair, the
                    // retry's own diagnostic reports why.
                    $this->makeWritable($path);
                }
            }
            $survivors = $this->purgeTree($dir);
            if ($survivors === []) {
                return;
            }
        }

        $report = [];
        foreach ($survivors as $path => $diagnostic) {
            $report[] = ' - ' . $path . ': ' . $diagnostic;
        }
        throw new \RuntimeException(
            'RecursiveDirCleanup: ' . self::CLEANUP_PASSES . ' cleanup passes did not remove these paths under ' . $dir . ":\n"
            . implode("\n", $report)
        );
    }

    /**
     * One depth-first pass: children first, then the directory itself.
     * Failures are collected, never escalated, so the retry loop can repair
     * and re-scan (each pass re-derives the entry list from scandir()).
     *
     * @return array<string, string> surviving path => last diagnostic; [] means fully removed
     */
    private function purgeTree(string $dir): array
    {
        $survivors = [];

        $listing = $this->capture(fn(): array|false => $this->cleanupScandir($dir));
        if (!is_array($listing['result'])) {
            if (!is_dir($dir)) {
                return $survivors; // gone between scan and stat: nothing to remove
            }
            $survivors[$dir] = $this->diagnose('scandir', $listing);
            return $survivors;
        }

        foreach ($listing['result'] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                // Descend real directories only - a symlink is removed, never
                // followed, so a link to a directory cannot empty its target.
                foreach ($this->purgeTree($path) as $survivor => $diagnostic) {
                    $survivors[$survivor] = $diagnostic;
                }
                continue;
            }
            $removal = $this->capture(fn(): bool => $this->cleanupUnlink($path));
            if ($removal['result'] !== true && !self::pathGone($path)) {
                $survivors[$path] = $this->diagnose('unlink', $removal);
            }
        }

        $removal = $this->capture(fn(): bool => $this->cleanupRmdir($dir));
        if ($removal['result'] !== true && is_dir($dir)) {
            // 'Directory not empty' here means a child survived the walk or a
            // handle held an entry; the next pass re-scans and repairs.
            $survivors[$dir] = $this->diagnose('rmdir', $removal);
        }
        return $survivors;
    }

    /**
     * Clear the Windows read-only attribute / restore the POSIX write bits.
     *
     * @param string $path a survivor of the previous pass
     */
    private function makeWritable(string $path): void
    {
        // Discarded result: a repair that fails leaves the path in the next
        // pass's survivor list with the operation's own diagnostic.
        $this->capture(fn(): bool => $this->cleanupChmod($path, self::CLEANUP_REPAIR_MODE));
    }

    /**
     * Run one filesystem operation with its diagnostics captured instead of
     * escalated. set_error_handler() observes E_WARNING even from '@'-prefixed
     * calls, so this is the recording channel: warnings reach the survivor
     * report, never the test result - no '@'-silencing of real errors and no
     * stray PHP warning as the error channel.
     *
     * @param callable(): mixed $operation
     * @return array{result: mixed, diagnostics: list<string>}
     */
    private function capture(callable $operation): array
    {
        $diagnostics = [];
        set_error_handler(
            /** @param string $message */
            static function (int $severity, string $message) use (&$diagnostics): bool {
                $diagnostics[] = $message;
                return true;
            }
        );
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }
        return ['result' => $result, 'diagnostics' => $diagnostics];
    }

    /**
     * @param array{result: mixed, diagnostics: list<string>} $attempt
     */
    private function diagnose(string $operation, array $attempt): string
    {
        return $attempt['diagnostics'] === []
            ? $operation . '() failed'
            : implode('; ', $attempt['diagnostics']);
    }

    /**
     * Lstat-style absence check: a dangling symlink still exists as a name
     * and must be unlinked, so file_exists() alone would miscount it as gone.
     */
    private static function pathGone(string $path): bool
    {
        return !is_link($path) && !file_exists($path);
    }

    // -- raw-operation seams -------------------------------------------------
    //
    // Split out so the pins can deterministically simulate the two Windows
    // fault classes (attribute-gated unlink, handle-held not-empty rmdir)
    // that a rootless Linux box cannot construct at all - chmod-repairable
    // conditions heal before any budget is spent, so no real-path fixture
    // can force a survivor. The real-filesystem pins beside them prove the
    // same repair path consumes the warning class Linux CAN construct.

    /** @return list<string>|false entry names, or false on failure (diagnostic captured by the caller) */
    protected function cleanupScandir(string $dir): array|false
    {
        return scandir($dir);
    }

    protected function cleanupUnlink(string $path): bool
    {
        return unlink($path);
    }

    protected function cleanupRmdir(string $dir): bool
    {
        return rmdir($dir);
    }

    protected function cleanupChmod(string $path, int $mode): bool
    {
        return chmod($path, $mode);
    }
}
