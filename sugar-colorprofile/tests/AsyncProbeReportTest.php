<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\TerminalProbe;
use SugarCraft\ColorProfile\Probe\TerminfoReplayProbe;

/**
 * @covers \SugarCraft\ColorProfile\AsyncProbe::report
 *
 * The async capability report is the replacement for the synchronous
 * TerminalProbe twin. Its contract: the same ProbeReport the sync pipeline
 * composes, with the infocmp child spawned only on the loop the caller hands
 * in, and no spawn at all where the sync pipeline would not ask for a dump.
 */
final class AsyncProbeReportTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'FORCE_COLOR', 'TERM_PROGRAM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
        AsyncProbe::resetForTesting();
        InfocmpBinary::reset();
    }

    protected function tearDown(): void
    {
        AsyncProbe::resetForTesting();
        InfocmpBinary::reset();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
        parent::tearDown();
    }

    private function setTerm(string $term): void
    {
        $_ENV['TERM'] = $term;
        putenv('TERM=' . $term);
    }

    /**
     * Run $loop until $promise settles, with a watchdog so a wedged child
     * cannot hang the suite (the probe's own 5 s + 1 s ladder settles first).
     */
    private static function settle(PromiseInterface $promise, LoopInterface $loop): mixed
    {
        $result = null;
        $done = false;
        $promise->then(static function (mixed $value) use (&$result, &$done): void {
            $result = $value;
            $done = true;
        });

        if (!$done) {
            $poll = null;
            $watchdog = $loop->addTimer(8.0, static function () use ($loop): void {
                $loop->stop();
            });
            $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$done, &$poll, $watchdog): void {
                if (!$done) {
                    return;
                }
                $loop->cancelTimer($watchdog);
                if ($poll !== null) {
                    $loop->cancelTimer($poll);
                }
                $loop->stop();
            });
            $loop->run();
        }

        self::assertTrue($done, 'the report promise must settle');

        return $result;
    }

    /** @return array<string, string> */
    private static function sources(ProbeReport $report): array
    {
        return $report->allWithSource();
    }

    public function testReportWithoutLoopResolvesInPlaceWithTheSyncReport(): void
    {
        $this->setTerm('xterm-256color');

        $resolved = null;
        AsyncProbe::report()->then(static function (ProbeReport $report) use (&$resolved): void {
            $resolved = $report;
        });

        $this->assertInstanceOf(ProbeReport::class, $resolved, 'no loop means an in-place settle, with no loop run');
        $this->assertSame(self::sources(TerminalProbe::run()), self::sources($resolved));
    }

    public function testSuppressionSettlesWithoutSpawningAChild(): void
    {
        $this->setTerm('xterm-256color');
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        $loop = new StreamSelectLoop();
        $report = null;
        AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
            $report = $r;
        });

        $this->assertInstanceOf(ProbeReport::class, $report, 'NO_COLOR answers before any loop turn');
        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertCount(0, $this->inFlightSlots(), 'the suppression gate keeps the child from ever being booked');

        unset($_ENV['NO_COLOR']);
        putenv('NO_COLOR');
    }

    public function testReportOverARealChildMatchesTheSyncReport(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        foreach (['xterm-256color', 'xterm-kitty', 'screen-256color'] as $term) {
            AsyncProbe::resetForTesting();
            $this->setTerm($term);

            $loop = new StreamSelectLoop();
            $async = self::settle(AsyncProbe::report($loop), $loop);

            $this->assertInstanceOf(ProbeReport::class, $async, $term);
            $this->assertSame(
                self::sources(TerminalProbe::run()),
                self::sources($async),
                "async report must equal the sync composition for TERM={$term}",
            );
        }
    }

    public function testCompletedChildReplaysFromTheDumpMemoWithoutASpawn(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $this->setTerm('xterm-256color');
        $loop = new StreamSelectLoop();
        $first = self::settle(AsyncProbe::report($loop), $loop);
        $this->assertInstanceOf(ProbeReport::class, $first);

        // The second call must answer from the dump memo: it settles before any
        // loop turn and books no slot.
        $second = null;
        AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$second): void {
            $second = $r;
        });

        $this->assertInstanceOf(ProbeReport::class, $second, 'a memoized dump answers in place');
        $this->assertSame(self::sources($first), self::sources($second));
        $this->assertCount(0, $this->inFlightSlots());
    }

    public function testSaturatedCapDegradesToTheEnvironmentReportWithoutSpawning(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $this->setTerm('xterm-256color');
        $slots = [];
        for ($i = 1; $i <= AsyncProbe::MAX_CONCURRENT_PROBES; $i++) {
            $slots[$i] = ['process' => new \React\ChildProcess\Process('sleep 30'), 'deadline' => \microtime(true) + 60.0];
        }
        $this->setInFlightSlots($slots);

        try {
            $loop = new StreamSelectLoop();
            $report = null;
            AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
                $report = $r;
            });

            $this->assertInstanceOf(ProbeReport::class, $report, 'a full cap answers in place');
            $this->assertFalse(\in_array('terminfo:Tc|RGB', self::sources($report), true), 'no terminfo leg ran at the cap');
            $this->assertCount(AsyncProbe::MAX_CONCURRENT_PROBES, $this->inFlightSlots(), 'no slot is added at the cap');
        } finally {
            $this->setInFlightSlots([]);
        }
    }

    /**
     * The SIGTERM -> SIGKILL ladder driven through report()'s own onLadder
     * seam, not only through colorProfile(). A TERM-ignoring child fires the
     * deadline rung: the report settles as "no dump" (the environment answer,
     * exactly what the sync door gives on overrun), books no dump memo entry
     * (CP-13), and the grace KILL reaps the child.
     */
    public function testTermIgnoringChildSettlesTheReportAsNoDumpAndIsReaped(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX signal semantics (trap, SIGKILL) are not available here.');
        }

        $this->setTerm('xterm-256color');
        $path = $this->plantBinary("#!/bin/sh\ntrap '' TERM\nwhile :; do :; done\n");
        AsyncProbe::setDeadlinesForTesting(1, 1);
        try {
            $loop = new StreamSelectLoop();
            $promise = AsyncProbe::report($loop);

            $slots = array_values($this->inFlightSlots());
            $this->assertCount(1, $slots, 'the terminfo leg must spawn a child on the loop the caller passed');
            $process = $slots[0]['process'];

            $report = self::settle($promise, $loop);

            $this->assertInstanceOf(ProbeReport::class, $report);
            $this->assertSame(
                self::sources(TerminfoReplayProbe::replaying(null)->runProbe()),
                self::sources($report),
                'a ladder-killed child replays as "no dump": the environment answer stands',
            );
            $this->assertCount(0, $this->inFlightSlots(), 'the deadline rung releases the slot');
            $this->assertSame(['xterm-256color' => null], $this->settledDumps(), 'a deadline overrun is a recorded miss, as on the sync door');

            // The grace KILL fires after the caller has settled. Drive the loop
            // past it so the child is observed, reaped and closed.
            $loop->addTimer(2.5, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();

            $this->assertSame(
                ['xterm-256color' => null],
                $this->settledDumps(),
                'the late exit of a ladder-killed child must not overwrite the recorded miss with its partial dump',
            );
            $this->assertFalse($process->isRunning(), 'no live child survives the ladder');
            $this->assertNull(
                (new \ReflectionProperty(\React\ChildProcess\Process::class, 'process'))->getValue($process),
                'the grace rung must SIGKILL and reap the child, not merely signal it',
            );
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->terminate(\defined('SIGKILL') ? \SIGKILL : 9);
            }
            @\unlink($path);
        }
    }

    /**
     * A child that exits non-zero is a verdict like any other: the report
     * answers from the stdout it printed (empty here, so "no dump"), memoizes
     * that dump, and a repeat report() replays it without a second spawn. This
     * is the sync door's policy (InfocmpBinary::execOnce returns the capped
     * stdout whatever the exit code); the spawn counter pins the memo side.
     */
    public function testNonZeroExitSettlesAsNoDumpAndIsMemoizedLikeTheSyncDoor(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $this->setTerm('xterm-256color');
        $log = \tempnam(\sys_get_temp_dir(), 'cp28log');
        self::assertIsString($log);
        $path = $this->plantBinary('#!/bin/sh' . "\n" . 'echo spawn >> ' . \escapeshellarg($log) . "\n" . "exit 1\n");
        try {
            $loop = new StreamSelectLoop();

            $first = self::settle(AsyncProbe::report($loop), $loop);
            $this->assertInstanceOf(ProbeReport::class, $first);
            $this->assertSame(
                self::sources(TerminfoReplayProbe::replaying(null)->runProbe()),
                self::sources($first),
                'a non-zero exit with empty stdout replays as "no dump": the environment answer stands',
            );
            $this->assertSame(['xterm-256color' => ''], $this->settledDumps(), 'the completed child books its verdict whatever its exit code');

            $second = self::settle(AsyncProbe::report($loop), $loop);
            $this->assertInstanceOf(ProbeReport::class, $second);
            $this->assertSame(['xterm-256color' => ''], $this->settledDumps());
            $this->assertSame(1, \substr_count((string) \file_get_contents($log), 'spawn'), 'the memoized verdict answers the repeat report without a second spawn');
        } finally {
            @\unlink($path);
            @\unlink($log);
        }
    }

    /**
     * The sync door and the async report agree on the verdict of the same
     * child, nonzero exit included: the dump the sync door memoizes is the one
     * the report memoizes and replays, and both surfaces spawn once per TERM.
     */
    public function testSyncDoorAndReportAgreeOnTheVerdictOfANonZeroChild(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $this->setTerm('xterm-256color');
        $log = \tempnam(\sys_get_temp_dir(), 'cp28parity');
        self::assertIsString($log);
        $path = $this->plantBinary(
            '#!/bin/sh' . "\n"
            . 'echo spawn >> ' . \escapeshellarg($log) . "\n"
            . "printf '\\tTc,\\n'\n"
            . "exit 3\n",
        );
        try {
            $syncDump = InfocmpBinary::runBounded('xterm-256color');
            $this->assertSame("\tTc,\n", $syncDump, 'the sync door keeps the dump of a nonzero child');
            InfocmpBinary::runBounded('xterm-256color');
            $this->assertSame(1, \substr_count((string) \file_get_contents($log), 'spawn'), 'the sync door memoizes its verdict');

            $loop = new StreamSelectLoop();
            $report = self::settle(AsyncProbe::report($loop), $loop);
            $this->assertSame(
                self::sources(TerminalProbe::run()),
                self::sources($report),
                'the async report composes the same answer the sync probe gives for the same child',
            );
            $this->assertContains('terminfo:Tc|RGB', self::sources($report));
            $this->assertSame(['xterm-256color' => "\tTc,\n"], $this->settledDumps(), 'the async memo holds the sync door\'s verdict');

            self::settle(AsyncProbe::report($loop), $loop);
            $this->assertSame(2, \substr_count((string) \file_get_contents($log), 'spawn'), 'one async spawn, the sync spawn before it: no re-spawn on either surface');
        } finally {
            @\unlink($path);
            @\unlink($log);
        }
    }

    /**
     * The TERM gate runs before any spawn on the report() path: a TERM infocmp
     * would parse as its own option books no slot and reaches no binary, and
     * the answer is the environment report the sync pipeline gives.
     */
    public function testUnsafeTermNeverReachesTheChildOnTheReportPath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $this->setTerm('-bad');
        $log = \tempnam(\sys_get_temp_dir(), 'cp28gate');
        self::assertIsString($log);
        \unlink($log);
        $path = $this->plantBinary('#!/bin/sh' . "\n" . 'echo spawn >> ' . \escapeshellarg($log) . "\n" . "exit 0\n");
        try {
            $loop = new StreamSelectLoop();
            $report = null;
            AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
                $report = $r;
            });

            $this->assertInstanceOf(ProbeReport::class, $report, 'an unsafe TERM settles in place');
            $this->assertCount(0, $this->inFlightSlots(), 'the TERM gate books no slot');
            $this->assertFileDoesNotExist($log, 'the planted binary must never be executed for an unsafe TERM');
            $this->assertSame(self::sources(TerminalProbe::run()), self::sources($report));
            $this->assertFalse(\in_array('terminfo:Tc|RGB', self::sources($report), true));
        } finally {
            @\unlink($path);
            @\unlink($log);
        }
    }

    /**
     * With no infocmp on the roster the report answers the environment alone:
     * no slot is booked, nothing is memoized, and the report equals the sync
     * composition on the same host. The recording pass never asks for a dump
     * without a binary (TerminalProbe's infocmpAvailable gate), so no verdict
     * exists to record.
     */
    public function testMissingBinaryNeverBooksAChildOnTheReportPath(): void
    {
        $this->setTerm('xterm-256color');
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, '');

        $loop = new StreamSelectLoop();
        $report = null;
        AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
            $report = $r;
        });

        $this->assertInstanceOf(ProbeReport::class, $report, 'a missing binary settles in place');
        $this->assertCount(0, $this->inFlightSlots(), 'no roster binary means no slot');
        $this->assertSame([], $this->settledDumps(), 'no binary, no verdict, no memo entry');
        $this->assertSame(self::sources(TerminalProbe::run()), self::sources($report));
    }

    /**
     * CP-14 on the report() surface: a loop that refuses the child's stream
     * watchers makes Process::start() throw after proc_open succeeded. The
     * spawn catch must release the booked slot, reap the launched child, and
     * settle the report with the environment answer in place, never throw and
     * never stay pending. The expectation on addReadStream proves the spawn
     * really reached start() before the refusal, so a regression that skips
     * the spawn would fail here rather than pass by accident.
     */
    public function testReportSpawnFailureSettlesTheEnvironmentReportAndReleasesTheSlot(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $this->setTerm('xterm-256color');
        $path = $this->plantBinary("#!/bin/sh\nexec sleep 30\n");
        try {
            $refusal = new \RuntimeException('test loop refuses stream watchers');
            $loop = $this->createMock(LoopInterface::class);
            $loop->expects($this->atLeastOnce())->method('addReadStream')->willThrowException($refusal);
            $loop->method('addWriteStream')->willThrowException($refusal);

            $report = null;
            AsyncProbe::report($loop)->then(static function (ProbeReport $r) use (&$report): void {
                $report = $r;
            });

            $this->assertInstanceOf(ProbeReport::class, $report, 'a failed spawn settles in place, without throwing');
            $this->assertSame(
                self::sources(TerminfoReplayProbe::replaying(null)->runProbe()),
                self::sources($report),
                'a failed spawn answers with the environment report, not a terminfo leg',
            );
            $this->assertCount(0, $this->inFlightSlots(), 'a failed spawn releases its booked cap slot');
            $this->assertSame(['xterm-256color' => null], $this->settledDumps(), 'a failed launch is a recorded miss, as on the sync door');
        } finally {
            @\unlink($path);
        }
    }

    /**
     * The 8 KiB ceiling is exact on the report() pump, the same cut the sync
     * door makes. The planted dump is ~36 KB with a Tc cap at its tail: the
     * memo must hold exactly InfocmpBinary::OUTPUT_CAP_BYTES bytes, and the
     * capability past the ceiling must never reach the report. A per-chunk
     * append guard would keep the whole first read (one pipe buffer) and
     * fail both assertions.
     */
    public function testStdoutCapIsExactAcrossASingleLargeRead(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $this->setTerm('xterm-256color');
        $path = $this->plantBinary(
            "#!/bin/sh\n"
            . "i=0\n"
            . "while [ \$i -lt 3000 ]; do printf '\\tfiller%d,\\n' \$i; i=\$((i+1)); done\n"
            . "printf '\\tTc,\\n'\n",
        );
        try {
            $loop = new StreamSelectLoop();
            $report = self::settle(AsyncProbe::report($loop), $loop);

            $this->assertInstanceOf(ProbeReport::class, $report);
            $dumps = $this->settledDumps();
            $this->assertArrayHasKey('xterm-256color', $dumps);
            $this->assertSame(
                InfocmpBinary::OUTPUT_CAP_BYTES,
                \strlen((string) $dumps['xterm-256color']),
                'the report pump keeps exactly the 8 KiB prefix, never a whole read past it',
            );
            $this->assertFalse(
                \in_array('terminfo:Tc|RGB', self::sources($report), true),
                'a capability beyond the ceiling must not reach the report',
            );
        } finally {
            @\unlink($path);
        }
    }

    /**
     * The dump memo clears in bulk when it reaches MEMO_MAX_ENTRIES (256), the
     * same ceiling the settled-answer memo and the sync dump table carry. A
     * full table plus one completed child leaves exactly that child's dump.
     */
    public function testDumpMemoClearsWhenFullThroughReport(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('the planted /bin/sh stub needs a POSIX shell.');
        }

        $full = [];
        for ($i = 1; $i <= 256; $i++) {
            $full["filler-term-{$i}"] = "filler-{$i}";
        }
        (new \ReflectionProperty(AsyncProbe::class, 'settledDumps'))->setValue(null, $full);

        $this->setTerm('xterm-newcap');
        $path = $this->plantBinary("#!/bin/sh\nprintf 'xterm-newcap|x,\\n'\n");
        try {
            $loop = new StreamSelectLoop();
            $report = self::settle(AsyncProbe::report($loop), $loop);

            $this->assertInstanceOf(ProbeReport::class, $report);
            $this->assertSame(
                ['xterm-newcap' => "xterm-newcap|x,\n"],
                $this->settledDumps(),
                'a write into a full dump memo clears it first, leaving only the new entry',
            );
        } finally {
            @\unlink($path);
        }
    }

    /** @return array<string, string|null> */
    private function settledDumps(): array
    {
        $prop = new \ReflectionProperty(AsyncProbe::class, 'settledDumps');

        return $prop->getValue();
    }

    /**
     * Stuff a temp script into InfocmpBinary's path memo (the house
     * binary-stub shape; tearDown() resets the memo).
     */
    private function plantBinary(string $body): string
    {
        $path = \tempnam(\sys_get_temp_dir(), 'cp28');
        self::assertIsString($path);
        \file_put_contents($path, $body);
        \chmod($path, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $path);

        return $path;
    }

    /** @return array<int, array{process: mixed, deadline: float}> */
    private function inFlightSlots(): array
    {
        $prop = new \ReflectionProperty(AsyncProbe::class, 'inFlight');

        return $prop->getValue();
    }

    /** @param array<int, array{process: mixed, deadline: float}> $slots */
    private function setInFlightSlots(array $slots): void
    {
        $prop = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $prop->setValue(null, $slots);
    }
}
