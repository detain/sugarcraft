<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class ProcListTest extends TestCase
{
    private FixtureTree $tree;

    /** @var array<int, int> uid → lookup count */
    private array $lookups = [];

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    private function procs(bool $perCore = false, bool $filterKernel = false): ProcList
    {
        return ProcList::new($this->tree->paths(), $perCore, $filterKernel, 4096, 100, function (int $uid): ?string {
            $this->lookups[$uid] = ($this->lookups[$uid] ?? 0) + 1;

            return $uid === 0 ? 'root' : null;
        });
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

    private function advance(int $aggregate, int $pid42CpuT): void
    {
        $this->tree->write('proc/stat', "cpu  {$aggregate} 0 0 0 0 0 0 0 0 0\ncpu0 1 0 0 0\ncpu1 1 0 0 0\n");
        $utime = $pid42CpuT - 100;
        $this->tree->write('proc/42/stat', "42 (my (weird) app) R 1 42 42 0 -1 4194304 500 0 0 0 {$utime} 100 0 0 20 5 4 0 1000 50000000 2500 0\n");
    }

    public function testFirstScanParsesFieldsAndHasNoInterval(): void
    {
        [$snap] = $this->procs()->sample();

        $this->assertSame([1, 2, 3, 42], array_map(static fn (Process $p): int => $p->pid, $snap->processes));
        $this->assertSame(2, $snap->coreCount);

        $app = self::byPid($snap, 42);
        $this->assertNotNull($app);
        $this->assertSame('my (weird) app', $app->name, 'comm split on the last paren');
        $this->assertSame('/usr/bin/app --flag', $app->cmd);
        $this->assertSame('1000', $app->user, 'no passwd entry → numeric uid');
        $this->assertSame(1000, $app->uid);
        $this->assertSame('R', $app->state);
        $this->assertSame(1, $app->ppid);
        $this->assertSame(4, $app->threads);
        $this->assertSame(5, $app->nice);
        $this->assertSame(2500 * 4096, $app->mem);
        $this->assertSame(Sentinel::UNMEASURED, $app->cpu);
        // 400 jiffies over (1000 s × 100 Hz − start 1000) lifetime.
        $this->assertEqualsWithDelta(100.0 * 400 / 99000, $app->cpuCumulative, 1e-9);

        $this->assertSame('root', self::byPid($snap, 1)?->user);
        $this->assertSame('', self::byPid($snap, 2)?->cmd, 'kernel threads have no cmdline');
    }

    public function testPageSizeDetectedFromSmapsElseDefault(): void
    {
        $this->tree->write('proc/self/smaps', "55d0-55d1 r--p 0 08:01 1 /usr/bin/php\nSize: 64 kB\nKernelPageSize: 16 kB\n");
        [$snap] = ProcList::new($this->tree->paths(), userLookup: static fn (): ?string => null)->sample();
        $this->assertSame(2500 * 16384, self::byPid($snap, 42)?->mem);

        $this->tree->remove('proc/self');
        [$snap] = ProcList::new($this->tree->paths(), userLookup: static fn (): ?string => null)->sample();
        $this->assertSame(2500 * 4096, self::byPid($snap, 42)?->mem);
    }

    public function testCpuPercentIsShareOfWholeMachineDelta(): void
    {
        [, $procs] = $this->procs()->sample();
        $this->advance(11000, 650);
        [$snap] = $procs->sample();

        $this->assertSame(25.0, self::byPid($snap, 42)?->cpu);
        $this->assertSame(0.0, self::byPid($snap, 1)?->cpu);
    }

    public function testPerCoreMultipliesByCoreCount(): void
    {
        [, $procs] = $this->procs(perCore: true)->sample();
        $this->advance(11000, 650);
        [$snap] = $procs->sample();

        $this->assertSame(50.0, self::byPid($snap, 42)?->cpu);
    }

    public function testKernelFilterDropsKthreaddAndChildren(): void
    {
        [$snap] = $this->procs(filterKernel: true)->sample();

        $this->assertSame([1, 42], array_map(static fn (Process $p): int => $p->pid, $snap->processes));
    }

    public function testUserLookupIsCachedPerUid(): void
    {
        [, $procs] = $this->procs()->sample();
        $procs->sample();

        $this->assertSame([0 => 1, 1000 => 1], $this->lookups);
    }

    public function testStaticInfoIsReadOncePerProcessLifetime(): void
    {
        [, $procs] = $this->procs()->sample();
        // cmdline/status gone, but the cached row survives because only stat is re-read.
        $this->tree->remove('proc/42/cmdline');
        $this->tree->remove('proc/42/status');
        [$snap] = $procs->sample();

        $this->assertSame('/usr/bin/app --flag', self::byPid($snap, 42)?->cmd);
    }

    public function testRecycledPidIsANewProcess(): void
    {
        [, $procs] = $this->procs()->sample();
        $this->tree->write('proc/stat', "cpu  11000 0 0 0 0 0 0 0 0 0\n");
        $this->tree->write('proc/42/stat', "42 (other) S 1 42 42 0 -1 0 0 0 0 0 900 100 0 0 20 0 1 0 77777 0 10 0\n");
        $this->tree->write('proc/42/cmdline', "/bin/other\0");
        [$snap] = $procs->sample();

        $p = self::byPid($snap, 42);
        $this->assertSame('/bin/other', $p?->cmd);
        $this->assertSame(0.0, $p?->cpu, 'new process: no inherited cpu-time baseline');
    }

    public function testPidVanishingMidScanIsSkippedSilently(): void
    {
        // Listed by scandir but its files are gone — the ENOENT race.
        $this->tree->remove('proc/42/stat');
        $this->tree->write('proc/3/stat', "3 (trunc");
        $this->tree->remove('proc/1/status');
        [$snap] = $this->procs()->sample();

        $this->assertSame([2], array_map(static fn (Process $p): int => $p->pid, $snap->processes));
    }

    public function testMissingProcStatStillListsWithUnmeasuredCpu(): void
    {
        $this->tree->remove('proc/stat');
        $this->tree->remove('proc/uptime');
        [, $procs] = $this->procs()->sample();
        [$snap] = $procs->sample();

        $this->assertSame(4, $snap->count());
        $this->assertSame(0, $snap->coreCount);
        $this->assertSame(Sentinel::UNMEASURED, self::byPid($snap, 42)?->cpu);
        $this->assertSame(Sentinel::UNMEASURED, self::byPid($snap, 42)?->cpuCumulative);
    }

    public function testEmptyTreeYieldsNoProcesses(): void
    {
        $tree = FixtureTree::empty();
        try {
            [$snap] = ProcList::new($tree->paths())->sample();
            $this->assertSame(0, $snap->count());
        } finally {
            $tree->destroy();
        }
    }

    /**
     * Plan §7 R4 budget is <50 ms for 500 pids; the assertion bound is 20×
     * that so a loaded CI box or coverage run cannot flake it, while an
     * accidental O(n²) or per-pid re-read still fails loudly.
     */
    public function testFiveHundredPidsScanWithinGenerousBound(): void
    {
        $tree = FixtureTree::empty();
        try {
            $tree->write('proc/stat', "cpu  1000 0 0 0 0 0 0 0 0 0\ncpu0 1 0 0 0\n");
            $tree->write('proc/uptime', "500.0 900.0\n");
            for ($pid = 100; $pid < 600; $pid++) {
                $tree->write("proc/{$pid}/stat", "{$pid} (worker {$pid}) S 1 1 1 0 -1 0 0 0 0 0 10 5 0 0 20 0 1 0 50 1000 100 0\n");
                $tree->write("proc/{$pid}/status", "Name:\tworker\nUid:\t" . ($pid % 7) . "\t0\t0\t0\n");
                $tree->write("proc/{$pid}/cmdline", "/usr/bin/worker\0--id\0{$pid}\0");
            }
            $procs = ProcList::new($tree->paths(), userLookup: static fn (int $uid): ?string => 'u' . $uid);

            $start = hrtime(true);
            [$first, $procs] = $procs->sample();
            [$second] = $procs->sample();
            $elapsedMs = (hrtime(true) - $start) / 1e6;

            $this->assertSame(500, $first->count());
            $this->assertSame(500, $second->count());
            $this->assertSame('u2', $second->processes[0]->user, 'pid 100 → uid 100 % 7');
            $this->assertLessThan(1000.0, $elapsedMs, sprintf('two 500-pid scans took %.1f ms', $elapsedMs));
        } finally {
            $tree->destroy();
        }
    }
}
