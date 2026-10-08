<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Ctr;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\VmInfo;
use SugarCraft\Top\Collect\Containers;
use SugarCraft\Top\Collect\ContainerSnapshot;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Ctr\CtrSample;
use SugarCraft\Top\Panel\Ctr\CtrView;
use SugarCraft\Top\Panel\CtrPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Proc\ProcGpuSample;
use SugarCraft\Top\Source\Fake\FakeContainers;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * btop PR #1873's containers box: sampling (own scan vs the proc tap),
 * selection (Ctr::select / select_row), the scroll law, mouse zones and
 * the paint goldens under tests/fixtures/panels/ctr/ (regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/Ctr`).
 */
final class CtrPanelTest extends TestCase
{
    private const string BOXES = 'cpu mem net ctr proc';

    public function testHiddenBoxNeverSamples(): void
    {
        $panel = CtrPanel::standard(8, true);
        $config = Config::new();
        $this->assertNull($panel->collect(new PanelContext($config)), '#1858: no ctr box, no scan');
        $proc = new SampledMsg('proc', FakeProcList::demo(8)->withContainers()->sample()[0], FakeProcList::demo(8));
        $this->assertNull($panel->update($proc, new PanelContext($config))->cmd, 'a hidden box ignores the tap');
    }

    public function testOwnScanOnlyWhileProcIsHidden(): void
    {
        $config = Config::new()->with('shown_boxes', self::BOXES);
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
        $panel = CtrPanel::standard(8, true);
        $this->assertNull($panel->collect($ctx), 'proc shown: only the tap feeds the box — never a second, cold /proc scan');
        $panel = $this->feed($panel, $config, $layout, 1);
        $this->assertSame(7, $panel->snapshot()?->count());
        $this->assertNull($panel->collect($ctx));

        $alone = Config::new()->with('shown_boxes', 'cpu ctr');
        $cmd = $panel->collect(new PanelContext($alone));
        $this->assertNotNull($cmd, 'a lone ctr box scans for itself, as btop runs Proc::collect for it');
        $msg = $cmd();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('ctr', $msg->box);
    }

    public function testADisabledCollectorNeverSamples(): void
    {
        // FreeBSD: btop fills the box on Linux only — no scan just to draw an empty box.
        $panel = CtrPanel::new(FakeProcList::demo(8)->withContainers(), Containers::disabled());
        $this->assertSame(0, $panel->snapshot()?->count(), 'the empty box shows at once');
        foreach (['cpu ctr', self::BOXES] as $boxes) {
            $config = Config::new()->with('shown_boxes', $boxes);
            $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
            $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
            $this->assertNull($panel->collect($ctx), $boxes);
            $tap = new SampledMsg('proc', FakeProcList::demo(8)->withContainers()->sample()[0], FakeProcList::demo(8));
            $this->assertNull($panel->update($tap, $ctx)->cmd, $boxes);
        }
    }

    public function testASampleShorterThanHalfTheIntervalIsSkipped(): void
    {
        $config = Config::new()->with('shown_boxes', self::BOXES)->with('update_ms', 2000);
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
        $now = 1_000_000;
        $panel = CtrPanel::new(FakeProcList::demo(8), Containers::new(sys_get_temp_dir() . '/candy-top-no-cgroup', static fn (): string => '', static function () use (&$now): int {
            return $now;
        }));
        $procs = FakeProcList::demo(8)->withContainers()->sample()[0];
        $tap = new SampledMsg('proc', $procs, FakeProcList::demo(8));
        $first = $panel->update($tap, $ctx)->cmd;
        $this->assertNotNull($first);
        $msg = $first();
        $this->assertInstanceOf(SampledMsg::class, $msg, 'the first sample always counts');
        $panel = $panel->update($msg, $ctx)->panel;
        $this->assertInstanceOf(CtrPanel::class, $panel);

        $now += 300_000; // a sort change 0.3 s later rescans proc
        $quick = $panel->update($tap, $ctx)->cmd;
        $this->assertNotNull($quick);
        $this->assertNull($quick(), 'under update_ms / 2: skipped, no history point');
        $now += 700_000; // 1.0 s after the last counted sample
        $ok = $panel->update($tap, $ctx)->cmd;
        $this->assertNotNull($ok);
        $this->assertInstanceOf(SampledMsg::class, $ok());
    }

    public function testTheProcTapProducesACtrSample(): void
    {
        $config = Config::new()->with('shown_boxes', self::BOXES);
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
        $procs = FakeProcList::demo(8)->withContainers()->sample()[0];
        $this->assertInstanceOf(ProcSnapshot::class, $procs);
        foreach ([$procs, new ProcGpuSample($procs, new \SugarCraft\Top\Collect\GpuSnapshot([]), FakeProcList::demo())] as $payload) {
            $result = CtrPanel::standard(8, true)->update(new SampledMsg('proc', $payload, FakeProcList::demo()), $ctx);
            $this->assertNotNull($result->cmd);
            $msg = ($result->cmd)();
            $this->assertInstanceOf(SampledMsg::class, $msg);
            $this->assertSame('ctr', $msg->box);
            $this->assertInstanceOf(CtrSample::class, $msg->snapshot);
            $this->assertSame(
                ['9a0c5e21b7d4', 'arch', 'build', 'c41d9e7f0a13', 'pg-main', 'web-1', 'web01'],
                array_map(static fn (ContainerInfo $c): string => $c->name, $msg->snapshot->snapshot->containers),
            );
        }
    }

    public function testSelectStepsThroughNoneAndWraps(): void
    {
        $snap = new ContainerSnapshot([new ContainerInfo('docker', 'a', '/a'), new ContainerInfo('lxc', 'b', '/b')]);
        $this->assertSame('/a', CtrPanel::stepped($snap, '', 1));
        $this->assertSame('/b', CtrPanel::stepped($snap, '/a', 1));
        $this->assertSame('', CtrPanel::stepped($snap, '/b', 1), 'position 0 is no selection');
        $this->assertSame('/b', CtrPanel::stepped($snap, '', -1));
        $this->assertSame('', CtrPanel::stepped($snap, '/a', -1));
        $this->assertSame('/a', CtrPanel::stepped($snap, '/gone', 1), 'an unknown selection counts as none');
        $this->assertSame('', CtrPanel::stepped(new ContainerSnapshot([]), '', 1));
        $this->assertSame('', CtrPanel::stepped(null, '', -1));
    }

    public function testBracketKeysWriteTheSelection(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40);
        $r = $panel->update(new KeyMsg(KeyType::Char, ']'), $ctx);
        $this->assertStringStartsWith('/kubepods.slice/', $r->set[Schema::CTR_SELECTED], 'none -> the first by name (byte order: 9a0c… sorts first)');
        $r = $panel->update(new KeyMsg(KeyType::Char, '['), $ctx);
        $this->assertSame([Schema::CTR_SELECTED => '/machine.slice/machine-qemu\x2d1\x2dweb01.scope'], $r->set, 'from none backwards: the last (the libvirt guest web01 sorts after web-1)');
        $this->assertSame([], $panel->update(new KeyMsg(KeyType::Char, ']', ctrl: true), $ctx)->set);
        $hidden = new PanelContext($ctx->config);
        $this->assertSame([], $panel->update(new KeyMsg(KeyType::Char, ']'), $hidden)->set, 'btop: only while Ctr::shown');
    }

    public function testMouseZones(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40);
        $box = $ctx->box;
        $this->assertNotNull($box);
        $w = $box->width;
        $map = CtrView::buttons($box, $panel->snapshot(), '', 0);
        // btop: "[" {y, x + width - 12, 1, 5}, "]" {y, x + width - 7, 1, 5}, ctr_rowN {y + 2 + n, x + 1, 1, list_width}.
        $this->assertSame([$box->x + $w - 12, $box->y, 5, 1], $map['[']);
        $this->assertSame([$box->x + $w - 7, $box->y, 5, 1], $map[']']);
        $this->assertSame([$box->x + 1, $box->y + 2, $w - 2, 1], $map['ctr_row0']);
        $this->assertArrayHasKey('ctr_row5', $map);
        $this->assertArrayNotHasKey('ctr_row6', $map, 'rows below the last container are unmapped');

        $this->assertTrue($panel->capturesClick(self::click($box->x + $w - 10, $box->y), $ctx));
        $this->assertSame($panel->update(new KeyMsg(KeyType::Char, ']'), $ctx)->set, $panel->update(self::click($box->x + $w - 4, $box->y), $ctx)->set, '"]" half');
        $this->assertSame($panel->update(new KeyMsg(KeyType::Char, '['), $ctx)->set, $panel->update(self::click($box->x + $w - 11, $box->y), $ctx)->set, '"[" half');
        $row = $panel->update(self::click($box->x + 5, $box->y + 4), $ctx);
        $this->assertSame([Schema::CTR_SELECTED => '/lxc.payload.build'], $row->set, 'a row click selects it (row 2: build)');
        $selected = new PanelContext($ctx->config->with(Schema::CTR_SELECTED, '/lxc.payload.build'), $ctx->layout, $box);
        $this->assertSame([Schema::CTR_SELECTED => ''], $panel->update(self::click($box->x + 5, $box->y + 4), $selected)->set, 'a second click deselects');
        $this->assertFalse($panel->capturesClick(self::click($box->x + 5, $box->y + 12), $ctx), 'an empty row is no zone');
        $right = new MouseMsg($box->x + 6, $box->y + 4, MouseButton::Right, MouseAction::Press);
        $this->assertFalse($panel->capturesClick($right, $ctx), 'only a bare left click');
        // With the detail panel open (>= 80 wide) the rows end at the divider.
        $wide = Rect::new(0, 10, 120, 12);
        $detail = CtrView::buttons($wide, $panel->snapshot(), '/lxc.payload.build', 0);
        $this->assertSame(118 - 48, $detail['ctr_row0'][2]);
        $this->assertSame(118, CtrView::buttons($wide, $panel->snapshot(), '', 0)['ctr_row0'][2]);
    }

    public function testAVanishedSelectionIsCleared(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40);
        $ctx = new PanelContext($ctx->config->with(Schema::CTR_SELECTED, '/gone'), $ctx->layout, $ctx->box);
        $msg = new SampledMsg('ctr', new CtrSample(new ContainerSnapshot([]), FakeContainers::new()), FakeProcList::demo());
        $this->assertSame([Schema::CTR_SELECTED => ''], $panel->update($msg, $ctx)->set);
    }

    public function testScrollKeepsTheSelectionInView(): void
    {
        $this->assertSame(0, CtrView::scroll(0, -1, 3, 5));
        $this->assertSame(6, CtrView::scroll(0, 10, 20, 5), 'selected row 10 lands on the last line');
        $this->assertSame(3, CtrView::scroll(7, 3, 20, 5), 'scrolls back up to it');
        $this->assertSame(15, CtrView::scroll(40, -1, 20, 5), 'never past the end');
        $this->assertSame(0, CtrView::scroll(4, -1, 2, 5));
    }

    public function testGeometry(): void
    {
        $this->assertSame(['detail' => false, 'dWidth' => 0, 'dx' => 119, 'list' => 118, 'engine' => 9, 'name' => 83], CtrView::geometry(120, false));
        $this->assertSame(['detail' => true, 'dWidth' => 48, 'dx' => 71, 'list' => 70, 'engine' => 9, 'name' => 35], CtrView::geometry(120, true));
        $this->assertFalse(CtrView::geometry(79, true)['detail'], 'btop: detail from 80 columns');
        $this->assertSame(32, CtrView::geometry(80, true)['dWidth'], 'max(30, width * 2 / 5)');
        $this->assertSame(0, CtrView::geometry(53, false)['engine'], 'engine column from a 52-wide list');
        $this->assertSame([9, 17], [CtrView::geometry(54, false)['engine'], CtrView::geometry(54, false)['name']], 'a 52-wide list: name = 52 - 25 - 10');
        $this->assertSame(3, CtrView::selectMax(6), 'btop select_max = height - 3');
    }

    public function testMiniGraphsFollowDrawnRowsAndVanishWithTheirContainer(): void
    {
        [$panel, $ctx] = $this->sampled(120, 40);
        $this->assertCount(6, $panel->graphs());
        $msg = new SampledMsg('ctr', new CtrSample(new ContainerSnapshot([new ContainerInfo('lxc', 'build', '/lxc.payload.build', 1, 0.05)]), FakeContainers::new()), FakeProcList::demo());
        $next = $panel->update($msg, $ctx)->panel;
        $this->assertInstanceOf(CtrPanel::class, $next);
        $this->assertSame(['/lxc.payload.build'], array_keys($next->graphs()));
        // A 6-row box shows 3 rows: only those get a graph.
        $tiny = new PanelContext($ctx->config, null, Rect::new(0, 0, 50, 6));
        $fresh = CtrPanel::standard(8, true)->update(new SampledMsg('ctr', new CtrSample(new ContainerSnapshot(array_map(
            static fn (int $i): ContainerInfo => new ContainerInfo('lxc', 'c' . $i, '/c' . $i, 1, 3.0),
            range(1, 5),
        )), FakeContainers::new()), FakeProcList::demo()), $tiny)->panel;
        $this->assertInstanceOf(CtrPanel::class, $fresh);
        $this->assertSame(['/c1', '/c2', '/c3'], array_keys($fresh->graphs()));
    }

    public function testPaintStaysInsideItsBoxAtEverySize(): void
    {
        foreach ([[80, 24, 'cpu ctr'], [120, 40, self::BOXES], [44, 6, 'ctr'], [200, 60, 'ctr proc'], [90, 30, 'cpu mem ctr proc']] as [$cols, $rows, $boxes]) {
            $config = Config::new()->with('shown_boxes', $boxes);
            foreach (['', '/lxc.payload.build'] as $sel) {
                $c = $config->with(Schema::CTR_SELECTED, $sel);
                $layout = PanelPaint::layout($cols, $rows, $c, PanelPaint::host());
                $panel = $this->feed(CtrPanel::standard(8, true), $c, $layout, 3);
                $rect = $layout->box('ctr');
                $this->assertNotNull($rect, "{$cols}x{$rows} {$boxes}");
                $surface = Surface::new($cols, $rows);
                $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, Ink::new(ThemeConfig::new()), FrameBuilder::border($c), $c, PanelPaint::host()));
                foreach ($surface->plainLines() as $y => $line) {
                    foreach (mb_str_split($line) as $x => $ch) {
                        $this->assertTrue($ch === ' ' || $rect->contains($x, $y), "{$cols}x{$rows} {$boxes} [{$sel}] cell {$x},{$y}");
                    }
                }
            }
        }
    }

    public function testListGolden(): void
    {
        PanelPaint::assertGolden($this, 'ctr', 'list-120x40.txt', $this->grid(120, 40, self::BOXES));
    }

    public function testListSgrGolden(): void
    {
        PanelPaint::assertGolden($this, 'ctr', 'list-120x40.sgr.txt', $this->grid(120, 40, self::BOXES, sgr: true));
    }

    public function testDetailGolden(): void
    {
        PanelPaint::assertGolden($this, 'ctr', 'detail-120x40.txt', $this->grid(120, 40, 'cpu ctr proc', '/lxc.payload.build'));
    }

    public function testDetailSgrGolden(): void
    {
        PanelPaint::assertGolden($this, 'ctr', 'detail-120x40.sgr.txt', $this->grid(120, 40, 'cpu ctr proc', '/lxc.payload.build', sgr: true));
    }

    public function testVmDetailGolden(): void
    {
        // Beyond btop: the demo libvirt guest, its cpu as a share of its vCPUs, limited by its -m (4 GiB) since memory.max is "max".
        $grid = $this->grid(120, 40, 'cpu ctr proc', '/machine.slice/machine-qemu\x2d1\x2dweb01.scope');
        $this->assertStringContainsString('Cpu 7.5% · 15.0% of 4 vCPU', $grid, 'host share, then 0.6 cores of 4 vCPUs');
        $this->assertStringContainsString('/4.0G', $grid);
        PanelPaint::assertGolden($this, 'ctr', 'vm-detail-120x40.txt', $grid);
    }

    public function testVmCpuAgainstItsOwnVcpus(): void
    {
        $vm = (new ContainerInfo('kvm', 'g', '/g', cpu: 12.5))->withVm(new VmInfo(1, 'g', 'g', Sentinel::UNAVAILABLE, 2, 1 << 30));
        $this->assertSame(50.0, CtrView::guestShare($vm, false, 8), '12.5 % of 8 cores = 1 core = half of 2 vCPUs');
        $this->assertSame(6.25, CtrView::guestShare($vm, true, 8), 'proc_per_core: already in cores');
        $this->assertNull(CtrView::guestShare(new ContainerInfo('docker', 'd', '/d', cpu: 50.0), false, 8));
        $this->assertNull(CtrView::guestShare($vm->withVm(new VmInfo()), false, 8), 'unknown vCPUs');
        $this->assertSame('g kvm    Cpu 12.5% · 50.0% of 2 vCPU', CtrView::detailTitle($vm, 37, false, 8));
        $this->assertSame('g kvm 2 vCPU        Cpu 12.5%', CtrView::detailTitle($vm, 30, false, 8), 'too narrow: btop\'s layout, vCPUs on the left');
        $this->assertSame('d docker          Cpu 50.0%', CtrView::detailTitle(new ContainerInfo('docker', 'd', '/d', cpu: 50.0), 28, false, 8), 'containers keep btop\'s title');
    }

    public function testShowVmsOffRestoresBtopsContainersOnlyBox(): void
    {
        // The tap path (proc shown): the count drops the guest.
        $this->assertStringContainsString('0/7', $this->grid(120, 40, self::BOXES));
        $this->assertStringContainsString('0/6', $this->grid(120, 40, self::BOXES, options: [CtrPanel::SHOW_VMS => false]));
        // The own-scan path (proc hidden), where every row is drawn.
        $this->assertStringContainsString('web01', $this->grid(80, 24, 'cpu ctr'));
        $alone = $this->grid(80, 24, 'cpu ctr', options: [CtrPanel::SHOW_VMS => false]);
        $this->assertStringNotContainsString('web01', $alone);
        $this->assertStringContainsString('0/6', $alone);
    }

    public function testNoProcColorsSgrGolden(): void
    {
        $sgr = $this->grid(120, 40, self::BOXES, '/system.slice/docker-3f2a1b9c0d1e.scope', sgr: true, options: ['proc_colors' => false]);
        PanelPaint::assertGolden($this, 'ctr', 'plain-colors-120x40.sgr.txt', $sgr);
    }

    public function testNarrowListDropsTheEngineColumn(): void
    {
        $grid = $this->grid(90, 30, 'cpu mem ctr proc');
        $this->assertStringNotContainsString('Engine:', $grid);
        PanelPaint::assertGolden($this, 'ctr', 'narrow-90x30.txt', $grid);
    }

    public function testAloneTakesTheWholeColumn(): void
    {
        PanelPaint::assertGolden($this, 'ctr', 'alone-80x24.txt', $this->grid(80, 24, 'cpu ctr', '/machine.slice/machine-arch.scope'));
    }

    public function testTtySgrGolden(): void
    {
        $sgr = $this->grid(80, 24, 'cpu ctr', '/machine.slice/machine-arch.scope', sgr: true, options: ['tty_mode' => true, 'rounded_corners' => false], tty: true);
        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*[34]8;2;/', $sgr, 'no truecolor in tty mode');
        $this->assertStringContainsString('xctr', strip_tags((string) preg_replace('/\e\[[0-9;]*m/', '', $sgr)), 'tty title: plain x');
        PanelPaint::assertGolden($this, 'ctr', 'tty-80x24.sgr.txt', $sgr);
    }

    public function testEmptyHostGolden(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu ctr proc');
        $layout = PanelPaint::layout(100, 30, $config, PanelPaint::host());
        $panel = $this->feed(CtrPanel::new(FakeProcList::new(8), FakeContainers::new()), $config, $layout, 2, FakeProcList::new(8));
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, PanelPaint::host()), $layout->box('ctr') ?? Rect::new(0, 0, 0, 0));
        $this->assertStringContainsString('No containers found', $grid);
        $this->assertStringContainsString('0/0', $grid);
        PanelPaint::assertGolden($this, 'ctr', 'empty-100x30.txt', $grid);
    }

    /** @return array{0: CtrPanel, 1: PanelContext} */
    private function sampled(int $cols, int $rows): array
    {
        $config = Config::new()->with('shown_boxes', self::BOXES);
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = $this->feed(CtrPanel::standard(8, true), $config, $layout, 2);

        return [$panel, new PanelContext($config, $layout, $layout->box('ctr'))];
    }

    /**
     * Sample `$n` times as the App would: through the ctr box's own scan
     * when proc is hidden, else by tapping a proc scan of the same fleet.
     */
    private function feed(CtrPanel $panel, Config $config, Layout $layout, int $n, ?FakeProcList $procs = null): CtrPanel
    {
        $procs ??= FakeProcList::demo(8)->withContainers();
        for ($i = 0; $i < $n; $i++) {
            $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
            $cmd = $panel->collect($ctx);
            if ($cmd === null) {
                [$snapshot, $procs] = $procs->sample();
                $cmd = $panel->update(new SampledMsg('proc', $snapshot, $procs), $ctx)->cmd;
            }
            $this->assertNotNull($cmd);
            $msg = $cmd();
            $this->assertInstanceOf(SampledMsg::class, $msg);
            $next = $panel->update($msg, $ctx)->panel;
            $this->assertInstanceOf(CtrPanel::class, $next);
            $panel = $next;
        }

        return $panel;
    }

    /** @param array<string, bool|int|string> $options */
    private function grid(int $cols, int $rows, string $boxes, string $selected = '', bool $sgr = false, array $options = [], bool $tty = false): string
    {
        $config = Config::new()->with('shown_boxes', $boxes);
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = $this->feed(CtrPanel::standard(8, true), $config, $layout, 3);
        $config = $config->with(Schema::CTR_SELECTED, $selected);
        $surface = PanelPaint::surface($panel, $config, $layout, PanelPaint::host(), $tty ? TtyTheme::new() : null, $tty ? ColorProfile::Ansi : ColorProfile::TrueColor);
        $rect = $layout->box('ctr');
        $this->assertNotNull($rect);

        return $sgr ? PanelPaint::sgr($surface, $rect) : PanelPaint::grid($surface, $rect);
    }

    private static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x + 1, $y + 1, MouseButton::Left, MouseAction::Press);
    }
}
