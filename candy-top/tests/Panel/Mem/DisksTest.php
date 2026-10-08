<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Mem;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Collect\DiskDevice;
use SugarCraft\Top\Collect\DiskIoSnapshot;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MountsSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Mem\DiskRow;
use SugarCraft\Top\Panel\Mem\Disks;
use SugarCraft\Top\Panel\Mem\DisksSample;
use SugarCraft\Top\Panel\Mem\DisksSource;
use SugarCraft\Top\Panel\Mem\DisksView;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeMounts;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\View\Rect;

final class DisksTest extends TestCase
{
    private const GIB = 1024 ** 3;

    private static function mount(string $point, string $device = '/dev/sda1', int $total = 100 * self::GIB, int $used = 40 * self::GIB): Mount
    {
        return new Mount($device, $point, 'ext4', $point === '/' ? 'root' : basename($point), $total, $total - $used, $used);
    }

    private static function device(string $name, float $read, float $write = 0.0, float $busy = 0.0): DiskDevice
    {
        return new DiskDevice($name, $read, $write, $busy, 0, 0);
    }

    private static function memory(int $swapTotal = 2 * self::GIB): MemorySnapshot
    {
        return new MemorySnapshot(16 * self::GIB, 8 * self::GIB, 8 * self::GIB, 2 * self::GIB, 6 * self::GIB, $swapTotal, intdiv($swapTotal, 4), $swapTotal - intdiv($swapTotal, 4));
    }

    private static function ctx(Config $config, int $width = 50): PanelContext
    {
        return new PanelContext($config, null, Rect::new(0, 0, $width, 20));
    }

    /** @return list<string> */
    private static function keys(Disks $d, Config $config): array
    {
        return array_map(static fn (DiskRow $r): string => $r->key, $d->rows($config));
    }

    // ---- roster / sampling ------------------------------------------------

    public function testStandardMemPanelCarriesTheDisksSection(): void
    {
        foreach ([true, false] as $fake) {
            $panel = Panels::standard(PanelPaint::host(), Config::new(), $fake)['mem'];
            $this->assertInstanceOf(MemPanel::class, $panel);
            $this->assertInstanceOf(Disks::class, $panel->disks());
        }
    }

    public function testSourceOnlyWhileShowDisksAndRetunedFromTheCurrentConfig(): void
    {
        $disks = Disks::standard(Config::new(), true);
        $this->assertNull($disks->source(self::ctx(Config::new()->with('show_disks', false))));
        $source = $disks->source(self::ctx(Config::new()->with('disks_filter', 'exclude=/home')->with('use_fstab', false)->with('zfs_hide_datasets', true)));
        $this->assertInstanceOf(DisksSource::class, $source);
        $mounts = $source->mounts();
        $this->assertInstanceOf(FakeMounts::class, $mounts);
        $sel = $mounts->selection();
        $this->assertSame([true, false, ['/home'], true, true], [$sel->physicalOnly, $sel->useFstab, $sel->filter, $sel->exclude, $sel->zfsHideDatasets]);

        $live = Disks::standard(Config::new())->source(self::ctx(Config::new()->with('only_physical', false)));
        $this->assertInstanceOf(DisksSource::class, $live);
        $this->assertInstanceOf(CollectorSource::class, $live->mounts());
        $this->assertFalse($live->mounts()->collector()->selection()->physicalOnly);

        // A plain Source is passed through untouched.
        $plain = new ScriptedSource([new DisksSample([], [])]);
        $this->assertSame($plain, Disks::new($plain)->source(self::ctx(Config::new())));
    }

    public function testDisksSourcePairsMountsWithTheirDevice(): void
    {
        $mounts = new ScriptedSource([new MountsSnapshot([
            self::mount('/', '/dev/mapper/root'),
            self::mount('/boot', '/dev/sda1'),
            self::mount('/data', '/dev/sdb1'),
            self::mount('/net', 'nas:/share'),
        ])]);
        $io = new ScriptedSource([new DiskIoSnapshot([
            'sda' => self::device('sda', 1.0),
            'sda1' => self::device('sda1', 2.0),
            'sdb' => self::device('sdb', 3.0),
            'dm-0' => self::device('dm-0', 4.0),
        ])]);
        $names = ['/dev/mapper/root' => 'dm-0'];
        $source = DisksSource::new($mounts, $io, static fn (string $d): string => $names[$d] ?? basename($d));
        [$sample, $next] = $source->sample();
        $this->assertInstanceOf(DisksSample::class, $sample);
        $this->assertCount(4, $sample->mounts);
        $this->assertSame('dm-0', $sample->io['/']->name, 'canonical device');
        $this->assertSame('sda1', $sample->io['/boot']->name, 'the partition itself');
        $this->assertSame('sdb', $sample->io['/data']->name, 'btop trims sdb1 -> sdb');
        $this->assertArrayNotHasKey('/net', $sample->io);
        $this->assertInstanceOf(DisksSource::class, $next);
        // Non-snapshot results degrade to an empty sample.
        [$empty] = DisksSource::new(new ScriptedSource([new \stdClass()]), new ScriptedSource([new \stdClass()]))->sample();
        $this->assertSame([[], []], [$empty->mounts, $empty->io]);
    }

