<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Vcr\Recorder;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * P6.5.6 — performance baseline. Measure wallclock for the canonical
 * Shirley benchmark (`bash -c 'seq 100000'`) WITH and WITHOUT the
 * recorder tap, reduce each scenario to a trimmed mean, and assert the
 * recorder overhead ratio stays within budget.
 *
 * Measurement protocol (matches the plan's CI-tolerance guidance):
 *   - One warmup iteration to seed FFI handles / kernel caches.
 *   - 6 timed iterations per scenario (two interleaved rounds of
 *     ROUNDS = 3 without/with pairs, controlling for CPU cache drift).
 *   - Sort + trimmed mean — discard the min and max, average the
 *     middle four — which is more stable than a median on this
 *     sample size while still shrugging off single-shot stalls.
 *   - Assert (mean_with - mean_without) / mean_without ≤ budget.
 *
 * The assertion budget is {@see self::OVERHEAD_BUDGET} = 8 % to absorb
 * shared-runner noise without losing the regression signal; the plan's
 * 2 % acceptance target ({@see self::PLAN_TARGET_OVERHEAD}) is
 * reported but not asserted. A real recorder regression lands at
 * dozens of percent (full JSON serialization per chunk), so 8 % still
 * catches the class of bug this test exists to flag.
 */
final class ShirleyOverheadTest extends TestCase
{
    /**
     * Hard ceiling on the recorder overhead ratio. Plan acceptance is
     * "≤ 2 %", but the assertion budget includes CI tolerance.
     * Using trimmed mean (discarding min/max) gives more stable central
     * tendency, so we can use a slightly tighter budget.
     */
    private const OVERHEAD_BUDGET = 0.08;

    /** Plan's stated acceptance target — reported separately. */
    private const PLAN_TARGET_OVERHEAD = 0.02;

    /** Number of full round-trips (without, with) to reduce ordering bias. */
    private const ROUNDS = 3;

    /** Hard cap on the total integration runtime (seconds). */
    private const MAX_TOTAL_SEC = 45.0;

    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('sugar-pty is POSIX-only; Windows ConPTY is a separate port.');
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

        // Interleave runs to reduce ordering bias: do full round-trips
        // (without, with) rather than all without first then all with.
        // This controls for CPU cache state drift across the bench.
        $without = [];
        $with = [];
        for ($r = 0; $r < self::ROUNDS; $r++) {
            $without[] = $this->runOnce(false);
            $with[]    = $this->runOnce(true);
        }
        for ($r = 0; $r < self::ROUNDS; $r++) {
            $without[] = $this->runOnce(false);
            $with[]    = $this->runOnce(true);
        }

        // Use trimmed mean (discard min and max) for more stable central
        // tendency than median, which is still sensitive to outliers.
        \sort($without);
        \sort($with);
        $trim = 1;
        $withoutTrimmed = \array_slice($without, $trim, \count($without) - 2 * $trim);
        $withTrimmed = \array_slice($with, $trim, \count($with) - 2 * $trim);
        $avgWithout = \array_sum($withoutTrimmed) / \count($withoutTrimmed);
        $avgWith = \array_sum($withTrimmed) / \count($withTrimmed);

        $overhead = ($avgWith - $avgWithout) / $avgWithout;

        $report = \sprintf(
            "shirley overhead: without=%.3f ms (min=%.3f max=%.3f), with=%.3f ms (min=%.3f max=%.3f), overhead=%.2f%% (plan target ≤%.1f%%, CI budget ≤%.1f%%)",
            $avgWithout * 1000,
            $without[0] * 1000,
            $without[\count($without) - 1] * 1000,
            $avgWith * 1000,
            $with[0] * 1000,
            $with[\count($with) - 1] * 1000,
            $overhead * 100,
            self::PLAN_TARGET_OVERHEAD * 100,
            self::OVERHEAD_BUDGET * 100,
        );
        \fwrite(\STDERR, "\n[ShirleyOverheadTest] {$report}\n");

        $totalElapsed = \microtime(true) - $start;
        $this->assertLessThan(
            self::MAX_TOTAL_SEC,
            $totalElapsed,
            'perf bench must stay under 45 s wallclock',
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

        $stdin = Stream::devNull();
        $stdout = Stream::memory('w+b');
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
