<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Source\Fake\FakeGpuProcesses;
use SugarCraft\Top\Source\Fake\FakeProcList;

/**
 * The `--fake` GPU feed for the proc box's #1552 columns: deterministic,
 * on demo pids, with a multi-GPU process and a utilization-less one.
 */
final class FakeGpuProcessesTest extends TestCase
{
    public function testDeterministicAndStepping(): void
    {
        [$a, $next] = FakeGpuProcesses::demo()->sample();
        [$b] = FakeGpuProcesses::demo()->sample();
        $this->assertEquals($a, $b, 'the same step is the same snapshot');
        [$c] = $next->sample();
        $this->assertNotEquals($a, $c, 'values wander between steps');
    }

    public function testRowsSitOnDemoPids(): void
    {
        [$snap] = FakeGpuProcesses::demo()->sample();
        $this->assertInstanceOf(GpuSnapshot::class, $snap);
        $this->assertCount(2, $snap->devices);
        [$procs] = FakeProcList::demo(8)->sample();
        $pids = array_map(static fn ($p): int => $p->pid, $procs->processes);
        foreach ($snap->processes ?? [] as $p) {
            $this->assertContains($p->pid, $pids);
        }
        $mem = $snap->memoryByPid();
        $util = $snap->utilizationByPid();
        $this->assertSame(4 * 1024 ** 3, $mem[6969], 'qemu on both GPUs');
        $this->assertLessThan(0.0, $util[880], 'postgres: compute-apps without pmon');
        $this->assertGreaterThanOrEqual(20.0, $util[5133]);
    }
}