    // ---- history ------------------------------------------------------------

    public function testFirstSamplePushesZeroThenUnmeasuredHoldsTheLastValue(): void
    {
        $samples = [
            new DisksSample([self::mount('/')], ['/' => self::device('sda1', Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED)]),
            new DisksSample([self::mount('/')], ['/' => self::device('sda1', 500.0, 200.0, 40.0)]),
            new DisksSample([self::mount('/')], ['/' => self::device('sda1', Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED)]),
        ];
        $d = Disks::new(new ScriptedSource($samples));
        $ctx = self::ctx(Config::new());
        foreach ($samples as $s) {
            $d = $d->withSample($s, new ScriptedSource([$s]), $ctx);
        }
        $this->assertSame([0, 500, 500], $d->history()->series(Disks::key('read', '/')));
        $this->assertSame([0, 200, 200], $d->history()->series(Disks::key('write', '/')));
        $this->assertSame([0, 40, 40], $d->history()->series(Disks::key('activity', '/')));
        $this->assertSame(1, $d->ioCount());
    }

    public function testNewDiskStartsAtZeroWhateverItRead(): void
    {
        $s = new DisksSample([self::mount('/')], ['/' => self::device('sda1', 500.0, 200.0, 40.0)]);
        $d = Disks::new(new ScriptedSource([$s]));
        $ctx = self::ctx(Config::new());
        $d = $d->withSample($s, new ScriptedSource([$s]), $ctx)->withSample($s, new ScriptedSource([$s]), $ctx);
        $this->assertSame([0, 500], $d->history()->series(Disks::key('read', '/')));
        $this->assertSame([0, 40], $d->history()->series(Disks::key('activity', '/')));
    }

    /** A sample without the device (one-off unreadable /proc/diskstats) keeps a listed mount's deques. */
    public function testMissingDeviceKeepsHistoryWhileMounted(): void
    {
        $ctx = self::ctx(Config::new());
        $with = new DisksSample([self::mount('/')], ['/' => self::device('sda1', 500.0)]);
        $without = new DisksSample([self::mount('/')], []);
        $d = Disks::new(new ScriptedSource([$with]));
        foreach ([$with, $with, $without, $with] as $s) {
            $d = $d->withSample($s, new ScriptedSource([$s]), $ctx);
            $this->assertSame(1, $d->ioCount());
            $this->assertTrue($d->rows(Config::new()->with('swap_disk', false))[0]->io);
        }
        $this->assertSame([0, 500, 500], $d->history()->series(Disks::key('read', '/')), 'nothing pushed while absent');
        // A mount that never had a device stays IO-less.
        $bare = Disks::new(new ScriptedSource([$without]))->withSample($without, new ScriptedSource([$without]), $ctx);
        $this->assertSame(0, $bare->ioCount());
    }

    public function testHistoryIsCappedAndDroppedWithItsMount(): void
    {
        $d = Disks::new(new ScriptedSource([new DisksSample([], [])]));
        $ctx = self::ctx(Config::new(), 3);
        for ($i = 1; $i <= 10; $i++) {
            $s = new DisksSample([self::mount('/'), self::mount('/x', '/dev/sdb1')], ['/' => self::device('sda1', (float) $i), '/x' => self::device('sdb1', 1.0)]);
            $d = $d->withSample($s, new ScriptedSource([$s]), $ctx);
        }
        $this->assertSame([5, 6, 7, 8, 9, 10], $d->history()->series(Disks::key('read', '/')), 'width * 2');
        $gone = new DisksSample([self::mount('/')], ['/' => self::device('sda1', 11.0)]);
        $d = $d->withSample($gone, new ScriptedSource([$gone]), $ctx);
        $this->assertFalse($d->history()->has(Disks::key('read', '/x')));
        $this->assertSame(['/'], array_map(static fn (Mount $m): string => $m->mountpoint, $d->mounts()));
        // A foreign snapshot keeps the state but takes the next source.
        $kept = $d->withSample(new \stdClass(), new ScriptedSource([$gone]), $ctx);
        $this->assertSame($d->history()->series(Disks::key('read', '/')), $kept->history()->series(Disks::key('read', '/')));
    }

