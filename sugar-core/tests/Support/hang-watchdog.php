<?php

declare(strict_types=1);

/**
 * Out-of-process hang watchdog for the sugar-core suite (CI-wave-4 lane 3).
 *
 * Argv: <parentPid> <heartbeatPath> <budgetSeconds> [<armedAtUnixTime>]
 *
 * Polls the heartbeat file the parent rewrites on every `Test\Prepared`
 * event. When the CURRENT test has been in flight longer than the budget,
 * dumps a forensic bundle — test name, process state, elapsed, cpu share,
 * threads, children, plus the platform's own view of where the process is
 * wedged — to stderr and then SIGKILLs the parent.
 *
 * Port of sugar-pty's E490 watchdog (sugar-pty/tests/Support/hang-watchdog.php).
 * Out-of-process for the same measured reasons as the original:
 *
 *   1. A wedged FFI/ioctl call is not guaranteed to run userland signal
 *      handlers promptly — a separate process observing from outside cannot
 *      be wedged by it. That is the exact failure this port exists to bound:
 *      the leading hypothesis for the macOS sugar-core hang is the arm64
 *      TIOCSWINSZ FFI chain (scripts/affected-libs.php:35-53) reached via
 *      PosixBackend's descriptor legs.
 *   2. `pcntl_alarm()` is whole-second only and any in-process handler can be
 *      disarmed by the suite's own signal bookkeeping (Program rewrites
 *      dispositions on its run paths).
 *
 * FORENSICS ARE PLATFORM-AWARE AND FAILURE-ISOLATED. The original's probe set
 * (`ps -o …,wchan:24,…`, `/proc/<pid>/fd`, `ps … --ppid`) is GNU/Linux
 * procps syntax; on Darwin the `wchan` keyword and `--ppid` do not exist and a
 * bad `-o` key makes `ps` print a usage error and NO data — a watchdog whose
 * one job was to report a name would report nothing on the very platform it
 * is here for. Each probe therefore runs in its own guarded block and the
 * kill never waits on forensics succeeding. `sample(1)` is attempted on
 * Darwin only: it is the "stack where obtainable" of the mission — a native
 * backtrace of the wedged process, which is precisely the evidence the
 * variadic-ioctl hypothesis needs; if it is missing or denied, the line is
 * skipped, the kill still lands. fd enumeration stays Linux-only: there is no
 * `/proc` on Darwin and `lsof` can itself stall against a wedged target, so on
 * the box that matters the thread state and sample stack carry the diagnosis.
 *
 * @see \SugarCraft\Core\Tests\Support\HangWatchdog — the parent-side half
 */

$parentPid = isset($argv[1]) ? (int) $argv[1] : 0;
$heartbeat = $argv[2] ?? '';
$budget    = isset($argv[3]) ? (float) $argv[3] : 0.0;

// The instant the PARENT armed this watchdog. Any heartbeat older than that
// was written by a different run whose state directory this one inherited --
// the directory is named for a pid alone, and the SIGKILL this process
// delivers is exactly what prevents the previous owner from cleaning up. A
// record from such a run is arbitrarily old, so it fires instantly. Optional,
// and 0.0 disables the check, so an older parent still works with this script.
$armedAt = isset($argv[4]) ? (float) $argv[4] : 0.0;

if ($parentPid <= 0 || $heartbeat === '' || $budget <= 0.0) {
    \fwrite(\STDERR, "hang-watchdog: usage: hang-watchdog.php <pid> <heartbeatPath> <budgetSeconds>\n");
    exit(2);
}

$isDarwin = \PHP_OS_FAMILY === 'Darwin';

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

/** Run one forensic probe; a probe that errors contributes nothing, never a failure. */
$probe = static function (string $command): string {
    $out = @\shell_exec($command . ' 2>&1');
    $out = \rtrim((string) $out);
    // `ps` answers usage errors with exit-noise text rather than a non-zero
    // shell status we could read; refuse anything that looks like a usage
    // refusal so a whole-empty report cannot masquerade as a whole report.
    if ($out === '' || \stripos($out, 'usage:') !== false) {
        return '';
    }
    return $out;
};

