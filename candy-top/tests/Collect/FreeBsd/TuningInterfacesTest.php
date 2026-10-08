<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect;
use SugarCraft\Top\Collect\FreeBsd;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\SelectableBattery;
use SugarCraft\Top\Collect\SelectableMounts;
use SugarCraft\Top\Collect\TunableFreq;
use SugarCraft\Top\Collect\TunableProcList;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

/**
 * The panels' collect-time retuning goes through these interfaces, so
 * both collector families must satisfy them — and the retune must land.
 */
final class TuningInterfacesTest extends TestCase
{
    public function testBothFamiliesImplementTheTuningInterfaces(): void
    {
        $this->assertInstanceOf(TunableFreq::class, Collect\Freq::new());
        $this->assertInstanceOf(TunableFreq::class, FreeBsd\Freq::new(new FixtureProbe()));
        $this->assertInstanceOf(SelectableBattery::class, Collect\Battery::new());
        $this->assertInstanceOf(SelectableBattery::class, FreeBsd\Battery::new(new FixtureProbe()));
        $this->assertInstanceOf(SelectableMounts::class, Collect\Mounts::new());
        $this->assertInstanceOf(SelectableMounts::class, FreeBsd\Mounts::new(new FixtureProbe()));
        $this->assertInstanceOf(TunableProcList::class, Collect\ProcList::new());
        $this->assertInstanceOf(TunableProcList::class, FreeBsd\ProcList::new(new FixtureProbe()));
    }

    public function testFreqRetuneThroughTheInterfaceRoundTrips(): void
    {
        $source = CollectorSource::of(FreeBsd\Freq::new(new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'))));
        $c = $source->collector();
        $this->assertInstanceOf(TunableFreq::class, $c);

        $on = $c->withPerCore(true);
        $this->assertInstanceOf(FreeBsd\Freq::class, $on);
        [$snap, $next] = CollectorSource::of($on)->sample();
        $this->assertCount(4, $snap->perCore);
        $this->assertInstanceOf(TunableFreq::class, $next->collector());

        [$off] = $on->withPerCore(false)->sample();
        $this->assertSame([], $off->perCore);
        $this->assertSame($on, $on->withPerCore(true), 'no-op retune keeps the instance');
    }

    public function testBatteryRetuneThroughTheInterfaceRoundTrips(): void
    {
        $c = CollectorSource::of(FreeBsd\Battery::new(new FixtureProbe(FixtureProbe::fixture('sysctl-battery-discharging.synthetic.txt'))))->collector();
        $this->assertInstanceOf(SelectableBattery::class, $c);

        $picked = $c->withSelected('BAT1');
        $this->assertSame('BAT1', $picked->selected());
        $this->assertNull($picked->withSelected('Auto')->selected());
        [$snap] = $picked->sample();
        $this->assertSame(74, $snap->percent, 'one aggregate battery whatever the selection');
    }

    public function testMountsRetuneThroughTheInterfaceRoundTrips(): void
    {
        $probe = new FixtureProbe('', ['mount' => FixtureProbe::fixture('mount-p-zfs.synthetic.txt')], spaces: ['/' => [100, 40], '/mnt/My Data' => [200, 50], '/usr/home' => [300, 60]]);
        $c = CollectorSource::of(FreeBsd\Mounts::new($probe))->collector();
        $this->assertInstanceOf(SelectableMounts::class, $c);

        $selection = MountSelection::new(true, false, '/usr/home');
        $tuned = $c->withSelection($selection);
        $this->assertSame($selection, $tuned->selection());
        [$snap] = $tuned->sample();
        $this->assertSame(['/usr/home'], array_map(static fn ($m) => $m->mountpoint, $snap->mounts), 'disks_filter applied');
    }

    public function testProcListRetuneThroughTheInterfaceRoundTrips(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), [
            'ps' => FixtureProbe::fixture('ps-root.synthetic.txt'),
            'procstat -f 4242' => "  PID COMM FD T V FLAGS REF OFFSET PRO NAME\n 4242 php cwd v d r------- - - - /srv/app\n",
        ]);
        $c = CollectorSource::of(FreeBsd\ProcList::new($probe, userLookup: static fn (int $u): ?string => null))->collector();
        $this->assertInstanceOf(TunableProcList::class, $c);

        $tuned = $c->withIo(true)->withPerCore(true)->withFilterKernel(true)->withDetail(4242);
        $this->assertInstanceOf(FreeBsd\ProcList::class, $tuned);
        [$snap] = $tuned->sample();
        $pids = array_map(static fn ($p) => $p->pid, $snap->processes);
        $this->assertNotContains(2, $pids, 'proc_filter_kernel applied');
        $php = $snap->processes[array_search(4242, $pids, true)];
        $this->assertSame(80.0, $php->cpu, 'proc_per_core applied');
        $this->assertSame('/srv/app', $snap->detail?->cwd, 'detailed pid applied');

        [$back] = $tuned->withPerCore(false)->withFilterKernel(false)->withDetail(null)->sample();
        $this->assertContains(2, array_map(static fn ($p) => $p->pid, $back->processes));
        $this->assertNull($back->detail);
    }
}
