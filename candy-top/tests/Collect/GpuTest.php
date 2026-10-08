<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\GpuOutcome;
use SugarCraft\Top\Collect\Sentinel;

final class GpuTest extends TestCase
{
    private float $now = 1000.0;
    private int $calls = 0;

    /** @var list<array{0: GpuOutcome, 1: string}> */
    private array $script = [];

    private const string CSV = "0, 42, 2048, 10240, 61, 220.50, NVIDIA A100-SXM4-40GB, MIG 1g.5gb\n1, [N/A], 0, 15360, [N/A], [Not Supported], Tesla T4\n";

    private function gpu(float $interval = 5.0): Gpu
    {
        return Gpu::new(function (array $argv): array {
            $this->calls++;

            return array_shift($this->script) ?? [GpuOutcome::Ok, self::CSV];
        }, fn (): float => $this->now, $interval, ['nvidia-smi'], [Gpu::QUERY]);
    }

    public function testParsesCsvWithNameLastAndCommasInName(): void
    {
        $seen = null;
        $gpu = Gpu::new(static function (array $argv) use (&$seen): array {
            $seen = $argv;

            return [GpuOutcome::Ok, self::CSV];
        }, candidates: ['nvidia-smi'], queries: [Gpu::QUERY]);
        [$snap] = $gpu->sample();

        $this->assertSame('nvidia-smi', $seen[0]);
        $this->assertSame('--query-gpu=' . implode(',', Gpu::QUERY), $seen[1]);
        $this->assertSame('name', Gpu::QUERY[array_key_last(Gpu::QUERY)]);
        $this->assertCount(2, $snap->devices);
        $a100 = $snap->devices[0];
        $this->assertSame('NVIDIA A100-SXM4-40GB, MIG 1g.5gb', $a100->name);
        $this->assertSame(42.0, $a100->utilization);
        $this->assertSame(2048 * 1048576, $a100->memUsed);
        $this->assertSame(10240 * 1048576, $a100->memTotal);
        $this->assertSame(61.0, $a100->temp);
        $this->assertSame(220.5, $a100->watts);
        $this->assertSame('Tesla T4', $snap->devices[1]->name);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[1]->utilization);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[1]->watts);
    }

    public function testQueriesAreSpacedByIntervalAndReuseLastSnapshot(): void
    {
        [$first, $gpu] = $this->gpu()->sample();
        $this->now += 2.0;
        [$cached, $gpu] = $gpu->sample();
        $this->assertSame(1, $this->calls);
        $this->assertSame($first, $cached);

        $this->now += 3.0;
        $gpu->sample();
        $this->assertSame(2, $this->calls);
    }

    public function testTimeoutIsTransientWithExponentialBackoff(): void
    {
        $this->script = [[GpuOutcome::Timeout, ''], [GpuOutcome::Timeout, '']];
        [$snap, $gpu] = $this->gpu()->sample();
        $this->assertFalse($snap->available());
        $this->assertFalse($gpu->absent(), 'a timeout is never memoized as absent');

        // First backoff: 5 × 2¹ = 10 s.
        $this->now += 9.0;
        $gpu->sample();
        $this->assertSame(1, $this->calls);
        $this->now += 1.0;
        [, $gpu] = $gpu->sample();
        $this->assertSame(2, $this->calls);

        // Second backoff: 5 × 2² = 20 s; then the GPU answers again.
        $this->now += 19.0;
        $gpu->sample();
        $this->assertSame(2, $this->calls);
        $this->now += 1.0;
        [$snap, $gpu] = $gpu->sample();
        $this->assertTrue($snap->available());

        // Success resets the backoff to the plain interval.
        $this->now += 5.0;
        $gpu->sample();
        $this->assertSame(4, $this->calls);
    }

    public function testAbsentIsMemoizedAndNeverSpawnsAgain(): void
    {
        $this->script = [[GpuOutcome::Absent, '']];
        [$snap, $gpu] = $this->gpu()->sample();
        $this->now += 1000.0;
        [$snap2, $gpu] = $gpu->sample();

        $this->assertFalse($snap->available());
        $this->assertFalse($snap2->available());
        $this->assertTrue($gpu->absent());
        $this->assertSame(1, $this->calls);
    }

    public function testFailureAfterASuccessBacksOffInsteadOfGoingAbsent(): void
    {
        $this->script = [[GpuOutcome::Ok, self::CSV], [GpuOutcome::Absent, ''], [GpuOutcome::Ok, "garbage\n"]];
        [, $gpu] = $this->gpu()->sample();

        // Driver reload: non-zero exit on a host that had a GPU.
        $this->now += 5.0;
        [$snap, $gpu] = $gpu->sample();
        $this->assertFalse($snap->available());
        $this->assertFalse($gpu->absent(), 'a GPU that answered once is never memoized absent');

        // Backoff 5 × 2¹ = 10 s, then unparseable output backs off again (× 2²).
        $this->now += 9.0;
        $gpu->sample();
        $this->assertSame(2, $this->calls);
        $this->now += 1.0;
        [, $gpu] = $gpu->sample();
        $this->assertSame(3, $this->calls);
        $this->assertFalse($gpu->absent());
        $this->now += 19.0;
        $gpu->sample();
        $this->assertSame(3, $this->calls);

        // Recovery.
        $this->now += 1.0;
        [$snap] = $gpu->sample();
        $this->assertSame(4, $this->calls);
        $this->assertTrue($snap->available());
    }

    public function testGarbageOutputCountsAsAbsent(): void
    {
        $this->script = [[GpuOutcome::Ok, "NVIDIA-SMI has failed\n"]];
        [$snap, $gpu] = $this->gpu()->sample();

        $this->assertFalse($snap->available());
        $this->assertTrue($gpu->absent());
    }

    public function testDefaultRunnerNeverThrowsWhenBinaryMissing(): void
    {
        $path = getenv('PATH');
        putenv('PATH=/nonexistent-candy-top');
        try {
            [$snap, $gpu] = Gpu::new()->sample();
        } finally {
            putenv('PATH=' . $path);
        }

        $this->assertFalse($snap->available());
        $this->assertTrue($gpu->absent());
    }

    /**
     * Drives the real proc_open runner against a fake nvidia-smi script.
     *
     * @return array{0: \SugarCraft\Top\Collect\GpuSnapshot, 1: Gpu, 2: float}
     */
    private function withFakeBinary(string $body): array
    {
        $dir = sys_get_temp_dir() . '/candy-top-gpu-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/nvidia-smi', "#!/bin/sh\n" . $body . "\n");
        chmod($dir . '/nvidia-smi', 0755);
        $path = getenv('PATH');
        putenv('PATH=' . $dir . PATH_SEPARATOR . $path);
        try {
            $start = microtime(true);
            [$snap, $gpu] = Gpu::new(queries: [Gpu::QUERY])->sample();

            return [$snap, $gpu, microtime(true) - $start];
        } finally {
            putenv('PATH=' . $path);
            @unlink($dir . '/nvidia-smi');
            @rmdir($dir);
        }
    }

    public function testDefaultRunnerReadsRealChildOutput(): void
    {
        [$snap, $gpu] = $this->withFakeBinary("echo '0, 7, 1, 2, 30, 15.5, Fake GPU'");

        $this->assertTrue($snap->available());
        $this->assertSame('Fake GPU', $snap->devices[0]->name);
        $this->assertFalse($gpu->absent());
    }

    public function testDefaultRunnerNonZeroExitIsAbsent(): void
    {
        [$snap, $gpu] = $this->withFakeBinary("echo '0, 7, 1, 2, 30, 15.5, Fake GPU'; exit 9");

        $this->assertFalse($snap->available());
        $this->assertTrue($gpu->absent());
    }

    public function testDefaultRunnerKillsHungChildBoundedAndRetriesLater(): void
    {
        // exec so the SIGKILL lands on sleep itself, not an orphaned grandchild.
        [$snap, $gpu, $elapsed] = $this->withFakeBinary('exec sleep 30');

        $this->assertFalse($snap->available());
        $this->assertFalse($gpu->absent(), 'timeout is transient');
        $this->assertLessThan(5.0, $elapsed, 'TIMEOUT 2s + REAP_WAIT 0.5s, far below the child\'s 30s');
    }

    private static function fixture(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/fixtures/linux/nvidia-smi/' . $file);
    }

    /**
     * Real output of a 4× RTX PRO 6000 Blackwell host (driver 595.91.07),
     * prompt_kit/findings/nvidia-smi-skynet2.md: multi-GPU, "N/A" memory
     * temperature, pstate text, and one pid (377306) on two GPUs.
     *
     * @return \Closure(list<string>): array{0: GpuOutcome, 1: string}
     */
    private function skynet2(): \Closure
    {
        return function (array $argv): array {
            $this->calls++;
            $this->argvs[] = $argv;
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                return [GpuOutcome::Ok, self::fixture('skynet2-compute-apps.csv')];
            }
            if ($argv[1] === 'pmon') {
                return [GpuOutcome::Ok, (string) file_get_contents(dirname(__DIR__) . '/fixtures/gpu/nvidia/skynet2-pmon.txt')];
            }

            return [GpuOutcome::Ok, self::fixture($argv[1] === '--query-gpu=' . implode(',', Gpu::QUERY_EXTENDED)
                ? 'skynet2-query-extended.csv'
                : 'skynet2-query-base.csv')];
        };
    }

    /** @var list<list<string>> */
    private array $argvs = [];

    public function testRealMultiGpuExtendedQueryParses(): void
    {
        [$snap, $gpu] = Gpu::new($this->skynet2(), fn (): float => $this->now, candidates: ['/usr/bin/nvidia-smi'])->sample();

        $this->assertCount(4, $snap->devices);
        $d = $snap->devices[2];
        $this->assertSame(2, $d->index);
        $this->assertSame('NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition', $d->name);
        $this->assertSame(100.0, $d->utilization);
        $this->assertSame(48243 * 1048576, $d->memUsed);
        $this->assertSame(97887 * 1048576, $d->memTotal);
        $this->assertSame(79.0, $d->temp);
        $this->assertSame(299.98, $d->watts);
        $this->assertSame(75.0, $d->memUtilization);
        $this->assertSame(0.0, $d->encoderUtilization);
        $this->assertSame(0.0, $d->decoderUtilization);
        $this->assertSame(300.0, $d->powerLimit);
        $this->assertSame(1095.0, $d->clockGraphics);
        $this->assertSame(13365.0, $d->clockMem);
        $this->assertSame(3090.0, $d->clockGraphicsMax);
        $this->assertSame(14001.0, $d->clockMemMax);
        $this->assertSame(53.0, $d->fanSpeed);
        $this->assertSame('P1', $d->pstate);
        $this->assertSame(5, $d->pcieGen);
        $this->assertSame(16, $d->pcieWidth);
        $this->assertSame(Sentinel::UNMEASURED, $d->tempMem, 'unbracketed "N/A" is a sentinel too');
        $this->assertSame('GPU-d9a18a51-6b47-71b3-44aa-dfd4448a0e88', $d->uuid);
        $this->assertEqualsWithDelta(49.28, $d->memPercent(), 0.01);
        $this->assertSame('/usr/bin/nvidia-smi', $gpu->binary());
        $this->assertSame('--query-gpu=' . implode(',', Gpu::QUERY_EXTENDED), $this->argvs[0][1]);
    }

    public function testComputeAppsMapUuidToIndexAndSumAcrossGpus(): void
    {
        [$snap] = Gpu::new($this->skynet2(), fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertSame(3, $this->calls, 'device query + compute-apps query + pmon');
        $this->assertSame('--query-compute-apps=pid,gpu_uuid,used_memory', $this->argvs[1][1]);
        $this->assertNotNull($snap->processes);
        $this->assertCount(6, $snap->processes);
        $p = $snap->processes[2];
        $this->assertSame(377306, $p->pid);
        $this->assertSame(2, $p->gpuIndex);
        $this->assertSame(550 * 1048576, $p->usedMemory);
        $this->assertSame(3, $snap->processes[4]->gpuIndex, 'same pid, second GPU');

        $byPid = $snap->memoryByPid();
        $this->assertSame(1100 * 1048576, $byPid[377306]);
        $this->assertSame(90480 * 1048576, $byPid[2094147]);
    }

    public function testComputeAppsNaIsSentinelAndUnknownUuidIsMinusOne(): void
    {
        $runner = static fn (array $argv): array => str_starts_with($argv[1], '--query-compute-apps=')
            ? [GpuOutcome::Ok, "10, GPU-gone, [N/A]\n11, GPU-a, 12\nNo running processes found\n"]
            : [GpuOutcome::Ok, "0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, [N/A], P0, 3, 8, N/A, GPU-a, Fake\n"];
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'])->withProcesses()->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[0]->fanSpeed, 'passively cooled → [N/A]');
        $this->assertSame(-1, $snap->processes[0]->gpuIndex);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->processes[0]->usedMemory);
        $this->assertSame(0, $snap->processes[1]->gpuIndex);
        $this->assertSame([10 => Sentinel::UNMEASURED_INT, 11 => 12 * 1048576], $snap->memoryByPid());
    }

    public function testComputeAppsFailureLeavesDevicesIntact(): void
    {
        $runner = static fn (array $argv): array => str_starts_with($argv[1], '--query-compute-apps=')
            ? [GpuOutcome::Absent, '']
            : [GpuOutcome::Ok, self::fixture('skynet2-query-extended.csv')];
        [$snap, $gpu] = Gpu::new($runner, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertCount(4, $snap->devices);
        $this->assertNull($snap->processes, 'not measured, not "no processes"');
        $this->assertFalse($gpu->absent());
    }

    public function testBaseQueryCarriesNoUuidSoProcessesAreNotQueried(): void
    {
        [$snap] = Gpu::new($this->skynet2(), candidates: ['nvidia-smi'], queries: [Gpu::QUERY], processes: true)->sample();

        $this->assertSame(1, $this->calls);
        $this->assertCount(4, $snap->devices);
        $this->assertNull($snap->processes);
        $this->assertSame(Sentinel::UNAVAILABLE, $snap->devices[0]->pstate);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[0]->powerLimit);
    }

    /**
     * An older driver rejects an unknown field with exit 2 ("Field … is not
     * a valid field to query.") — that must fall back to the base query,
     * never memoize "no GPU", and pin the base query afterwards.
     */
    public function testInvalidFieldExitFallsBackToBaseQueryAndPinsIt(): void
    {
        $runner = function (array $argv): array {
            $this->argvs[] = $argv;

            return $argv[1] === '--query-gpu=' . implode(',', Gpu::QUERY_EXTENDED)
                ? [GpuOutcome::Absent, '']
                : [GpuOutcome::Ok, self::fixture('skynet2-query-base.csv')];
        };
        [$snap, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['nvidia-smi'])->sample();
        $this->assertCount(4, $snap->devices);
        $this->assertFalse($gpu->absent());

        $this->now += 5.0;
        $this->argvs = [];
        $gpu->sample();
        $this->assertCount(1, $this->argvs, 'pinned query: no re-probe of the extended one');
        $this->assertSame('--query-gpu=' . implode(',', Gpu::QUERY), $this->argvs[0][1]);
    }

    /** btop #1869 analog: a broken first nvidia-smi must not hide a working later one. */
    public function testFailingFirstCandidateFallsThroughToNextAndPinsIt(): void
    {
        $runner = function (array $argv): array {
            $this->argvs[] = $argv;

            return $argv[0] === '/usr/lib/wsl/lib/nvidia-smi'
                ? [GpuOutcome::Ok, self::CSV]
                : [GpuOutcome::Absent, '']; // "couldn't communicate with the NVIDIA driver"
        };
        [$snap, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['/usr/bin/nvidia-smi', '/usr/lib/wsl/lib/nvidia-smi'], queries: [Gpu::QUERY])->sample();

        $this->assertTrue($snap->available());
        $this->assertSame('/usr/lib/wsl/lib/nvidia-smi', $gpu->binary());
        $this->assertSame(['/usr/bin/nvidia-smi', '/usr/lib/wsl/lib/nvidia-smi'], array_column($this->argvs, 0));

        $this->now += 5.0;
        $this->argvs = [];
        $gpu->sample();
        $this->assertSame(['/usr/lib/wsl/lib/nvidia-smi'], array_column($this->argvs, 0), 'winner pinned');
    }

    public function testPinnedBinaryThatKeepsFailingIsUnpinnedAndTheSweepReruns(): void
    {
        $gone = false;
        $runner = function (array $argv) use (&$gone): array {
            $this->argvs[] = $argv;

            return match (true) {
                $argv[0] === '/usr/bin/nvidia-smi' && !$gone => [GpuOutcome::Ok, self::CSV],
                $argv[0] === '/usr/lib/wsl/lib/nvidia-smi' && $gone => [GpuOutcome::Ok, self::CSV],
                default => [GpuOutcome::Absent, ''],
            };
        };
        [, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['/usr/bin/nvidia-smi', '/usr/lib/wsl/lib/nvidia-smi'], queries: [Gpu::QUERY])->sample();
        $this->assertSame('/usr/bin/nvidia-smi', $gpu->binary());

        $gone = true; // binary removed by a driver upgrade
        $waits = [5.0, 10.0, 20.0]; // interval, then backoff 5 × 2¹, 5 × 2²
        for ($i = 1; $i <= Gpu::PIN_MAX_FAILURES; $i++) {
            $this->now += $waits[$i - 1];
            $this->argvs = [];
            [$snap, $gpu] = $gpu->sample();
            $this->assertFalse($snap->available());
            $this->assertSame(['/usr/bin/nvidia-smi'], array_column($this->argvs, 0), "failure {$i}: pinned binary only");
            $this->assertFalse($gpu->absent());
        }
        $this->assertNull($gpu->binary(), 'unpinned after PIN_MAX_FAILURES consecutive failures');

        // The backoff still applies before the re-sweep (5 × 2³ = 40 s).
        $this->now += 39.0;
        $this->argvs = [];
        $gpu->sample();
        $this->assertSame([], $this->argvs);

        $this->now += 1.0;
        [$snap, $gpu] = $gpu->sample();
        $this->assertTrue($snap->available());
        $this->assertSame(['/usr/bin/nvidia-smi', '/usr/lib/wsl/lib/nvidia-smi'], array_column($this->argvs, 0), 'full sweep re-ran');
        $this->assertSame('/usr/lib/wsl/lib/nvidia-smi', $gpu->binary(), 'new winner pinned');
    }

    public function testUnpinRediscoversCandidatesWhenNewDiscoveredThem(): void
    {
        $root = sys_get_temp_dir() . '/candy-top-gpu-' . bin2hex(random_bytes(6));
        mkdir("{$root}/old", 0777, true);
        mkdir("{$root}/new", 0777, true);
        touch("{$root}/old/nvidia-smi");
        chmod("{$root}/old/nvidia-smi", 0755);
        $path = getenv('PATH');
        putenv("PATH={$root}/old");
        try {
            $runner = function (array $argv): array {
                $this->argvs[] = $argv;

                return is_file($argv[0]) ? [GpuOutcome::Ok, self::CSV] : [GpuOutcome::Absent, ''];
            };
            [, $gpu] = Gpu::new($runner, fn (): float => $this->now, queries: [Gpu::QUERY])->sample();
            $this->assertSame("{$root}/old/nvidia-smi", $gpu->binary());

            rename("{$root}/old/nvidia-smi", "{$root}/new/nvidia-smi");
            putenv("PATH={$root}/new");
            for ($i = 0; $i < Gpu::PIN_MAX_FAILURES; $i++) {
                $this->now += 1000.0;
                [, $gpu] = $gpu->sample();
            }
            $this->assertNull($gpu->binary());
            $this->now += 1000.0;
            [$snap, $gpu] = $gpu->sample();
            $this->assertTrue($snap->available());
            $this->assertSame("{$root}/new/nvidia-smi", $gpu->binary(), 'found at its new PATH location');
        } finally {
            putenv($path === false ? 'PATH' : "PATH={$path}");
            @unlink("{$root}/new/nvidia-smi");
            @rmdir("{$root}/old");
            @rmdir("{$root}/new");
            @rmdir($root);
        }
    }

    public function testPinnedTimeoutsAndSuccessesResetTheUnpinCount(): void
    {
        $this->script = [
            [GpuOutcome::Ok, self::CSV],
            [GpuOutcome::Absent, ''], [GpuOutcome::Absent, ''], [GpuOutcome::Timeout, ''],
            [GpuOutcome::Absent, ''], [GpuOutcome::Absent, ''], [GpuOutcome::Ok, self::CSV],
            [GpuOutcome::Absent, ''], [GpuOutcome::Absent, ''],
        ];
        [, $gpu] = $this->gpu()->sample();
        for ($i = 0; $i < 8; $i++) {
            $this->now += 1000.0; // past any backoff
            [, $gpu] = $gpu->sample();
            $this->assertSame('nvidia-smi', $gpu->binary(), "step {$i}: never three non-timeout failures in a row");
        }
        $this->assertSame(9, $this->calls);
    }

    public function testEveryCandidateFailingIsMemoizedAbsent(): void
    {
        $runner = function (array $argv): array {
            $this->calls++;

            return [GpuOutcome::Absent, ''];
        };
        [, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['/a/nvidia-smi', '/b/nvidia-smi'])->sample();
        $this->assertTrue($gpu->absent());
        $this->assertSame(4, $this->calls, '2 candidates × 2 queries, once');

        $this->now += 1000.0;
        $gpu->sample();
        $this->assertSame(4, $this->calls);
    }

    /**
     * A hung first candidate reached a driver that hung; every other
     * candidate talks to the same driver. The sweep must cost exactly one
     * timeout, then back off — not N × (TIMEOUT + REAP_WAIT) and N stuck
     * children.
     */
    public function testHungFirstCandidateCostsExactlyOneTimeoutThenBacksOff(): void
    {
        $runner = function (array $argv): array {
            $this->argvs[] = $argv;

            return [GpuOutcome::Timeout, ''];
        };
        [$snap, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['/a/nvidia-smi', '/b/nvidia-smi', '/c/nvidia-smi'])->sample();

        $this->assertFalse($snap->available());
        $this->assertFalse($gpu->absent(), 'a timeout is never memoized absent');
        $this->assertSame(['/a/nvidia-smi'], array_column($this->argvs, 0), 'one timeout, no further candidate or query');
        $this->now += 9.0;
        $this->argvs = [];
        $gpu->sample();
        $this->assertSame([], $this->argvs, 'backoff 5 × 2¹');
        $this->now += 1.0;
        $gpu->sample();
        $this->assertSame(['/a/nvidia-smi'], array_column($this->argvs, 0), 'retried from the top after the backoff, still one timeout');
    }

    public function testTimeoutAfterAFailedCandidateStopsTheSweep(): void
    {
        $runner = function (array $argv): array {
            $this->argvs[] = $argv;

            return match ($argv[0]) {
                '/a/nvidia-smi' => [GpuOutcome::Absent, ''],
                '/b/nvidia-smi' => [GpuOutcome::Timeout, ''],
                default => [GpuOutcome::Ok, self::CSV],
            };
        };
        [$snap, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['/a/nvidia-smi', '/b/nvidia-smi', '/c/nvidia-smi'])->sample();

        $this->assertFalse($snap->available());
        $this->assertFalse($gpu->absent());
        $this->assertSame(['/a/nvidia-smi', '/a/nvidia-smi', '/b/nvidia-smi'], array_column($this->argvs, 0));
    }

    public function testProcessesAreOptIn(): void
    {
        [$snap, $gpu] = Gpu::new($this->skynet2(), fn (): float => $this->now, candidates: ['nvidia-smi'])->sample();

        $this->assertSame(1, $this->calls, 'no compute-apps spawn by default');
        $this->assertNull($snap->processes);
        $this->assertFalse($gpu->processesEnabled());
        $this->assertTrue($gpu->withProcesses()->processesEnabled());
        $this->assertFalse($gpu->withProcesses()->withProcesses(false)->processesEnabled());

        $this->now += 5.0;
        [$snap] = $gpu->withProcesses()->sample();
        $this->assertSame(4, $this->calls, 'device + compute-apps + pmon once opted in');
        $this->assertNotNull($snap->processes);
    }

    /**
     * A driver that answers the device query but hangs on compute-apps must
     * not block every interval: exponential backoff, then off for
     * APPS_RETRY_AFTER after APPS_MAX_TIMEOUTS in a row — and the device
     * schedule is untouched throughout.
     */
    public function testComputeAppsTimeoutsBackOffThenDisableThenRetry(): void
    {
        $appsCalls = 0;
        $appsHang = true;
        $runner = function (array $argv) use (&$appsCalls, &$appsHang): array {
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                $appsCalls++;

                return $appsHang ? [GpuOutcome::Timeout, ''] : [GpuOutcome::Ok, self::fixture('skynet2-compute-apps.csv')];
            }
            if ($argv[1] === 'pmon') {
                return [GpuOutcome::Absent, '']; // pmon has its own schedule (see the pmon tests)
            }
            $this->calls++;

            return [GpuOutcome::Ok, self::fixture('skynet2-query-extended.csv')];
        };
        $gpu = Gpu::new($runner, fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true);
        $tick = function (float $dt) use (&$gpu): ?array {
            $this->now += $dt;
            [$snap, $gpu] = $gpu->sample();
            $this->assertTrue($snap->available(), 'device query unaffected by compute-apps hangs');

            return $snap->processes;
        };

        $this->assertNull($tick(0.0));
        $this->assertSame(1, $appsCalls, 'timeout #1 → apps backoff 5 × 2¹ = 10 s');
        $tick(5.0);
        $this->assertSame(1, $appsCalls);
        $tick(5.0);
        $this->assertSame(2, $appsCalls, 'timeout #2 → 5 × 2² = 20 s');
        $tick(5.0);
        $tick(5.0);
        $tick(5.0);
        $this->assertSame(2, $appsCalls);
        $tick(5.0);
        $this->assertSame(3, $appsCalls, 'timeout #3 = APPS_MAX_TIMEOUTS → off for APPS_RETRY_AFTER');
        $this->assertSame(7, $this->calls, 'device query kept its plain 5 s cadence (t = 0…30)');

        $off = (int) (Gpu::APPS_RETRY_AFTER / 5.0) - 1;
        for ($i = 0; $i < $off; $i++) {
            $tick(5.0);
        }
        $this->assertSame(3, $appsCalls, 'disabled for the whole retry window');
        $tick(5.0);
        $this->assertSame(4, $appsCalls, 'one retry after APPS_RETRY_AFTER; still hung → off again');
        $tick(5.0);
        $this->assertSame(4, $appsCalls);

        for ($i = 1; $i < $off; $i++) {
            $tick(5.0);
        }
        $this->assertSame(4, $appsCalls);
        $appsHang = false;
        $this->assertNotNull($tick(5.0), 'recovered');
        $this->assertSame(5, $appsCalls);
        $this->assertNotNull($tick(5.0), 'success resets the apps backoff');
        $this->assertSame(6, $appsCalls);
    }

    public function testComputeAppsNonTimeoutFailureDoesNotBackOff(): void
    {
        $appsCalls = 0;
        $runner = function (array $argv) use (&$appsCalls): array {
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                $appsCalls++;

                return [GpuOutcome::Absent, ''];
            }

            return [GpuOutcome::Ok, self::fixture('skynet2-query-extended.csv')];
        };
        [, $gpu] = Gpu::new($runner, fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true)->sample();
        $this->now += 5.0;
        $gpu->sample();

        $this->assertSame(2, $appsCalls);
    }

    public function testDeviceScheduleSurvivesStateChangesThatOmitIt(): void
    {
        [, $gpu] = $this->gpu()->sample();
        $this->now += 4.9;
        $gpu->withProcesses(false)->sample();
        $this->assertSame(1, $this->calls, 'withProcesses() without an opt-in kept the schedule');
        [, $after] = $gpu->withProcesses()->sample();
        $this->assertSame(2, $this->calls, 'a fresh opt-in queries at once');
        $after->withProcesses()->sample();
        $this->assertSame(2, $this->calls, 'an opt-in that is already on keeps the schedule');
    }

    public function testSampleAsyncRunsTheSameCycleOverTheLoopRunner(): void
    {
        $pending = [];
        $async = function (array $argv) use (&$pending): \React\Promise\PromiseInterface {
            $this->calls++;
            $d = new \React\Promise\Deferred();
            $pending[] = [$d, $argv];

            return $d->promise();
        };
        $blocking = static fn (array $argv): array => throw new \LogicException('the blocking runner must not run');
        $gpu = Gpu::new($blocking, fn (): float => $this->now, candidates: ['/a/nvidia-smi', '/b/nvidia-smi'], queries: [Gpu::QUERY], asyncRunner: $async);

        $promise = $gpu->sampleAsync();
        $this->assertFalse(Settled::peek($promise)[0]);
        $pending[0][0]->resolve([GpuOutcome::Absent, '']);
        $this->assertFalse(Settled::peek($promise)[0], 'the sweep moved on to the next candidate');
        $this->assertSame('/b/nvidia-smi', $pending[1][1][0]);
        $pending[1][0]->resolve([GpuOutcome::Ok, self::CSV]);
        [$snap, $next] = Settled::value($promise);
        $this->assertCount(2, $snap->devices);
        $this->assertSame('/b/nvidia-smi', $next->binary(), 'pinned');

        $this->now += 1.0;
        [$cached] = Settled::value($next->sampleAsync());
        $this->assertSame($snap, $cached, 'between queries: settled at once, no spawn');
        $this->assertSame(2, $this->calls);
    }

    public function testSampleWithAPendingRunnerIsALogicError(): void
    {
        $gpu = Gpu::new(static fn (array $argv): \React\Promise\PromiseInterface => (new \React\Promise\Deferred())->promise(), candidates: ['nvidia-smi']);
        $this->expectException(\LogicException::class);
        $gpu->sample();
    }

    public function testAnInjectedBlockingRunnerAlsoServesSampleAsync(): void
    {
        [$snap] = Settled::value($this->gpu()->sampleAsync());
        $this->assertCount(2, $snap->devices);
        $this->assertSame(1, $this->calls);
    }

    public function testFreshProcessOptInDoesNotBypassADeviceBackoff(): void
    {
        $this->script = [[GpuOutcome::Ok, self::CSV], [GpuOutcome::Timeout, '']];
        [, $gpu] = $this->gpu()->sample();
        $this->now += 5.0;
        [, $gpu] = $gpu->sample();
        $this->assertSame(2, $this->calls);
        $this->now += 1.0;
        $gpu->withProcesses()->sample();
        $this->assertSame(2, $this->calls, 'the opt-in waits for the backoff');
    }

    public function testCandidatesListsEveryPathHitThenFallbacksDeduplicated(): void
    {
        $base = sys_get_temp_dir() . '/candy-top-cand-' . bin2hex(random_bytes(6));
        foreach (['a', 'b', 'c', 'wsl'] as $d) {
            mkdir("$base/$d", 0777, true);
        }
        foreach (['a', 'c', 'wsl'] as $d) {
            file_put_contents("$base/$d/nvidia-smi", "#!/bin/sh\n");
            chmod("$base/$d/nvidia-smi", 0755);
        }
        file_put_contents("$base/b/nvidia-smi", 'not executable');
        try {
            $path = implode(PATH_SEPARATOR, ["$base/a", "$base/b", '', "$base/c"]);
            $this->assertSame(
                ["$base/a/nvidia-smi", "$base/c/nvidia-smi", "$base/wsl/nvidia-smi"],
                Gpu::candidates($path, ["$base/wsl/nvidia-smi", "$base/a/nvidia-smi", "$base/missing/nvidia-smi"]),
            );
            $this->assertSame(['nvidia-smi'], Gpu::candidates('/nonexistent-candy-top', []), 'nothing found → bare name, runner reports Absent');
            $this->assertContains('/usr/lib/wsl/lib/nvidia-smi', Gpu::FALLBACK_BINARIES);
        } finally {
            foreach (['a', 'b', 'c', 'wsl'] as $d) {
                @unlink("$base/$d/nvidia-smi");
                @rmdir("$base/$d");
            }
            @rmdir($base);
        }
    }

    public function testDefaultRunnerAcceptsAbsoluteCandidateOffPath(): void
    {
        $dir = sys_get_temp_dir() . '/candy-top-gpu-abs-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/nvidia-smi', "#!/bin/sh\necho '0, 7, 1, 2, 30, 15.5, Off-Path GPU'\n");
        chmod($dir . '/nvidia-smi', 0755);
        try {
            [$snap, $gpu] = Gpu::new(candidates: ['/nonexistent/nvidia-smi', $dir . '/nvidia-smi'], queries: [Gpu::QUERY])->sample();
        } finally {
            @unlink($dir . '/nvidia-smi');
            @rmdir($dir);
        }

        $this->assertSame('Off-Path GPU', $snap->devices[0]->name);
        $this->assertSame($dir . '/nvidia-smi', $gpu->binary());
    }

    public function testNameControlBytesAreStripped(): void
    {
        $runner = static fn (array $argv): array => [GpuOutcome::Ok, "0, 1, 2, 3, 4, 5, Evil\x1b[31mGPU\n"];
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'], queries: [Gpu::QUERY])->sample();

        $this->assertSame('Evil[31mGPU', $snap->devices[0]->name);
    }

    public function testC1ControlsAreStrippedFromNameAndUuid(): void
    {
        // U+009B (UTF-8 C2 9B) and a raw 0x9B byte are both 8-bit CSI.
        $runner = static fn (array $argv): array => [GpuOutcome::Ok, "0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, P0, 3, 8, 40, GPU-\xc2\x9b2Jab\x9bc, Evil\xc2\x9b31mGPU\x85\n"];
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'])->sample();

        $this->assertSame('Evil31mGPU', $snap->devices[0]->name);
        $this->assertSame('GPU-2Jabc', $snap->devices[0]->uuid);
    }

    public function testPmonAddsPerProcessUtilization(): void
    {
        [$snap] = Gpu::new($this->skynet2(), fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertSame(['nvidia-smi', ...Gpu::PMON_ARGS], $this->argvs[2]);
        $p = $snap->processes[0];
        $this->assertSame([2094147, 0, 92.0, 52.0, 0.0, 0.0], [$p->pid, $p->gpuIndex, $p->utilization, $p->memUtilization, $p->encoderUtilization, $p->decoderUtilization]);
        $this->assertSame(90480 * 1048576, $p->usedMemory, 'compute-apps memory kept');
        $this->assertSame(0.0, $snap->processes[2]->utilization, '"-" = idle, not unmeasured');
        $this->assertCount(6, $snap->processes, 'every pmon row matched a compute-apps row');
        $this->assertSame([2094147 => 92.0, 2094383 => 91.0, 377306 => 0.0, 377813 => 99.0, 377814 => 100.0], $snap->utilizationByPid());
    }

    public function testPmonOnlyGraphicsProcessIsAppendedAndOldHeaderParses(): void
    {
        $pmon = "# gpu        pid  type    sm   mem   enc   dec    fb   command\n"
            . "# Idx          #   C/G     %     %     %     %    MB   name\n"
            . "    0       4242     G     7     3     -     -    64   Xorg\n"
            . "    0         11     C    50    20     1     2    12   cuda app\n"
            . "    9       4243     G     1     1     -     -     -   ghost\n";
        $runner = static fn (array $argv): array => match (true) {
            str_starts_with($argv[1], '--query-compute-apps=') => [GpuOutcome::Ok, "11, GPU-a, 12\n"],
            $argv[1] === 'pmon' => [GpuOutcome::Ok, $pmon],
            default => [GpuOutcome::Ok, "0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, P0, 3, 8, N/A, GPU-a, Fake\n"],
        };
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertCount(3, $snap->processes);
        $this->assertSame([11, 0, 12 * 1048576, 50.0, 20.0, 1.0, 2.0], [
            $snap->processes[0]->pid, $snap->processes[0]->gpuIndex, $snap->processes[0]->usedMemory,
            $snap->processes[0]->utilization, $snap->processes[0]->memUtilization,
            $snap->processes[0]->encoderUtilization, $snap->processes[0]->decoderUtilization,
        ]);
        $xorg = $snap->processes[1];
        $this->assertSame([4242, 0, 'GPU-a', 64 * 1048576, 7.0], [$xorg->pid, $xorg->gpuIndex, $xorg->gpuUuid, $xorg->usedMemory, $xorg->utilization]);
        $ghost = $snap->processes[2];
        $this->assertSame([-1, Sentinel::UNAVAILABLE, Sentinel::UNMEASURED_INT], [$ghost->gpuIndex, $ghost->gpuUuid, $ghost->usedMemory]);
    }

    public function testPmonTimeoutsBackOffPmonAloneNeverTheMemoryQuery(): void
    {
        $pmonCalls = 0;
        $appsCalls = 0;
        $runner = function (array $argv) use (&$pmonCalls, &$appsCalls): array {
            if ($argv[1] === 'pmon') {
                $pmonCalls++;

                return [GpuOutcome::Timeout, ''];
            }
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                $appsCalls++;
            }

            return $this->skynet2()($argv);
        };
        $gpu = Gpu::new($runner, fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true);
        [$snap, $gpu] = $gpu->sample();
        $this->assertCount(6, $snap->processes, 'compute-apps rows survive a pmon hang');
        $this->assertSame(Sentinel::UNMEASURED, $snap->processes[0]->utilization);

        for ($i = 0; $i < 40; $i++) {
            $this->now += 5.0;
            [$snap, $gpu] = $gpu->sample();
            $this->assertCount(6, $snap->processes ?? [], 'memory measured every interval');
        }
        $this->assertSame(41, $appsCalls, 'compute-apps never backed off by pmon');
        // pmon: t=0 (→10 s), t=10 (→20 s), t=30 (3rd → off 600 s): three hangs in 200 s.
        $this->assertSame(3, $pmonCalls);
        $this->assertTrue($gpu->pmonSupported(), 'a hang is transient, not "unsupported"');
    }

    public function testPmonNonTimeoutFailuresMemoizeUnsupported(): void
    {
        $pmonCalls = 0;
        $runner = function (array $argv) use (&$pmonCalls): array {
            if ($argv[1] === 'pmon') {
                $pmonCalls++;

                return $pmonCalls === 2 ? [GpuOutcome::Ok, "not pmon output\n"] : [GpuOutcome::Absent, ''];
            }

            return $this->skynet2()($argv);
        };
        $gpu = Gpu::new($runner, fn (): float => $this->now, candidates: ['nvidia-smi'], processes: true);
        for ($i = 0; $i < 6; $i++) {
            [$snap, $gpu] = $gpu->sample();
            $this->now += 5.0;
            $this->assertCount(6, $snap->processes ?? []);
        }

        $this->assertSame(Gpu::PMON_MAX_FAILURES, $pmonCalls, 'exit != 0 and header-less output both count; then no respawn');
        $this->assertFalse($gpu->pmonSupported());
        $this->assertTrue($gpu->withProcesses(false)->withProcesses()->pmonSupported(), 'a fresh opt-in retries');
    }

    public function testComputeAppsFailureStillRunsPmon(): void
    {
        $runner = function (array $argv): array {
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                return [GpuOutcome::Absent, ''];
            }

            return $this->skynet2()($argv);
        };
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertCount(6, $snap->processes ?? [], 'rows from pmon alone');
        $this->assertSame(90480 * 1048576, $snap->processes[0]->usedMemory, 'pmon fb column');
        $this->assertSame(92.0, $snap->processes[0]->utilization);
        $this->assertSame('GPU-e16d248c-c1a9-3302-01d6-82c18e02c9ce', $snap->processes[0]->gpuUuid);
    }

    public function testComputeAppsTimeoutSkipsPmonThatCycle(): void
    {
        $runner = function (array $argv): array {
            if (str_starts_with($argv[1], '--query-compute-apps=')) {
                return [GpuOutcome::Timeout, ''];
            }

            return $this->skynet2()($argv);
        };
        [$snap] = Gpu::new($runner, candidates: ['nvidia-smi'], processes: true)->sample();

        $this->assertNull($snap->processes);
        $this->assertSame(['--query-gpu=' . implode(',', Gpu::QUERY_EXTENDED)], array_column($this->argvs, 1), 'one bounded wait per interval');
    }
}
