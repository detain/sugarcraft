<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use SugarCraft\ColorProfile\AsyncProbe;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

/**
 * @covers \SugarCraft\ColorProfile\AsyncProbe
 *
 * CP-14 contract: AsyncProbe::colorProfile() spawns its infocmp child ONLY
 * on an explicitly supplied loop; called without one it resolves in-place
 * through the bounded sync face. Tests that exercise the async leg pass a
 * private StreamSelectLoop and run THAT — the react/event-loop singleton is
 * never picked up implicitly.
 */
final class AsyncProbeTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Clear environment variables that might affect detection — and
        // save each key (CP-26): a developer/CI shell exporting
        // FORCE_COLOR=1|3 outranks every TERM rung asserted below, and the
        // old list omitted FORCE_COLOR entirely. tearDown restores.
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'FORCE_COLOR', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    public function testColorProfileReturnsPromiseInterface(): void
    {
        $promise = AsyncProbe::colorProfile();

        $this->assertInstanceOf(PromiseInterface::class, $promise);
    }

    public function testProbeColorProfileFloorsAsciiByDefault(): void
    {
        // This tests the underlying Probe that AsyncProbe falls back to.
        // Without TERM set, the answer is the colorless floor (CP-10 —
        // formerly Ansi-by-default over-detection).
        $profile = Probe::colorProfile();

        $this->assertSame(ColorProfile::Ascii, $profile);
    }

    public function testProbeColorProfileReturnsTrueColorWhenForced(): void
    {
        $_ENV['CLICOLOR_FORCE'] = '1';
        putenv('CLICOLOR_FORCE=1');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::TrueColor, $profile);
        } finally {
            unset($_ENV['CLICOLOR_FORCE']);
            putenv('CLICOLOR_FORCE');
        }
    }

    public function testProbeColorProfileReturnsAsciiWhenDisabled(): void
    {
        // CP-05 Shape B: suppression answers the colorless Ascii rung.
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::Ascii, $profile);
        } finally {
            unset($_ENV['NO_COLOR']);
            putenv('NO_COLOR');
        }
    }

    public function testProbeColorProfileReturnsANSI256For256ColorTerm(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::Ansi256, $profile);
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testProbeColorProfileReturnsANSIForXterm(): void
    {
        $_ENV['TERM'] = 'xterm';
        putenv('TERM=xterm');

        try {
            $profile = Probe::colorProfile();
            $this->assertSame(ColorProfile::Ansi, $profile);
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testProbeIsNoColorWhenNoColorSet(): void
    {
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');

        try {
            $this->assertTrue(Probe::isNoColor());
        } finally {
            unset($_ENV['NO_COLOR']);
            putenv('NO_COLOR');
        }
    }

    public function testProbeIsNoColorWhenNoColorNotSet(): void
    {
        $this->assertFalse(Probe::isNoColor());
    }

    public function testProbeIsForceColorWhenForced(): void
    {
        $_ENV['CLICOLOR_FORCE'] = '1';
        putenv('CLICOLOR_FORCE=1');

        try {
            $this->assertTrue(Probe::isForceColor());
        } finally {
            unset($_ENV['CLICOLOR_FORCE']);
            putenv('CLICOLOR_FORCE');
        }
    }

    public function testProbeIsForceColorWhenNotForced(): void
    {
        $this->assertFalse(Probe::isForceColor());
    }

    // ─── env gate, no fabricated TERM, bounded concurrency ───────────────

    public function testNoColorResolvesSynchronouslyWithoutSpawning(): void
    {
        $_ENV['NO_COLOR'] = '1';
        putenv('NO_COLOR=1');
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            // The suppression legs resolve through an already-resolved
            // promise — a pending child would leave $resolved null until
            // Loop::run(). CP-05: the colorless rung skips the promotion
            // leg (it can only ADD TrueColor), so the answer is Ascii.
            $this->assertSame(ColorProfile::Ascii, $resolved);
        } finally {
            unset($_ENV['NO_COLOR'], $_ENV['TERM']);
            putenv('NO_COLOR');
            putenv('TERM');
        }
    }

    public function testUnsetTermResolvesSyncInsteadOfProbingFabricatedXterm(): void
    {
        // TERM absent: the old code ran `infocmp -1 xterm`, reporting the
        // capabilities of a terminal nobody declared.
        $resolved = null;
        AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
            $resolved = $p;
        });

        $this->assertSame(ColorProfile::Ascii, $resolved, 'must be the sync colorless floor (CP-10), not an async probe');
    }

    public function testFreshSlotsAtCapDegradeToSyncDetection(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $counter = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $counter->setAccessible(true);

        try {
            $counter->setValue(null, self::fakeSlots(4, \microtime(true) + 10.0));

            $resolved = null;
            AsyncProbe::colorProfile(new \React\EventLoop\StreamSelectLoop())->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            $this->assertSame(ColorProfile::Ansi256, $resolved, 'at-cap calls must sync-resolve');
            $this->assertCount(4, $counter->getValue(), 'the cap path must neither spawn nor book a slot');
        } finally {
            self::clearSlots($counter);
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testStaleSlotsAreReclaimedAndTheAsyncLegSettles(): void
    {
        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $counter = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $counter->setAccessible(true);

        try {
            // Four slots whose deadline passed: their callers abandoned the
            // loop, so no exit event could ever release them. The next call
            // must reclaim them instead of degrading forever.
            $counter->setValue(null, self::fakeSlots(4, \microtime(true) - 10.0));

            $loop = new \React\EventLoop\StreamSelectLoop();
            $resolved = null;
            AsyncProbe::colorProfile($loop)->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            if ($resolved === null) {
                // The reclaimed capacity went to a real infocmp child on the
                // loop we passed in; run it until the settlement lands, with
                // a wall-clock watchdog so a wedged child cannot hang the
                // suite (the probe's own ceiling timer settles it at 5s+1s
                // regardless).
                $poll = null;
                $watchdog = $loop->addTimer(8.0, static function () use ($loop): void {
                    $loop->stop();
                });
                $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$resolved, &$poll, $watchdog): void {
                    if ($resolved === null) {
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

            $this->assertInstanceOf(ColorProfile::class, $resolved, 'reclaimed capacity must settle a result');
            $this->assertCount(0, $counter->getValue(), 'every settle path releases its slot');
        } finally {
            self::clearSlots($counter);
            unset($_ENV['TERM']);
            putenv('TERM');
        }
    }

    public function testEverySpawnPathBooksABoundedDeadlineSlot(): void
    {
        // Structural pin for the bounded-child ladder: the ceiling timer
        // escalates and settles, the exit handler cancels it, and stale
        // reclamation kills the wedged child — remove any leg and this reddens.
        $src = file_get_contents(__DIR__ . '/../src/AsyncProbe.php');
        $this->assertStringContainsString('addTimer(self::PROBE_TIMEOUT_SECONDS', $src);
        $this->assertStringContainsString('$process->terminate();', $src);
        $this->assertStringContainsString('terminate(defined(\'SIGKILL\') ? \\SIGKILL : 9)', $src);
        $this->assertStringContainsString('cancelTimer($timeoutTimer)', $src);
        $this->assertStringContainsString('reclaimStaleSlots()', $src);
    }

    public function testAsciiFloorSettlesFromTheChainWithoutSpawning(): void
    {
        // The terminfo leg may only ADD TrueColor on the Ansi/Ansi256 rungs
        // (parity with the sync Probe). On a forced Ascii floor the chain
        // answer is final: before the gate this resolved TrueColor straight
        // from the child's Tc-declaring stdout.
        Probe::_reset();
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);

        $printer = \tempnam(\sys_get_temp_dir(), 'tc');
        $this->assertIsString($printer);
        \file_put_contents($printer, "#!/bin/sh\nprintf '\\tTc,\\n'\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');
        $_ENV['FORCE_COLOR'] = '0';
        putenv('FORCE_COLOR=0');

        try {
            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            $this->assertSame(ColorProfile::Ascii, $resolved, 'the forced floor must settle from the chain, not the child');
            $this->assertCount(0, $slots->getValue(), 'the floor path must neither spawn nor book a slot');
        } finally {
            unset($_ENV['TERM'], $_ENV['FORCE_COLOR']);
            putenv('TERM');
            putenv('FORCE_COLOR');
            Probe::_reset();
            $slots->setValue(null, []);
            @\unlink($printer);
        }
    }

    public function testTrueColorLineBeyondTheStdoutCapIsDiscarded(): void
    {
        // M10: the capability scan judges AT MOST the kept prefix. A child that
        // buries its Tc declaration under more than 8 KiB of filler is noise by
        // contract — the line never reaches the accumulator. Without the cap
        // this exact child promoted the answer to TrueColor (base-red).
        //
        // The filler is ~128 KiB so the Tc bytes can never ride the first
        // pipe read (the OS pipe holds 64 KiB); once the accumulator has
        // reached the ceiling every later chunk is dropped whole.
        Probe::_reset();
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);

        $printer = \tempnam(\sys_get_temp_dir(), 'tc');
        $this->assertIsString($printer);
        $hadForceColor = \getenv('FORCE_COLOR');
        unset($_ENV['FORCE_COLOR']);
        putenv('FORCE_COLOR');
        \file_put_contents(
            $printer,
            "#!/bin/sh\nprintf '\\tFx0," . \str_repeat('x', 131100) . ",\\n'\nprintf '\\tTc,\\n'\n",
        );
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $loop = new \React\EventLoop\StreamSelectLoop();
            $resolved = null;
            AsyncProbe::colorProfile($loop)->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            if ($resolved === null) {
                $poll = null;
                $watchdog = $loop->addTimer(8.0, static function () use ($loop): void {
                    $loop->stop();
                });
                $poll = $loop->addPeriodicTimer(0.005, static function () use ($loop, &$resolved, &$poll, $watchdog): void {
                    if ($resolved === null) {
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

            $this->assertSame(
                ColorProfile::Ansi256,
                $resolved,
                'a Tc line past the 8 KiB prefix must not reach the capability scan',
            );
            $this->assertCount(0, $slots->getValue(), 'the settle released its slot');
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
            if ($hadForceColor !== false) {
                $_ENV['FORCE_COLOR'] = $hadForceColor;
                putenv('FORCE_COLOR=' . $hadForceColor);
            }
            Probe::_reset();
            $slots->setValue(null, []);
            @\unlink($printer);
        }
    }

    // ─── CP-14: explicit loop contract, start-throw safety, kill-AND-reap ───

    public function testNullLoopResolvesInPlaceThroughTheBoundedSyncFace(): void
    {
        // CP-14: called without a loop the surface spawns on NOTHING and the
        // promise settles in-place via the bounded synchronous face (chain +
        // deadline-capped door exec). The old code silently took the
        // react/event-loop singleton, so this exact call returned a PENDING
        // promise parked on a loop the plain-CLI caller never runs.
        Probe::_reset();
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);

        $counter = \tempnam(\sys_get_temp_dir(), 'cp-syncface-cnt');
        $printer = \tempnam(\sys_get_temp_dir(), 'cp-syncface');
        \file_put_contents($printer, "#!/bin/sh\nprintf x >> " . \escapeshellarg($counter) . "\nprintf '\\tTc,\\n'\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $resolved = null;
            AsyncProbe::colorProfile()->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            // Settled before the call even returned — there was no loop to run.
            $this->assertSame(
                ColorProfile::TrueColor,
                $resolved,
                'the no-loop path must settle in-place through the bounded sync face',
            );
            $this->assertSame(
                1,
                \strlen((string) \file_get_contents($counter)),
                'the answer is ONE door exec — the async surface adds no child of its own',
            );
            $this->assertCount(0, $slots->getValue(), 'the no-loop path registers nothing on any loop');
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
            Probe::_reset();
            @\unlink($printer);
            @\unlink($counter);
        }
    }

    public function testThrowingLoopReleasesTheSlotAndSettlesFromChain(): void
    {
        // CP-14 start-throw safety: `new Process()` and `start($loop)` are
        // the only fallible calls outside every promise handler. A loop that
        // refuses the child's stream watchers makes start() throw — the
        // exception must not escape the method, the booked cap slot must be
        // released, and the promise must settle with the chain answer.
        Probe::_reset();
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);

        $printer = \tempnam(\sys_get_temp_dir(), 'cp-throw');
        \file_put_contents($printer, "#!/bin/sh\nexec sleep 30\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        try {
            $resolved = null;
            AsyncProbe::colorProfile(new ThrowingProbeLoop())->then(function (ColorProfile $p) use (&$resolved): void {
                $resolved = $p;
            });

            $this->assertSame(
                ColorProfile::Ansi256,
                $resolved,
                'a failed spawn must fail open to the chain, in-place, without throwing',
            );
            $this->assertCount(0, $slots->getValue(), 'a failed spawn must release its cap slot');
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
            Probe::_reset();
            @\unlink($printer);
        }
    }

    public function testReclaimKillsAndReapsAbandonedChildrenAcrossCycles(): void
    {
        // CP-14 reclaim reap: terminate() alone strands a ZOMBIE — react/
        // child-process reaps only through Process::close(), which its own
        // code reaches from stream-close events on the child's (abandoned)
        // loop. Real abandoned children across ten cycles prove the reclaim
        // path leaves neither zombies nor a growing file-descriptor bill.
        //
        // The pid asserted is the child proc_open tracks (proc_get_status),
        // which is what terminate() signals and close() reaps — a killed-but-
        // unreaped child keeps its /proc entry as state Z on Linux, so entry
        // absence == killed AND reaped. The announce via a pidfile is dropped
        // because the sigchild wrapper dash forks makes the script a
        // grandchild; the tracked child stays alive while its subshell sleeps.
        if (\PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('zombie-fd accounting requires /proc');
        }

        Probe::_reset();
        $slots = new \ReflectionProperty(AsyncProbe::class, 'inFlight');
        $slots->setValue(null, []);

        $printer = \tempnam(\sys_get_temp_dir(), 'cp-reap');
        \file_put_contents($printer, "#!/bin/sh\nexec sleep 30\n");
        \chmod($printer, 0755);
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $_ENV['TERM'] = 'xterm-256color';
        putenv('TERM=xterm-256color');

        $cycles = 10;
        $fdCount = static fn (): int => \count(\array_diff(\scandir('/proc/self/fd'), ['.', '..']));
        $trackedPid = static function (\React\ChildProcess\Process $process): int {
            $resource = (new \ReflectionProperty($process, 'process'))->getValue($process);
            if (!\is_resource($resource)) {
                return -1;
            }
            return (int) (\proc_get_status($resource)['pid'] ?? -1);
        };

        $fdBaseline = $fdCount();
        $previousPid = null;

        try {
            for ($cycle = 1; $cycle <= $cycles; $cycle++) {
                // Spawn on a loop that is never run — an abandoning caller.
                AsyncProbe::colorProfile(new \React\EventLoop\StreamSelectLoop());

                if ($previousPid !== null) {
                    $entries = $slots->getValue();
                    $this->assertCount(1, $entries, "cycle {$cycle}: aged slot reclaimed, the new child owns the only one");
                    $this->assertFalse(
                        \file_exists('/proc/' . $previousPid),
                        "cycle {$cycle}: abandoned child {$previousPid} must be killed AND reaped — no zombie may accumulate",
                    );
                }

                $entries = $slots->getValue();
                $entry = \reset($entries);
                $pid = $trackedPid($entry['process']);
                $this->assertGreaterThan(0, $pid, "cycle {$cycle}: the direct child must be live before aging");
                $this->assertFileExists('/proc/' . $pid, "cycle {$cycle}: child {$pid} alive before aging");

                // Age its deadline so the NEXT call reclaims it.
                $key = \array_key_first($entries);
                $entries[$key]['deadline'] = \microtime(true) - 1.0;
                $slots->setValue(null, $entries);
                $previousPid = $pid;
            }

            // One more call reclaims the final aged child and leaves its own.
            AsyncProbe::colorProfile(new \React\EventLoop\StreamSelectLoop());
            $this->assertLessThan(
                $fdBaseline + 5,
                $fdCount(),
                "fds must not accumulate across {$cycles} abandon/reclaim cycles (baseline {$fdBaseline})",
            );

            $entries = $slots->getValue();
            $this->assertCount(1, $entries);
            $lastPid = $trackedPid(\reset($entries)['process']);
            $this->assertGreaterThan(0, $lastPid);

            foreach ($entries as $entry) {
                $entry['process']->terminate(defined('SIGKILL') ? \SIGKILL : 9);
                $entry['process']->close();
            }
            $this->assertFalse(\file_exists('/proc/' . $lastPid), 'the last abandoned child must be killed AND reaped too');
            $previousPid = null;
        } finally {
            unset($_ENV['TERM']);
            putenv('TERM');
            foreach ($slots->getValue() as $entry) {
                $entry['process']->terminate(defined('SIGKILL') ? \SIGKILL : 9);
                $entry['process']->close();
            }
            $slots->setValue(null, []);
            Probe::_reset();
            @\unlink($printer);
            if (isset($previousPid) && $previousPid > 0) {
                @\posix_kill($previousPid, \SIGKILL);
            }
        }
    }

    /**
     * @return array<int, array{process: \\React\\ChildProcess\\Process, deadline: float}>
     */
    private static function fakeSlots(int $count, float $deadline): array
    {
        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $slots[$i + 1] = ['process' => new \React\ChildProcess\Process('sleep 30'), 'deadline' => $deadline];
        }

        return $slots;
    }

    private static function clearSlots(\ReflectionProperty $counter): void
    {
        foreach ($counter->getValue() as $slot) {
            $slot['process']->terminate(defined('SIGKILL') ? \SIGKILL : 9);
        }
        $counter->setValue(null, []);
    }
}

/**
 * Loop double for the CP-14 start-throw pin: it refuses the child's stream
 * watchers exactly like a poisoned or shut-down loop would, so
 * Process::start() throws AFTER proc_open succeeded — the one shape that
 * proves the spawn catch releases the slot and settles from the chain.
 * Every other LoopInterface member is out of contract for that path and
 * shouts if the spawn flow ever reaches it.
 */
final class ThrowingProbeLoop implements \React\EventLoop\LoopInterface
{
    public function addReadStream($stream, $listener)
    {
        throw new \RuntimeException('test loop refuses stream watchers');
    }

    public function addWriteStream($stream, $listener)
    {
        throw new \RuntimeException('test loop refuses stream watchers');
    }

    public function removeReadStream($stream)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function removeWriteStream($stream)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function addTimer($interval, $callback)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function addPeriodicTimer($interval, $callback)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function cancelTimer(\React\EventLoop\TimerInterface $timer)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function futureTick($listener)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function addSignal($signal, $listener)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function removeSignal($signal, $listener)
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function run()
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }

    public function stop()
    {
        throw new \BadMethodCallException('out of contract for the spawn-failure path');
    }
}

