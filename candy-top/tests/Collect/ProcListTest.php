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

    // ---- btop #1856: stat parsing survives a comm with ')' / newline and a rename ----

    public function testCommContainingCloseParenAndFieldsSplitsAtLastParen(): void
    {
        // comm "x) S 999 (y" — a first-')' split would read state S, ppid 999.
        $this->tree->write('proc/77/stat', "77 (x) S 999 (y) R 1 77 77 0 -1 0 0 0 0 0 30 20 0 0 20 0 3 0 4000 0 123 0\n");
        $this->tree->write('proc/77/status', "Name:\tx\nUid:\t0\t0\t0\t0\n");
        $this->tree->write('proc/77/cmdline', "/bin/x\0");
        [$snap] = $this->procs()->sample();

        $p = self::byPid($snap, 77);
        $this->assertSame('x) S 999 (y', $p?->name);
        $this->assertSame('R', $p?->state);
        $this->assertSame(1, $p?->ppid);
        $this->assertSame(3, $p?->threads);
        $this->assertSame(123 * 4096, $p?->mem);
    }

    public function testCommContainingNewlineParses(): void
    {
        $this->tree->write('proc/78/stat', "78 (two\nlines) S 1 78 78 0 -1 0 0 0 0 0 30 20 0 0 20 0 2 0 4000 0 50 0\n");
        $this->tree->write('proc/78/status', "Name:\ttwo\\nlines\nUid:\t0\t0\t0\t0\n");
        $this->tree->write('proc/78/cmdline', "/bin/two\0");
        [$snap] = $this->procs()->sample();

        $p = self::byPid($snap, 78);
        $this->assertSame("two\nlines", $p?->name);
        $this->assertSame(2, $p?->threads);
        $this->assertSame(50 * 4096, $p?->mem);
    }

    /**
     * prctl(PR_SET_NAME) between scans changes the space count in comm;
     * btop's cached name offset then read starttime as RSS ("11 MiB → 36G").
     */
    public function testRenameBetweenScansKeepsFieldsAligned(): void
    {
        $this->tree->write('proc/79/stat', "79 (worker) S 1 79 79 0 -1 0 0 0 0 0 30 20 0 0 20 0 20 0 9000000 0 2816 0\n");
        $this->tree->write('proc/79/status', "Name:\tworker\nUid:\t0\t0\t0\t0\n");
        $this->tree->write('proc/79/cmdline', "/usr/bin/worker\0--pool\0");
        [, $procs] = $this->procs()->sample();

        $this->tree->write('proc/stat', "cpu  11000 0 0 0 0 0 0 0 0 0\ncpu0 1 0 0 0\ncpu1 1 0 0 0\n");
        $this->tree->write('proc/79/stat', "79 (my renamed w k) S 1 79 79 0 -1 0 0 0 0 0 130 20 0 0 20 0 20 0 9000000 0 2816 0\n");
        [$snap] = $procs->sample();

        $p = self::byPid($snap, 79);
        $this->assertSame('my renamed w k', $p?->name, 'name re-read from stat every scan');
        $this->assertSame(2816 * 4096, $p?->mem, 'RSS, not starttime');
        $this->assertSame(20, $p?->threads);
        $this->assertSame('/usr/bin/worker --pool', $p?->cmd, 'same starttime → same process, cache kept');
        $this->assertSame(10.0, $p?->cpu, '100 jiffies of 1000 → baseline survived the rename');
    }

    // ---- btop #1859: argv[0] basename offset ----

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function basenameOffsets(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'bare' => ['firefox', 0];
        yield 'root' => ['/', 0];
        yield 'trailing slash' => ['/usr/bin/', 0];
        yield 'dot' => ['./firefox', 2];
        yield 'dotdot' => ['../bin/firefox', 7];
        yield 'absolute' => ['/usr/bin/firefox', 9];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('basenameOffsets')]
    public function testBasenameOffsetMirrorsBtop(string $argv0, int $offset): void
    {
        $this->assertSame($offset, ProcList::basenameOffset($argv0));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function basenameCommands(): iterable
    {
        yield 'nix store' => ['/nix/store/hash-firefox/bin/firefox', 'firefox'];
        yield 'spaces in path' => ['/path with spaces/my program', 'my program'];
        yield 'unicode' => ['/路徑/程式', '程式'];
        yield 'bare' => ['firefox', 'firefox'];
        yield 'trailing slash' => ['/usr/bin/', '/usr/bin/'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('basenameCommands')]
    public function testCmdBasenamePreservesArgumentsWithSlashes(string $executable, string $basename): void
    {
        $this->tree->write('proc/42/cmdline', "{$executable}\0--profile\0/home/user/profile\0--url\0https://example.org/a/b\0");
        [$snap] = $this->procs()->sample();

        $p = self::byPid($snap, 42);
        $this->assertSame("{$executable} --profile /home/user/profile --url https://example.org/a/b", $p?->cmd, 'cmd stays full');
        $this->assertSame("{$basename} --profile /home/user/profile --url https://example.org/a/b", $p?->cmdBasename());
    }

    public function testFixtureAndKernelThreadOffsets(): void
    {
        [$snap] = $this->procs()->sample();

        $this->assertSame(9, self::byPid($snap, 42)?->cmdBasenameOffset);
        $this->assertSame('app --flag', self::byPid($snap, 42)?->cmdBasename());
        $this->assertSame('init splash', self::byPid($snap, 1)?->cmdBasename());
        $this->assertSame(0, self::byPid($snap, 2)?->cmdBasenameOffset);
        $this->assertSame('', self::byPid($snap, 2)?->cmdBasename());
    }

    public function testRewrittenArgvTitleIsNotCutAtItsLastSlash(): void
    {
        $this->tree->write('proc/42/cmdline', "sshd: joe@pts/0\0\0\0");
        $this->tree->write('proc/1/cmdline', "/opt/google/chrome/chrome --type=renderer --user-data-dir=/home/x/.cfg");
        [$snap] = $this->procs()->sample();

        $this->assertSame('sshd: joe@pts/0', self::byPid($snap, 42)?->cmdBasename());
        $this->assertSame('chrome --type=renderer --user-data-dir=/home/x/.cfg', self::byPid($snap, 1)?->cmdBasename());
    }

    public function testArgumentlessAbsolutePathWithSpaceKeepsLastSlashRule(): void
    {
        $this->tree->write('proc/42/cmdline', "/opt/My App/app\0");
        $this->tree->write('proc/1/cmdline', "nginx: master process /usr/sbin/nginx -c /etc/nginx/nginx.conf");
        [$snap] = $this->procs()->sample();

        $this->assertSame('app', self::byPid($snap, 42)?->cmdBasename(), 'an absolute single segment is a path');
        $this->assertSame('nginx: master process /usr/sbin/nginx -c /etc/nginx/nginx.conf', self::byPid($snap, 1)?->cmdBasename(), 'relative title: first token only');
    }

    public function testAbsoluteSingleSegmentWithColonTitleIsATitle(): void
    {
        $this->tree->write('proc/42/cmdline', "/usr/sbin/sshd: joe@pts/0");
        $this->tree->write('proc/1/cmdline', "/opt/My App/app --flag=/x/y");
        [$snap] = $this->procs()->sample();

        $this->assertSame('sshd: joe@pts/0', self::byPid($snap, 42)?->cmdBasename(), 'first token ends with ":" → title');
        $this->assertSame('app --flag=/x/y', self::byPid($snap, 1)?->cmdBasename(), 'path with a space, cut before its first option');
    }

    // ---- btop #1823: /proc/[pid]/io rates ----

    public function testIoIsOffByDefaultAndAllSentinel(): void
    {
        [, $procs] = $this->procs()->sample();
        [$snap] = $procs->sample();

        $p = self::byPid($snap, 42);
        $this->assertSame(Sentinel::UNMEASURED, $p?->ioRead);
        $this->assertSame(Sentinel::UNMEASURED, $p?->ioWrite);
        $this->assertSame(Sentinel::UNMEASURED_INT, $p?->ioReadTotal);
        $this->assertSame(Sentinel::UNMEASURED, $p?->ioTotal());
    }

    public function testIoRatesOverUptimeDelta(): void
    {
        $procs = $this->procs()->withIo(true);
        $this->assertSame($procs, $procs->withIo(true));
        [$first, $procs] = $procs->sample();

        $p = self::byPid($first, 42);
        $this->assertSame(1048576, $p?->ioReadTotal);
        $this->assertSame(524288, $p?->ioWriteTotal, 'cancelled_write_bytes not subtracted (btop)');
        $this->assertSame(Sentinel::UNMEASURED, $p?->ioRead, 'no baseline yet — "-" not 0');

        $this->tree->write('proc/uptime', "1002.00 1900.00\n");
        $this->tree->write('proc/42/io', "rchar: 1\nwchar: 1\nread_bytes: 3145728\nwrite_bytes: 524288\ncancelled_write_bytes: 0\n");
        [$snap] = $procs->sample();

        $p = self::byPid($snap, 42);
        $this->assertSame(1048576.0, $p?->ioRead, '2 MiB over 2 s');
        $this->assertSame(0.0, $p?->ioWrite, 'measured idle IS 0');
        $this->assertSame(1048576.0, $p?->ioTotal());
        $this->assertSame(0.0, self::byPid($snap, 1)?->ioRead);
    }

    public function testUnreadableIoIsSentinelNeverZero(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root reads any mode');
        }
        $procs = $this->procs();
        chmod($this->tree->root . '/proc/1/io', 0000);
        try {
            [, $procs] = $procs->withIo(true)->sample();
            $this->tree->write('proc/uptime', "1002.00 1900.00\n");
            [$snap] = $procs->sample();
        } finally {
            chmod($this->tree->root . '/proc/1/io', 0644);
        }

        $p = self::byPid($snap, 1);
        $this->assertNotNull($p, 'EACCES on io never drops the row');
        $this->assertSame(Sentinel::UNMEASURED, $p->ioRead);
        $this->assertSame(Sentinel::UNMEASURED, $p->ioWrite);
        $this->assertSame(Sentinel::UNMEASURED_INT, $p->ioReadTotal);
        $this->assertSame(0.0, self::byPid($snap, 42)?->ioRead);
    }

    public function testIoBaselineDoesNotSurviveTogglingOffOrPidRecycle(): void
    {
        [, $procs] = $this->procs()->withIo(true)->sample();
        [, $procs] = $procs->withIo(false)->sample();
        $this->tree->write('proc/uptime', "1002.00 1900.00\n");
        [$snap, $procs] = $procs->withIo(true)->sample();
        $this->assertSame(Sentinel::UNMEASURED, self::byPid($snap, 42)?->ioRead, 'a stale baseline would fabricate a rate');

        $this->tree->write('proc/uptime', "1004.00 1900.00\n");
        $this->tree->write('proc/42/stat', "42 (other) S 1 42 42 0 -1 0 0 0 0 0 900 100 0 0 20 0 1 0 77777 0 10 0\n");
        [$snap] = $procs->sample();
        $this->assertSame(Sentinel::UNMEASURED, self::byPid($snap, 42)?->ioRead, 'recycled pid: no inherited counters');
    }

    // ---- btop #1873: container tag ----

    public function testContainerTagFromCgroupCachedPerLifetime(): void
    {
        [$snap, $procs] = $this->procs()->sample();

        $c = self::byPid($snap, 42)?->container;
        $this->assertSame('docker', $c?->engine);
        $this->assertSame('3f2a9c1b04de', $c?->name);
        $this->assertNull(self::byPid($snap, 1)?->container, '/init.scope is the host');
        $this->assertNull(self::byPid($snap, 2)?->container, 'no cgroup file → host');

        $this->tree->write('proc/42/cgroup', "0::/user.slice\n");
        [$snap] = $procs->sample();
        $this->assertSame('docker', self::byPid($snap, 42)?->container?->engine, 'read once per lifetime (btop)');
    }

    public function testLiveIoCostSmoke(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/self/io')) {
            $this->markTestSkipped('needs a Linux /proc with io accounting');
        }
        [, $procs] = ProcList::new(readIo: true)->sample();
        [$snap] = $procs->sample();

        $self = null;
        foreach ($snap->processes as $p) {
            $this->assertTrue($p->ioRead === Sentinel::UNMEASURED || $p->ioRead >= 0.0);
            if ($p->pid === getmypid()) {
                $self = $p;
            }
        }
        $this->assertNotNull($self);
        $this->assertGreaterThanOrEqual(0, $self->ioReadTotal, 'own io is always readable');
    }
}
