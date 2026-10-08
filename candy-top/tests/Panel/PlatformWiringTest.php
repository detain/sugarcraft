<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect;
use SugarCraft\Top\Collect\FreeBsd;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Cpu\BorderBattery;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\Gfx\NamedSources;
use SugarCraft\Top\Panel\Mem\Disks;
use SugarCraft\Top\Panel\Mem\DisksSource;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\Net\NetPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;

/**
 * Panels::standard threads one {@see Platform} through every panel
 * factory, and each panel's collect-time retune must reach the FreeBSD
 * collector too — the retune checks name the shared interfaces, never
 * the Linux classes. On Linux the roster must stay exactly what it was.
 */
final class PlatformWiringTest extends TestCase
{
    private static function freeBsd(FixtureProbe $probe): Platform
    {
        return Platform::for('FreeBSD', $probe);
    }

    private static function prop(object $o, string $name): mixed
    {
        return (new \ReflectionProperty($o, $name))->getValue($o);
    }

    // ---- cpu: show_core_freq → TunableFreq ---------------------------------

    public function testCpuPanelRetunesTheFreeBsdFreqCollector(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'));
        $panel = CpuPanel::standard(PanelPaint::host(), Config::new(), false, self::freeBsd($probe));
        // gpu is Collect\Gpu\Accelerators (nvidia-smi only on FreeBSD); keep the live host out of the test.
        $config = Config::new()->with('show_gpu_info', 'Off')->with('check_temp', false)->with('show_battery', false);

        $off = ($panel->collect(PanelPaint::context('cpu', $config, null)))();
        $this->assertInstanceOf(SampledMsg::class, $off);
        $this->assertInstanceOf(Samples::class, $off->snapshot);
        $this->assertSame([], $off->snapshot->get('freq')->perCore);

        $on = ($panel->collect(PanelPaint::context('cpu', $config->with('show_core_freq', 'graph'), null)))();
        $this->assertCount(4, $on->snapshot->get('freq')->perCore, 'the retune reached the FreeBSD collector');
        $this->assertInstanceOf(NamedSources::class, $on->next);
        $freq = $on->next->get('freq');
        $this->assertInstanceOf(CollectorSource::class, $freq);
        $this->assertInstanceOf(FreeBsd\Freq::class, $freq->collector());
        $this->assertTrue(self::prop($freq->collector(), 'perCore'));
        $this->assertInstanceOf(CollectorSource::class, $on->next->get('cpu'));
        $this->assertInstanceOf(FreeBsd\Cpu::class, $on->next->get('cpu')->collector());
    }

    // ---- battery badge: selected_battery → SelectableBattery ---------------

    public function testBatteryBadgeReselectsTheFreeBsdBatteryCollector(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-battery-discharging.synthetic.txt'));
        $panel = CpuPanel::standard(PanelPaint::host(), Config::new(), false, self::freeBsd($probe));
        $badge = $panel->battery();
        $this->assertInstanceOf(BorderBattery::class, $badge);

        $source = $badge->source(new PanelContext(Config::new()->with('selected_battery', 'BAT1')));
        $this->assertInstanceOf(CollectorSource::class, $source);
        $collector = $source->collector();
        $this->assertInstanceOf(FreeBsd\Battery::class, $collector);
        $this->assertSame('BAT1', $collector->selected(), 'read from the CURRENT config');
        $this->assertNull($badge->source(new PanelContext(Config::new()))->collector()->selected(), 'Auto');
    }

    // ---- disks: only_physical / disks_filter → SelectableMounts ------------

