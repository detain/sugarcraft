<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Vcr\Recorder;

/**
 * P6.5.6 — performance baseline. Measure wallclock for the canonical
 * Shirley benchmark (`bash -c 'seq 100000'`) WITH and WITHOUT the
 * recorder tap, take the median across multiple runs, assert the
 * recorder overhead is ≤ 2 % per the plan target.
 *
 * Measurement protocol (matches the plan's CI-tolerance guidance):
 *   - One warmup iteration to seed FFI handles / kernel caches.
 *   - N = 5 timed iterations per scenario.
 *   - Sort + take median (not mean) — robust against single-shot
 *     stalls from GC / preemption / unrelated CPU spikes.
 *   - Assert (median_with - median_without) / median_without ≤ budget.
 *
 * The local microbench reports ~0 % overhead on Linux. The CI budget
 * is set at 5 % to absorb shared-runner noise without losing the
 * regression signal — a real recorder regression would land at
 * dozens of percent (full JSON serialization per chunk), so 5 %
 * still catches the class of bug this test exists to flag.
 */
final class ShirleyOverheadTest extends TestCase
{
    /**
     * Hard ceiling on the recorder overhead ratio. Plan acceptance is
     * "≤ 2 %", but the assertion budget includes CI tolerance.
     */
    private const OVERHEAD_BUDGET = 0.05;

    /** Plan's stated acceptance target — reported separately. */
    private const PLAN_TARGET_OVERHEAD = 0.02;

    /** Runs per scenario (≥ 5 per plan guidance). */
    private const RUNS = 5;

    /** Hard cap on the total integration runtime (seconds). */
    private const MAX_TOTAL_SEC = 30.0;

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
        if (!\is_executable('/bin/bash')) {
            $this->markTestSkipped('/bin/bash is not executable on this host.');
        }
        if (!\extension_loaded('pcntl')) {
            $this->markTestSkipped('ext-pcntl is required for controllingTerminal:true spawns.');
        }
    }

    public function testRecorderOverheadOnSeq100k(): void
    {
        $this->requirePtySyscalls();

        $start = \microtime(true);

        // Warmup — first PTY open lazily caches the FFI cdef, the
        // process page table, and the kernel's pty allocator pools.
        // Discard the timing.
        $this->runOnce(false);

        $without = [];
        $with = [];
        for ($i = 0; $i < self::RUNS; $i++) {
            $without[] = $this->runOnce(false);
            $with[]    = $this->runOnce(true);
        }
        \sort($without);
        \sort($with);
        $mid = (int) (self::RUNS / 2);
        $medianWithout = $without[$mid];
        $medianWith    = $with[$mid];

        $overhead = ($medianWith - $medianWithout) / $medianWithout;

        $report = \sprintf(
            "shirley overhead: without=%.3f ms (min=%.3f max=%.3f), with=%.3f ms (min=%.3f max=%.3f), overhead=%.2f%% (plan target ≤%.1f%%, CI budget ≤%.1f%%)",
            $medianWithout * 1000,
            $without[0] * 1000,
            $without[self::RUNS - 1] * 1000,
            $medianWith * 1000,
            $with[0] * 1000,
            $with[self::RUNS - 1] * 1000,
            $overhead * 100,
            self::PLAN_TARGET_OVERHEAD * 100,
            self::OVERHEAD_BUDGET * 100,
        );
        \fwrite(\STDERR, "\n[ShirleyOverheadTest] {$report}\n");

        $totalElapsed = \microtime(true) - $start;
        $this->assertLessThan(
            self::MAX_TOTAL_SEC,
            $totalElapsed,
            'perf bench must stay under 30 s wallclock',
        );

        $this->assertLessThan(
            self::OVERHEAD_BUDGET,
            $overhead,
            $report,
        );
    }

    /**
     * One timed iteration of the benchmark. Spawns bash under a fresh
     * PTY pair, runs the pump (optionally with a recorder tap),
     * returns wallclock seconds.
     *
     * The cassette file is unlinked immediately after the run so disk
     * pressure stays bounded across the bench.
     */
    private function runOnce(bool $withRecorder): float
    {
        $system = PtySystemFactory::default();
        $pair = $system->open(80, 24);
        $child = $pair->slave()->spawn(['/bin/bash', '-c', 'seq 100000']);

        $stdin = \fopen('/dev/null', 'r');
        $stdout = \fopen('php://memory', 'w+b');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        $opts = new PumpOptions();
        $cassette = null;
        if ($withRecorder) {
            $cassette = \tempnam(\sys_get_temp_dir(), 'shirley-perf-');
            $this->assertIsString($cassette);
            $opts = $opts->withRecorder(Recorder::open($cassette));
        }

        $start = \microtime(true);
        (new PosixPump())->run(
            $pair->master(),
            $stdin,
            $stdout,
            $child,
            $opts,
        );
        if (!$child->exited()) {
            $child->wait();
        }
        $elapsed = \microtime(true) - $start;

        \fclose($stdin);
        \fclose($stdout);
        if (!$pair->master()->isClosed()) {
            $pair->master()->close();
        }
        if ($cassette !== null && \file_exists($cassette)) {
            @\unlink($cassette);
        }
        return $elapsed;
    }
}
