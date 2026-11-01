<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Support\ProcessContainment;

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
 */
final class WindowsSwap
{
    /** Retry cadence between rename attempts, in microseconds (design-fixed). */
    public const RETRY_INTERVAL_US = 200_000;

    /** Total wait budget for the swap, in seconds (design-fixed; E646-only ceiling). */
    public const RETRY_BUDGET_SECONDS = 60.0;

    /**
     * The detached helper's complete source, handed to `php -r` verbatim.
     *
     * FIXED LITERAL — the argv slots (1) parent pid, (2) staged `.new` path,
     * (3) target phar path, (4) optional budget-seconds override (test seam for
     * the never-frees leg; production never passes it) are read as DATA only.
     * Core functions only, so the helper carries no autoload and no require edge.
     * The parent pid rides the log line as forensic context; the wait itself is
     * OS-portable because it IS the retry loop — rename returns false while the
     * image lock is held, on NT and everywhere else. A vanished `.new` logs
     * `nothing staged` and exits 0 rather than spinning against a file that is
     * no longer there.
     */
    public const HELPER = <<<'PHP'
$parentPid = isset($argv[1]) ? (int) $argv[1] : 0;
$new = isset($argv[2]) ? (string) $argv[2] : '';
$target = isset($argv[3]) ? (string) $argv[3] : '';
$budget = isset($argv[4]) && \is_numeric($argv[4]) ? (float) $argv[4] : 60.0;
if ($new === '' || $target === '') {
    \fwrite(\STDERR, "usage: php -r <helper> <parent-pid> <staged-new> <target-phar> [budget-seconds]\n");
    exit(2);
}
$log = $target . '.swap.log';
$line = static function (string $message) use ($log): void {
    @\file_put_contents($log, \date('c') . ' ' . $message . "\n", \FILE_APPEND | \LOCK_EX);
};
$line('swap helper started; waiting for parent pid ' . $parentPid . ' to exit');
$retryUs = 200000;
$attempts = 0;
$deadline = \microtime(true) + $budget;
$lastError = '';
\set_error_handler(static function (int $severity, string $message) use (&$lastError): bool {
    $lastError = $message;

    return true;
});
while (true) {
    if (!\is_file($new)) {
        $line('nothing staged at ' . $new . '; exiting without a swap');
        exit(0);
    }
    ++$attempts;
    $lastError = '';
    $swapped = \rename($new, $target);
    if ($swapped) {
        $line('swap complete after ' . $attempts . ' attempt(s)');
        exit(0);
    }
    $line('attempt ' . $attempts . ': ' . ($lastError === '' ? 'rename failed' : $lastError));
    if (\microtime(true) + $retryUs / 1000000.0 > $deadline) {
        $line('gave up after ' . $attempts . ' attempt(s); ' . $new . ' left staged for manual swap');
        exit(1);
    }
    \usleep($retryUs);
}
PHP;

    /**
     * Launch the detached swap helper for a staged `.new` waiting on `$phar`.
     *
     * @param int    $parentPid pid the helper waits out (the verb's own pid)
     * @param string $new       staged path, `<phar>.new`, already verified in place
     * @param string $phar      target phar path the helper renames onto
     *
     * @return bool false only when the spawn itself failed — the staged `.new`
     *              survives either verdict and the next run announces it
     */
    public static function launch(int $parentPid, string $new, string $phar): bool
    {
        // Same kernel rule as BackgroundShellRegistry::start(): the null device
        // is chosen from the PATH SEPARATOR, not from an OS-family guess, so the
        // seam-injected Windows tests running on POSIX hosts still spawn against
        // '/dev/null' instead of a missing literal 'NUL' path (which would fail
        // the whole proc_open). On real NT this resolves to 'NUL' exactly.
        $nullDevice = \DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        // create_process_group is a Windows-only proc_open option (inert on
        // POSIX): the helper must land in its own group so a signal aimed at the
        // verb's group does not kill the swap mid-retry; spawnSpec's setsid
        // prefix keeps the same distance from the parent's group on POSIX.
        // bypass_shell mirrors the other `PHP_BINARY -r` sites (EgressProxyProcess,
        // WorkspaceHostClient): the argv list goes straight to CreateProcess.
        $child = @\proc_open(
            ProcessContainment::spawnSpec(self::launchCommand($parentPid, $new, $phar)),
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $nullDevice, 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env(),
            ['bypass_shell' => true, 'create_process_group' => true]
        );
        if (!\is_resource($child)) {
            return false;
        }
        // The handle is deliberately dropped: proc_close would WAIT for the
        // helper, and the helper's whole purpose is to outlive this process.
        // The fixed retry budget bounds the orphan; the row in
        // DescriptorInheritanceGuardTest accounts the licence.
        return true;
    }

    /**
     * The exact argv the spawn carries, as data — paths never touch the code.
     *
     * @return list<string>
     */
    public static function launchCommand(int $parentPid, string $new, string $phar): array
    {
        return [\PHP_BINARY, '-r', self::HELPER, (string) $parentPid, $new, $phar];
    }

    /** Never instantiated — pure static helper (mirrors the SelfUpdate verb class). */
    private function __construct()
    {
    }
}
