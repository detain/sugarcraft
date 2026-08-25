<?php

declare(strict_types=1);

/**
 * Out-of-process hang watchdog for the candy-pty suite (E490).
 *
 * Argv: <parentPid> <heartbeatPath> <budgetSeconds>
 *
 * Polls the heartbeat file the parent rewrites on every `Test\Prepared`
 * event. When the CURRENT test has been in flight longer than the budget,
 * dumps the forensic bundle E490 had to be gathered by hand -- test name,
 * process state, `wchan`, elapsed, cpu share, and the count of open
 * `/dev/ptmx` descriptors -- to stderr and then SIGKILLs the parent.
 *
 * Deliberately a SEPARATE PROCESS rather than a `pcntl_alarm()` in the
 * runner. Two independent reasons, both measured on this tree:
 *
 *   1. `RetryOnEintrDeadlineTest` installs its own SIGALRM handler and
 *      restores `SIG_DFL` on the way out, which would silently disarm an
 *      in-process alarm watchdog part-way through the suite.
 *   2. `pcntl_setitimer()` does not exist on this host (PHP 8.3.6), so the
 *      sub-second granularity an in-process design would want is not
 *      available anyway; `pcntl_alarm()` is whole-second only.
 *
 * A wedged FFI/PTY call is also not guaranteed to run userland signal
 * handlers promptly, which is exactly the failure this watchdog exists to
 * bound -- a separate process observing from outside cannot be wedged by it.
 *
 * @see \SugarCraft\Pty\Tests\Support\HangWatchdog  — the parent-side half
 */

$parentPid = isset($argv[1]) ? (int) $argv[1] : 0;
$heartbeat = $argv[2] ?? '';
$budget    = isset($argv[3]) ? (float) $argv[3] : 0.0;

if ($parentPid <= 0 || $heartbeat === '' || $budget <= 0.0) {
    \fwrite(\STDERR, "hang-watchdog: usage: hang-watchdog.php <pid> <heartbeatPath> <budgetSeconds>\n");
    exit(2);
}

/**
 * Read the heartbeat. The parent writes it with an atomic rename, so a
 * torn read is not possible; a MISSING file just means "no test in flight
 * yet" and is not an error.
 *
 * @return array{0: float, 1: string}|null
 */
$readHeartbeat = static function (string $path): ?array {
    $raw = @\file_get_contents($path);
    if (!\is_string($raw) || $raw === '') {
        return null;
    }
    $parts = \explode("\t", \trim($raw), 2);
    if (\count($parts) !== 2 || !\is_numeric($parts[0])) {
        return null;
    }
    return [(float) $parts[0], $parts[1]];
};

$forensics = static function (int $pid): string {
    $out = '';
    $ps = @\shell_exec('ps -o pid,stat,wchan:24,etime,%cpu,rss -p ' . $pid . ' 2>&1');
    $out .= "  ps:\n" . \rtrim((string) $ps) . "\n";

    $fds = @\scandir('/proc/' . $pid . '/fd');
    if (\is_array($fds)) {
        $ptmx = 0;
        $total = 0;
        foreach ($fds as $fd) {
            if ($fd === '.' || $fd === '..') {
                continue;
            }
            $total++;
            $target = @\readlink('/proc/' . $pid . '/fd/' . $fd);
            if (\is_string($target) && \str_contains($target, 'ptmx')) {
                $ptmx++;
            }
        }
        $out .= "  open fds: {$total} (of which /dev/ptmx: {$ptmx})\n";
    }

    $children = @\shell_exec('ps -o pid,stat,etime,args --ppid ' . $pid . ' 2>&1');
    $out .= "  children:\n" . \rtrim((string) $children) . "\n";

    return $out;
};

while (true) {
    \usleep(500_000);

    // Parent gone (normal end of run, or someone else killed it) -> we are done.
    if (!@\posix_kill($parentPid, 0)) {
        exit(0);
    }

    $beat = $readHeartbeat($heartbeat);
    if ($beat === null) {
        continue;
    }
    [$startedAt, $testName] = $beat;

    $age = \microtime(true) - $startedAt;
    if ($age < $budget) {
        continue;
    }

    \fwrite(\STDERR, \sprintf(
        "\n"
        . "================================================================\n"
        . "candy-pty HANG WATCHDOG: a single test exceeded its budget.\n"
        . "  test:    %s\n"
        . "  in flight: %.1fs (budget %.1fs)\n"
        . "%s"
        . "The runner is being SIGKILLed so this surfaces as a named failure\n"
        . "instead of an unbounded stall. See E490.\n"
        . "================================================================\n",
        $testName,
        $age,
        $budget,
        $forensics($parentPid),
    ));

    @\posix_kill($parentPid, \SIGKILL);
    exit(1);
}