    public function testDisksSectionReselectsTheFreeBsdMountsCollector(): void
    {
        $probe = new FixtureProbe('', ['mount' => FixtureProbe::fixture('mount-p-zfs.synthetic.txt')], spaces: ['/' => [100, 40], '/mnt/My Data' => [200, 50], '/usr/home' => [300, 60]]);
        $panel = MemPanel::standard(Config::new(), false, self::freeBsd($probe));
        $disks = $panel->disks();
        $this->assertInstanceOf(Disks::class, $disks);

        $config = Config::new()->with('only_physical', false)->with('disks_filter', '/usr/home');
        $source = $disks->source(new PanelContext($config, null, Rect::new(0, 0, 50, 20)));
        $this->assertInstanceOf(DisksSource::class, $source);
        $mounts = $source->mounts();
        $this->assertInstanceOf(CollectorSource::class, $mounts);
        $this->assertInstanceOf(FreeBsd\Mounts::class, $mounts->collector());
        $this->assertFalse($mounts->collector()->selection()->physicalOnly, 'retuned from the CURRENT config');
        [$snap] = $mounts->sample();
        $this->assertSame(['/usr/home'], array_map(static fn ($m) => $m->mountpoint, $snap->mounts), 'disks_filter reached the collector');

        $io = self::prop($source, 'io');
        $this->assertInstanceOf(CollectorSource::class, $io);
        $this->assertInstanceOf(FreeBsd\DiskIo::class, $io->collector());
        $this->assertFalse(self::prop($io->collector(), 'physicalOnly'), 'unfiltered, as DisksSource::live() pairs partitions');
    }

    // ---- proc: per-core / kernel filter / detail → TunableProcList --------

    public function testProcPanelRetunesTheFreeBsdProcListCollector(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['ps' => FixtureProbe::fixture('ps-root.synthetic.txt')]);
        $panel = Panels::standard(PanelPaint::host(), Config::new(), false, self::freeBsd($probe))['proc'];
        $this->assertInstanceOf(ProcPanel::class, $panel);

