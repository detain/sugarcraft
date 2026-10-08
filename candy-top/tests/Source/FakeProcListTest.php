<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Source\Fake\FakeProcList;

final class FakeProcListTest extends TestCase
{
    /** @return array<int, Process> */
    private static function byPid(ProcSnapshot $snap): array
    {
        $out = [];
        foreach ($snap->processes as $p) {
            $out[$p->pid] = $p;
        }

        return $out;
    }

    public function testNewKeepsThePlaceholderCast(): void
    {
        [$snap] = FakeProcList::new(4)->sample();
        $this->assertInstanceOf(ProcSnapshot::class, $snap);
        $this->assertSame([1, 412, 880, 1203, 2210, 2290, 3105, 4410], array_map(static fn (Process $p): int => $p->pid, $snap->processes));
        $this->assertNull($snap->detail);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->memTotal, 'frozen P-A output: no new fields');
    }

    public function testDemoAddsContainersAVmAKernelThreadAndIsDeterministic(): void
    {
        [$a, $next] = FakeProcList::demo(8)->sample();
        [$b] = FakeProcList::demo(8)->sample();
        $this->assertEquals($a, $b);
        $this->assertNotEquals($a, $next->sample()[0], 'it moves');
        $procs = self::byPid($a);
        $this->assertSame(FakeProcList::MEM_TOTAL, $a->memTotal);
        $this->assertSame('docker', $procs[5133]->container?->engine);
        $this->assertTrue($procs[6969]->container?->isVm());
        $this->assertSame('web01', $procs[6969]->container?->vm?->guestName);
        $this->assertSame('vim notes.md', $procs[7001]->cmdBasename());
        $this->assertSame('init splash', $procs[1]->cmdBasename());
        $this->assertArrayHasKey(2, $procs);
        $this->assertCount(113, FakeProcList::demo(8, 100)->sample()[0]->processes);
    }

    public function testDemoHonoursTheCollectorOptIns(): void
    {
        $plain = self::byPid(FakeProcList::demo(4)->sample()[0]);
        $this->assertSame(Sentinel::UNMEASURED, $plain[2210]->ioRead, 'io off by default');
        $tuned = FakeProcList::demo(4)->withPerCore(true)->withIo(true)->withFilterKernel(true)->withDetail(2210)->sample()[0];
        $procs = self::byPid($tuned);
        $this->assertArrayNotHasKey(2, $procs);
        $this->assertGreaterThanOrEqual(0.0, $procs[2210]->ioRead);
        $this->assertSame(Sentinel::UNMEASURED, $procs[412]->ioRead, 'another uid: EACCES');
        $this->assertEqualsWithDelta($plain[1]->cpu * 4, $procs[1]->cpu, 0.1);
        $this->assertSame(2210, $tuned->detail?->pid);
        $this->assertSame('/home/joe', $tuned->detail?->cwd);
        $this->assertNull(FakeProcList::demo()->withDetail(6969)->sample()[0]->detail?->cwd, 'an unreadable cwd');
    }
}