    // ---- line-up ------------------------------------------------------------

    public function testLineUpRootThenSwapThenMountOrder(): void
    {
        $s = new DisksSample([self::mount('/'), self::mount('/boot'), self::mount('/home')], ['/home' => self::device('sdc1', 1.0)]);
        $d = Disks::new(new ScriptedSource([$s]))->withSample($s, new ScriptedSource([$s]), self::ctx(Config::new()), self::memory());
        $config = Config::new();
        $this->assertSame(['/', 'swap', '/boot', '/home'], self::keys($d, $config));
        $rows = $d->rows($config);
        $this->assertSame(['swap', 2 * self::GIB, 25, 75, false], [$rows[1]->name, $rows[1]->total, $rows[1]->usedPercent, $rows[1]->freePercent, $rows[1]->io]);
        $this->assertTrue($rows[3]->io);
        $this->assertSame([40, 60], [$rows[0]->usedPercent, $rows[0]->freePercent]);

        $this->assertSame(['/', '/boot', '/home'], self::keys($d, $config->with('swap_disk', false)));
        $noSwap = Disks::new(new ScriptedSource([$s]))->withSample($s, new ScriptedSource([$s]), self::ctx($config), self::memory(0));
        $this->assertSame(['/', '/boot', '/home'], self::keys($noSwap, $config), 'no swap -> no pseudo-disk');

        $rootless = new DisksSample([self::mount('/boot'), self::mount('/home')], []);
        $r = Disks::new(new ScriptedSource([$rootless]))->withSample($rootless, new ScriptedSource([$rootless]), self::ctx($config), self::memory());
        $this->assertSame(['swap', '/boot', '/home'], self::keys($r, $config));
        // The memory snapshot sticks when a later sample brings none.
        $r = $r->withSample($rootless, new ScriptedSource([$rootless]), self::ctx($config));
        $this->assertContains('swap', self::keys($r, $config));
    }

    public function testDisksOrder1700(): void
    {
        $this->assertSame(['/home', 'swap', '/', '/boot'], Disks::order(['/', 'swap', '/boot', '/home'], ['/home', 'swap']));
        $this->assertSame(['/', 'swap'], Disks::order(['/', 'swap'], ['/nope', '/']), 'unknown names are ignored');
        $this->assertSame(['/b', '/a'], Disks::order(['/a', '/b'], ['/b', '/b']));
        $s = new DisksSample([self::mount('/'), self::mount('/boot'), self::mount('/home')], []);
        $d = Disks::new(new ScriptedSource([$s]))->withSample($s, new ScriptedSource([$s]), self::ctx(Config::new()), self::memory());
        $this->assertSame(['/home', 'swap', '/', '/boot'], self::keys($d, Config::new()->with('disks_order', '/home  swap /nope')));
    }

    public function testIoGraphSpeeds(): void
    {
        $this->assertSame([], DisksView::speeds(Config::new()));
        $this->assertSame(['/mnt/media' => 100, '/' => 20], DisksView::speeds(Config::new()->with('io_graph_speeds', '/mnt/media:100 /:20')));
        $this->assertSame(['/' => 5], DisksView::speeds(Config::new()->with('io_graph_speeds', '/:5 /x:99999999999')), 'btop drops out-of-range values');
    }

    // ---- keys -----------------------------------------------------------------

    /** `i` (MemPanel) writes io_mode; the section repaints in io mode on the next frame. */
    public function testIoModeKeyFlipsTheDisksView(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $panel = PanelPaint::feed(MemPanel::standard($config, true), $config, $layout, 6);
        $before = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('mem'));
        $result = $panel->update(new KeyMsg(KeyType::Char, 'i'), PanelPaint::context('mem', $config, $layout));
        $this->assertSame(['io_mode' => true], $result->set);
        $flipped = $config->with('io_mode', true);
        $after = PanelPaint::grid(PanelPaint::surface($result->panel, $flipped, $layout, $host), $layout->box('mem'));
        $this->assertStringContainsString('Used:', $before);
        $this->assertStringNotContainsString(' Used: ', $after);
        $this->assertMatchesRegularExpression('/▲[0-9.]+M/u', $after, 'read graph label');
        $this->assertMatchesRegularExpression('/▼[0-9.]+M/u', $after, 'write graph label');
    }
}