$forensics = static function (int $pid) use ($probe, $isDarwin): string {
    $out = '';

    $psCols = $isDarwin
        ? 'pid,stat,etime,%cpu,rss'   // no wchan keyword on BSD ps
        : 'pid,stat,wchan:24,etime,%cpu,rss';
    $ps = $probe('ps -o ' . $psCols . ' -p ' . $pid);
    if ($ps !== '') {
        $out .= "  ps:\n" . $ps . "\n";
    }

    if (!$isDarwin) {
        // The E490 evidence trio: thread states with their kernel wait channel
        // (do_select is the signature), and the open-descriptor census whose
        // /dev/ptmx count named the PTY hang.
        $threads = $probe('for t in /proc/' . $pid . '/task/*; do '
            . 'printf "%s %s %s\\n" "$(basename "$t")" "$(cat "$t/stat" 2>/dev/null | cut -d" " -f3)" "$(cat "$t/wchan" 2>/dev/null)"; done');
        if ($threads !== '') {
            $out .= "  threads (tid state wchan):\n" . $threads . "\n";
        }

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

        $children = $probe('ps -o pid,stat,etime,args --ppid ' . $pid);
    } else {
        // Darwin substitutes its own syntax for each Linux probe that cannot
        // run there. `ps -M` lists threads with their wait address; `sample`
        // is the native backtrace the ioctl hypothesis is judged on.
        $threads = $probe('ps -M ' . $pid);
        if ($threads !== '') {
            $out .= "  threads (ps -M):\n" . $threads . "\n";
        }

        $stack = $probe('sample ' . $pid . ' 1 -f /dev/stdout');
        if ($stack !== '' && \stripos($stack, 'sample:') === false) {
            // Keep the dump readable: the call-graph tree is the payload.
            $out .= "  sample (1s native stack):\n" . $stack . "\n";
        } else {
            $out .= "  sample: unavailable (denied or not installed)\n";
        }

        // Children via pgrep + an explicit -p list: BSD xargs lacks -r, and an
        // empty `ps -p` invocation would dump EVERY process into the report.
        $kids = [];
        foreach (\explode("\n", $probe('pgrep -P ' . $pid)) as $line) {
            if (\preg_match('/^\d+$/', \trim($line)) === 1) {
                $kids[] = \trim($line);
            }
        }
        $children = $kids === [] ? '' : $probe('ps -o pid,stat,etime,args -p ' . \implode(',', $kids));
    }
    if ($children !== '') {
        $out .= "  children:\n" . $children . "\n";
    }

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

    // Predates our arming -> it belongs to a previous occupant of this pid.
    // Ignored rather than acted on, and NOT treated as "no test in flight":
    // the parent overwrites it as soon as it prepares a test of its own.
    if ($armedAt > 0.0 && $startedAt < $armedAt) {
        continue;
    }

    $age = \microtime(true) - $startedAt;
    if ($age < $budget) {
        continue;
    }

    \fwrite(\STDERR, \sprintf(
        "\n"
        . "================================================================\n"
        . "sugar-core HANG WATCHDOG: a single test exceeded its budget.\n"
        . "  test:    %s\n"
        . "  in flight: %.1fs (budget %.1fs)\n"
        . "%s"
        . "The runner is being SIGKILLed. PHPUnit prints NOTHING after this --\n"
        . "the run ends at exit 137 with no summary -- so THIS report is the\n"
        . "only record of which test it was. See the sugar-core E490-port note\n"
        . "on \SugarCraft\Core\Tests\Support\HangWatchdog and the macOS hang\n"
        . "dossier /tmp/opencode/ciwatch-1116/TRIAGE4.md.\n"
        . "================================================================\n",
        $testName,
        $age,
        $budget,
        $forensics($parentPid),
    ));

    @\posix_kill($parentPid, \SIGKILL);
    exit(1);
}