        $config = Config::new()->with('proc_per_core', true)->with('proc_filter_kernel', true)->with('proc_sorting', 'io total');
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $msg = ($panel->collect(new PanelContext($config, $layout, $layout->box('proc'))))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertInstanceOf(ProcSnapshot::class, $msg->snapshot);
        $this->assertNotContains(2, array_map(static fn ($p): int => $p->pid, $msg->snapshot->processes), 'proc_filter_kernel reached the collector');
        $this->assertInstanceOf(CollectorSource::class, $msg->next);
        $collector = $msg->next->collector();
        $this->assertInstanceOf(FreeBsd\ProcList::class, $collector);
        $this->assertTrue(self::prop($collector, 'perCore'));
        $this->assertTrue(self::prop($collector, 'filterKernel'));
        $this->assertTrue(self::prop($collector, 'readIo'), 'an io sort asks for the rates (accepted, not collected on FreeBSD)');
    }

    // ---- roster -------------------------------------------------------------

    public function testFreeBsdRosterUsesTheFreeBsdFamilyEverywhere(): void
    {
        $panels = Panels::standard(PanelPaint::host(), Config::new(), false, self::freeBsd(new FixtureProbe()));
        $cpu = self::prop($panels['cpu'], 'sources');
        $this->assertInstanceOf(FreeBsd\Cpu::class, $cpu->get('cpu')->collector());
        $this->assertInstanceOf(FreeBsd\Freq::class, $cpu->get('freq')->collector());
        $this->assertInstanceOf(FreeBsd\Temp::class, $cpu->get('temp')->collector());
        $this->assertInstanceOf(FreeBsd\Battery::class, self::prop($panels['cpu']->battery(), 'source')->collector());
        $this->assertInstanceOf(FreeBsd\Memory::class, self::prop($panels['mem'], 'sources')->get('mem')->collector());
        $this->assertInstanceOf(FreeBsd\Net::class, self::prop($panels['net'], 'source')->collector());
        $this->assertInstanceOf(FreeBsd\ProcList::class, $panels['proc']->source()->collector());
        $this->assertSame(
            self::describe(CollectorSource::of(Collect\Gpu\Accelerators::nvidiaOnly())),
            self::describe(self::prop($panels['gpu'], 'source')),
            'FreeBSD gpu boxes read nvidia-smi only',
        );
        $this->assertSame(self::describe(self::prop($panels['gpu'], 'source')), self::describe($cpu->get('gpu')));
    }

    public function testLinuxRosterBuildsTheSameCollectorsAsBefore(): void
    {
        $config = Config::new()->with('selected_battery', 'BAT0')->with('cpu_sensor', 'coretemp/Package id 0')
            ->with('freq_mode', 'highest')->with('show_core_freq', 'value')->with('zfs_arc_cached', false);
        $panels = Panels::standard(PanelPaint::host(), $config, false, Platform::for('Linux'));

        // The pre-Platform wiring, verbatim.
        $expected = [
            'cpu' => CollectorSource::of(Collect\Cpu::new()),
            'freq' => CollectorSource::of(Collect\Freq::new(null, FreqMode::tryFrom('highest') ?? FreqMode::First, true)),
            'temp' => CollectorSource::of(Collect\Temp::new(null, 'coretemp/Package id 0')),
            // U4: the multi-vendor accelerators replace the bare nvidia-smi Collect\Gpu.
            'gpu' => CollectorSource::of(Collect\Gpu\Accelerators::detect()),
            'gpuBoxes' => CollectorSource::of(Collect\Gpu\Accelerators::detect()),
            'battery' => CollectorSource::of(Collect\Battery::new(null, 'BAT0')),
            'mem' => CollectorSource::of(Collect\Memory::new(null, false)),
            'mounts' => CollectorSource::of(Collect\Mounts::new()),
            'diskIo' => CollectorSource::of(Collect\DiskIo::new(null, null, false)),
            'net' => CollectorSource::of(Collect\Net::new()),
            'proc' => CollectorSource::of(Collect\ProcList::new()),
        ];
        $cpu = self::prop($panels['cpu'], 'sources');
        $disks = self::prop($panels['mem']->disks(), 'source');
        $this->assertInstanceOf(DisksSource::class, $disks);
        $actual = [
            'cpu' => $cpu->get('cpu'),
            'freq' => $cpu->get('freq'),
            'temp' => $cpu->get('temp'),
            'gpu' => $cpu->get('gpu'),
            'gpuBoxes' => self::prop($panels['gpu'], 'source'),
            'battery' => self::prop($panels['cpu']->battery(), 'source'),
            'mem' => self::prop($panels['mem'], 'sources')->get('mem'),
            'mounts' => $disks->mounts(),
            'diskIo' => self::prop($disks, 'io'),
            'net' => self::prop($panels['net'], 'source'),
            'proc' => $panels['proc']->source(),
        ];
        $this->assertInstanceOf(NetPanel::class, $panels['net']);
        foreach ($expected as $name => $source) {
            $this->assertSame(self::describe($source), self::describe($actual[$name]), $name);
        }
        // The default (no Platform) is the running host's family — Linux here.
        if (PHP_OS === 'Linux') {
            $default = Panels::standard(PanelPaint::host(), $config, false);
            $this->assertSame(self::describe($actual['proc']), self::describe($default['proc']->source()));
            $this->assertSame(self::describe($cpu), self::describe(self::prop($default['cpu'], 'sources')));
        }
    }

    /**
     * Class + every property, recursively; closures compare as their
     * presence (two `static fn` defaults are never the same instance).
     */
    private static function describe(mixed $v, int $depth = 0): mixed
    {
        if ($v instanceof \Closure) {
            return 'closure';
        }
        if (is_array($v)) {
            return array_map(static fn (mixed $x): mixed => self::describe($x, $depth + 1), $v);
        }
        if (!is_object($v) || $depth > 8) {
            return $v;
        }
        $out = ['class' => $v::class];
        foreach ((new \ReflectionObject($v))->getProperties() as $p) {
            if ($p->isStatic() || !$p->isInitialized($v)) {
                continue;
            }
            $out[$p->getName()] = self::describe($p->getValue($v), $depth + 1);
        }

        return $out;
    }
}
