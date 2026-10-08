<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\ProcList;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class ProcListTest extends TestCase
{
    private static function users(): \Closure
    {
        return static fn (int $uid): ?string => [0 => 'root', 1004 => 'detain', 80 => 'www'][$uid] ?? null;
    }

    /** @return array<int, Process> */
    private static function byPid(array $processes): array
    {
        $out = [];
        foreach ($processes as $p) {
            $out[$p->pid] = $p;
        }

        return $out;
    }

    public function testLimitedVisibilityReferenceListsOnlyOwnProcesses(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['ps' => FixtureProbe::fixture('ps-limited.txt')]);
        [$snap] = ProcList::new($probe, userLookup: self::users())->sample();

        $this->assertSame(4, $snap->count(), 'see_other_uids=0: a shorter table, not a failure');
        $this->assertSame(4, $snap->coreCount);
        $this->assertSame(1005423 * 4096, $snap->memTotal, '#1851 total, shared with the mem box');
        $bash = self::byPid($snap->processes)[34179];
        $this->assertSame('bash', $bash->name);
        $this->assertSame('-bash', $bash->cmd, "ps's ' (comm)' annotation is stripped");
        $this->assertSame('sshd-session: detain@pts/82', self::byPid($snap->processes)[34178]->cmd);
        $this->assertSame('detain', $bash->user);
        $this->assertSame(1004, $bash->uid);
        $this->assertSame('S', $bash->state);
        $this->assertSame(4648 * 1024, $bash->mem);
        $this->assertSame(34178, $bash->ppid);
        $this->assertEqualsWithDelta(100.0 * 0.15 / 3599, $bash->cpuCumulative, 1e-9);
        $this->assertSame(Sentinel::UNMEASURED, $bash->ioRead, 'FreeBSD io is not collected: "-", never 0');
        $this->assertNull($bash->container);
        $ps = self::byPid($snap->processes)[35806];
        $this->assertSame(0.5, $ps->cpu, '%cpu 2.0 of one core = 0.5 of four');
        $this->assertSame('R', $ps->state);

        $argv = $probe->runs[0];
        $this->assertSame(['ps', '-axww'], array_slice($argv, 0, 2));
        $this->assertContains('etimes=', $argv);
    }

    public function testRootViewKernelFilterStatesAndUnits(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['ps' => FixtureProbe::fixture('ps-root.synthetic.txt')]);
        $list = ProcList::new($probe, userLookup: self::users());
        [$snap] = $list->sample();
        $p = self::byPid($snap->processes);

        $this->assertArrayNotHasKey(0, $p, 'pid 0 (the kernel) is skipped, as btop');
        $this->assertArrayNotHasKey(10, $p, 'btop drops the idle process');
        $this->assertSame([1, 2, 11, 880, 1200, 4242, 4243, 4244], array_keys($p), 'ascending pid order');
        $this->assertSame('S', $p[1]->state, 'I (idle > 20 s) reads as sleeping');
        $this->assertSame('S', $p[11]->state, 'W (idle interrupt thread) reads as sleeping');
        $this->assertSame('D', $p[4243]->state, 'L (lock wait) reads as waiting');
        $this->assertSame('Z', $p[4244]->state);
        $this->assertSame(-5, $p[1200]->nice);
        $this->assertSame('www', $p[1200]->user);
        $this->assertSame('65534', $p[4243]->user, 'no passwd entry: numeric uid, as btop');
        $this->assertSame(20.0, $p[4242]->cpu, '80 % of one core on a 4-core host');
        $this->assertSame(8, $p[4242]->threads);
        $this->assertEqualsWithDelta(100.0 * 6000.0 / 36000, $p[4242]->cpuCumulative, 1e-9, '100:00.00 = 6000 s');
        $this->assertEqualsWithDelta(100.0 * (86400 + 2 * 3600 + 3 * 60 + 4.5) / 900000, $p[11]->cpuCumulative, 1e-9);
        $this->assertSame('worker.php', substr($p[4242]->cmd, -10));
        $this->assertSame('php -d memory_limit=-1 worker.php', $p[4242]->cmdBasename(), '#1859 argv[0] basename');
        $this->assertSame('[KTLS]', $p[2]->cmdBasename(), 'a bracketed kernel name is not a path');
        $this->assertSame('nginx: master process /usr/local/sbin/nginx', $p[1200]->cmd);
        $this->assertSame('<defunct>', $p[4244]->cmd);

        [$filtered] = $list->withFilterKernel(true)->sample();
        $this->assertSame([1, 880, 1200, 4242, 4243, 4244], array_keys(self::byPid($filtered->processes)), 'ppid 0 + [bracketed] = kernel');

        [$perCore] = $list->withPerCore(true)->sample();
        $this->assertSame(80.0, self::byPid($perCore->processes)[4242]->cpu);
    }

    public function testDetailReadsCwdForThatPidOnly(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), [
            'ps' => FixtureProbe::fixture('ps-limited.txt'),
            'procstat -f 34179' => FixtureProbe::fixture('procstat-f.txt'),
        ]);
        $list = ProcList::new($probe, userLookup: self::users())->withDetail(34179);
        [$snap] = $list->sample();

        $this->assertNotNull($snap->detail);
        $this->assertSame(34179, $snap->detail->pid);
        $this->assertSame('/usr/home/detain', $snap->detail->cwd, '#1546 via procstat -f');
        $this->assertSame(3599.0, $snap->detail->elapsed);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->detail->ioReadTotal);
        $this->assertSame(['procstat', '-f', '34179'], $probe->runs[1]);

        [$denied] = ProcList::new($probe, userLookup: self::users())->withDetail(34178)->sample();
        $this->assertNull($denied->detail?->cwd, 'procstat denied / absent → null cwd');

        [$none] = $list->withDetail(null)->sample();
        $this->assertNull($none->detail);
    }

    public function testFailedPsIsAnEmptyTableWithHostFigures(): void
    {
        $list = ProcList::new(new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt')));
        [$snap, $next] = $list->sample();

        $this->assertSame(0, $snap->count());
        $this->assertSame(4, $snap->coreCount);
        $this->assertInstanceOf(ProcList::class, $next);
        $this->assertSame($list, $list->withIo(false));
        $this->assertNotSame($list, $list->withIo(true));
    }

    public function testHostFiguresAreReadOnce(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['ps' => FixtureProbe::fixture('ps-limited.txt')]);
        [, $list] = ProcList::new($probe, userLookup: self::users())->sample();
        [$snap] = $list->withPerCore(true)->withDetail(1)->sample();

        $this->assertCount(1, $probe->sysctls, 'hw.ncpu / memory total carried forward through every with*()');
        $this->assertSame(4, $snap->coreCount);
        $this->assertSame(1005423 * 4096, $snap->memTotal);
    }

    public function testCommandStripsOnlyTheCommAnnotation(): void
    {
        $this->assertSame('-bash', ProcList::command('-bash (bash)', 'bash'));
        $this->assertSame('[geom]', ProcList::command('[geom]', 'geom'), 'bracketed kernel args are kept');
        $this->assertSame('vim notes (draft)', ProcList::command('vim notes (draft)', 'vim'), 'only the exact (comm) suffix');
        $this->assertSame('(bash)', ProcList::command('(bash)', 'bash'), 'never strip to empty');
        $this->assertSame('cron', ProcList::command('', 'cron'));
    }

    public function testParsersAreTotal(): void
    {
        $this->assertSame(0.16, ProcList::seconds('0:00.16'));
        $this->assertSame(6000.0, ProcList::seconds('100:00.00'));
        $this->assertSame(3723.5, ProcList::seconds('1:02:03.50'));
        $this->assertSame(-1.0, ProcList::seconds('garbage'));
        $this->assertNull(ProcList::cwd(null));
        $this->assertNull(ProcList::cwd("  PID COMM FD T V FLAGS REF OFFSET PRO NAME\n"));
        $this->assertSame([], ProcList::parse("  PID  PPID\n"));
    }
}
