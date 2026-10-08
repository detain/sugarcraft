<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu;
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
        }, fn (): float => $this->now, $interval);
    }

    public function testParsesCsvWithNameLastAndCommasInName(): void
    {
        $seen = null;
        $gpu = Gpu::new(static function (array $argv) use (&$seen): array {
            $seen = $argv;

            return [GpuOutcome::Ok, self::CSV];
        });
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
            [$snap, $gpu] = Gpu::new()->sample();

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
}
