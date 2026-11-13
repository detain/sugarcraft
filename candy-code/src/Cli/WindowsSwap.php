<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Releases\WindowsSwap as LibWindowsSwap;

/**
 * The relaunch dance {@see SelfUpdate} hands the phar swap to on Windows (C-TUI-6.e).
 *
 * WHY A HELPER PROCESS EXISTS AT ALL. On NT, renaming over the image a live
 * process is executing from fails while that process runs — so the verb can never
 * swap its own phar from the inside. The repair is a second, tiny process that
 * waits for this one to exit and completes the rename from the outside:
 *
 *  1. {@see SelfUpdate} stages the verified bytes at `<phar>.new` and copies the
 *     running phar to `<phar>.old` (both legs happen before anything is launched).
 *  2. {@see launch()} spawns the detached helper with
 *     `[PHP_BINARY, '-r', self::HELPER, <parentPid>, <new>, <phar>]`.
 *  3. The helper retries `rename(<new>, <phar>)` every 200 ms up to 60 s until
 *     the parent exits and NT releases the image lock, logging every outcome to
 *     `<phar>.swap.log`.
 *
 * THE INJECTION LAW. `php -r` takes code as argv, and the code half is the FIXED
 * {@see HELPER} literal — no path, no pid, nothing derived from disk ever
 * interpolates into it. The paths travel strictly as separate argv elements the
 * helper reads as data (`$argv[2]`, `$argv[3]`). {@see launchCommand()} is the
 * whole construction, exposed as data so the separation is testable without a
 * process.
 *
 * THE BOUND. 60 seconds total at a 200 ms cadence — the design-declared ceiling
 * and the ONLY timeout in this leg (E646: it bounds the swap retry, never an LLM
 * request). On the happy path the helper exits 0 with `swap complete` in the log;
 * when the target never frees it exits 1 leaving `<phar>.new` intact for a manual
 * swap — never a half-written phar. Either way the preserved `.old` and the log
 * make the state recoverable, and the next `--self-update` run announces the
 * leftover stage before downloading.
 *
 * CONTAINMENT. The spawn rides {@see ProcessContainment::spawnSpec()} (detached:
 * setsid on POSIX; on NT the prefix is inert and `create_process_group` puts the
 * helper in its own group), all three stdio descriptors point at the kernel's
 * null device so zero parent file descriptors are inherited, and the handle is
 * deliberately dropped — outliving the verb IS the point (accounted in
 * tests/Support/DescriptorInheritanceGuardTest.php).
 *
 * S-L01: the helper source ({@see HELPER}), the retry budget and the detached
 * spawn live in sugar-releases ({@see \SugarCraft\Releases\WindowsSwap}); this class
 * is the adapter that adds candy-code's process containment (spawnSpec +
 * scrubbed env) to the lib's host seams.
 */
final class WindowsSwap
{
    public const RETRY_INTERVAL_US = LibWindowsSwap::RETRY_INTERVAL_US;

    public const RETRY_BUDGET_SECONDS = LibWindowsSwap::RETRY_BUDGET_SECONDS;

    /** The fixed helper program — the lib's literal, never interpolated. */
    public const HELPER = LibWindowsSwap::HELPER;

    /**
     * Launch the detached swap helper for a staged `.new` waiting on `$phar`.
     *
     * @return bool false only when the spawn itself failed — the staged `.new`
     *              survives either verdict and the next run announces it
     */
    public static function launch(int $parentPid, string $new, string $phar): bool
    {
        return LibWindowsSwap::launch(
            $parentPid,
            $new,
            $phar,
            static fn(array $argv): string|array => ProcessContainment::spawnSpec($argv),
            ProcessContainment::env(),
        );
    }

    /**
     * The exact argv the spawn carries, as data — paths never touch the code.
     *
     * @return list<string>
     */
    public static function launchCommand(int $parentPid, string $new, string $phar): array
    {
        return LibWindowsSwap::launchCommand($parentPid, $new, $phar);
    }

    private function __construct()
    {
    }
}
