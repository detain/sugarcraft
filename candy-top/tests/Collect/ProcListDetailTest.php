<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ProcDetail;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

/**
 * The P-E opt-ins on ProcList: per-core and kernel-filter toggles at
 * runtime, MemTotal for Mem%, and the detailed-view extras read for one
 * pid only (#1546 cwd, elapsed, io totals).
 */
final class ProcListDetailTest extends TestCase
{
    private FixtureTree $tree;

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    private function procs(): ProcList
    {
        return ProcList::new($this->tree->paths(), false, false, 4096, 100, static fn (int $uid): ?string => null);
    }

    private static function byPid(ProcSnapshot $snap, int $pid): ?Process
    {
        foreach ($snap->processes as $p) {
            if ($p->pid === $pid) {
                return $p;
            }
        }

        return null;
    }

    private function advance(int $aggregate, int $cpuT): void
    {
        $this->tree->write('proc/stat', "cpu  {$aggregate} 0 0 0 0 0 0 0 0 0\ncpu0 1 0 0 0\ncpu1 1 0 0 0\n");
        $utime = $cpuT - 100;
        $this->tree->write('proc/42/stat', "42 (my (weird) app) R 1 42 42 0 -1 4194304 500 0 0 0 {$utime} 100 0 0 20 5 4 0 1000 50000000 2500 0\n");
    }

    public function testMemTotalComesFromMeminfo(): void
    {
        [$snap] = $this->procs()->sample();
        $meminfo = (string) file_get_contents($this->tree->root . '/proc/meminfo');
        preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $m);
        $this->assertSame((int) $m[1] * 1024, $snap->memTotal);

        $this->tree->remove('proc/meminfo');
        [$snap] = $this->procs()->sample();
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->memTotal);
    }

    public function testWithPerCoreTakesEffectOnTheNextScanWithoutAGap(): void
    {
        $this->advance(10000, 400);
        [, $list] = $this->procs()->sample();
        $this->advance(10100, 450);
        [$whole, $list] = $list->sample();
        $this->advance(10200, 500);
        [$core] = $list->withPerCore(true)->sample();
        $this->assertSame(50.0, self::byPid($whole, 42)?->cpu);
        $this->assertSame(100.0, self::byPid($core, 42)?->cpu, 'x2 cores, no unmeasured scan in between');
        $this->assertSame($list, $list->withPerCore(false), 'unchanged → same instance');
    }

    public function testWithFilterKernelDropsKthreadd(): void
    {
        $list = $this->procs();
        $this->assertNotNull(self::byPid($list->sample()[0], 2));
        $this->assertNull(self::byPid($list->withFilterKernel(true)->sample()[0], 2));
        $this->assertSame($list, $list->withFilterKernel(false));
    }

    public function testDetailReadsCwdElapsedAndIoForThatPidOnly(): void
    {
        symlink('/nonexistent/candy-top/app (deleted)', $this->tree->root . '/proc/42/cwd');
        $this->tree->write('proc/42/io', "rchar: 1\nwchar: 2\nread_bytes: 8192\nwrite_bytes: 4096\n");
        [$snap] = $this->procs()->withDetail(42)->sample();
        $d = $snap->detail;
        $this->assertInstanceOf(ProcDetail::class, $d);
        $this->assertSame(42, $d->pid);
        $this->assertSame('/nonexistent/candy-top/app (deleted)', $d->cwd, 'the " (deleted)" suffix is kept');
        $this->assertSame(8192, $d->ioReadTotal);
        $this->assertSame(4096, $d->ioWriteTotal);
        // uptime 1000 s, starttime 1000 ticks at 100 Hz → started at 10 s.
        $this->assertEqualsWithDelta(990.0, $d->elapsed, 1e-9);

        $this->assertNull($this->procs()->sample()[0]->detail, 'not asked → not read');
        $this->assertNull($this->procs()->withDetail(31337)->sample()[0]->detail, 'gone → null');
    }

    public function testDetailUnreadableCwdAndIoAreSentinels(): void
    {
        $this->tree->remove('proc/42/io');
        [$snap] = $this->procs()->withDetail(42)->sample();
        $this->assertNotNull($snap->detail);
        $this->assertNull($snap->detail->cwd, 'EACCES / zombie: "(unavailable)"');
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->detail->ioReadTotal);
        $list = $this->procs()->withDetail(42);
        $this->assertSame($list, $list->withDetail(42));
        $this->assertNotSame($list, $list->withDetail(null));
    }

    public function testDetailReusesTheIoCountersTheScanAlreadyRead(): void
    {
        $this->tree->write('proc/42/io', "read_bytes: 10\nwrite_bytes: 20\n");
        [$snap] = $this->procs()->withIo(true)->withDetail(42)->sample();
        $this->assertSame([10, 20], [$snap->detail?->ioReadTotal, $snap->detail?->ioWriteTotal]);
        $this->assertSame(10, self::byPid($snap, 42)?->ioReadTotal);
    }
}
