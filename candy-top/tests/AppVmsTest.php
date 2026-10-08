<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Panel\CtrPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Panel\VmsPanel;
use SugarCraft\Top\Panel\Vms\VmsView;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\VmsMode;

/**
 * The VM dashboard end to end through the App: the takeover layout, `v`
 * and the title button, sampling only what is laid out, leaving the
 * dashboard (toggle, eclipsed-box keys, Enter into the filtered proc box),
 * presets and the size rule.
 */
final class AppVmsTest extends TestCase
{
    private const WEB01 = '/machine.slice/machine-qemu\x2d1\x2dweb01.scope';

    protected function setUp(): void
    {
        T::reset();
    }

    public function testTheDashboardTakesEverythingBelowTheCpuBox(): void
    {
        $plain = FrameBuilder::layout(120, 40, Config::new(), 8);
        $layout = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu mem net proc vms'), 8);
        $cpu = $plain->box('cpu');
        $this->assertNotNull($cpu);
        $this->assertSame(['cpu', 'vms'], array_keys($layout->ordered()), 'mem, net and proc are eclipsed');
        // The cpu box keeps its place and width but only the height its cores
        // need (8 cores in 3 compact columns: 3 rows + 4 inside a 9-row box).
        $this->assertTrue($layout->box('cpu')?->equals(Rect::new($cpu->x, $cpu->y, $cpu->width, 9)));
        $this->assertSame(9, FrameBuilder::vmsCpuHeight(120, 8, 0));
        $this->assertSame((int) ceil(8 / $layout->coreColumns) + 4, $layout->cpuCores?->height, 'every core row is inside the box');
        $this->assertSame(14, FrameBuilder::vmsCpuHeight(200, 48, 1), 'kvm521: 48 cores with temps');
        $this->assertTrue($layout->box('vms')?->equals(Rect::new(0, 9, 120, 31)));
        $wide = FrameBuilder::layout(200, 60, Config::new()->with('shown_boxes', 'cpu vms'), 48, true);
        $this->assertSame(14, $wide->box('cpu')?->height);
        $this->assertSame(1, $wide->coreColumnSize, 'compact core columns, every core in view');
        $this->assertSame(0, $layout->procSelectMax);

        $bottom = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu vms')->with('cpu_bottom', true), 8);
        $this->assertSame(0, $bottom->box('vms')?->y, 'cpu_bottom: the dashboard on top');
        $this->assertSame(40, ($bottom->box('vms')?->height ?? 0) + ($bottom->box('cpu')?->height ?? 0));

        $alone = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'vms'), 8);
        $this->assertTrue($alone->box('vms')?->equals(Rect::new(0, 0, 120, 40)));

        // The gpu grid stays between the cpu box and the dashboard.
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu gpu0 vms'));
        $gpu = $app->layout?->box('gpu0');
        $vms = $app->layout?->box('vms');
        $this->assertNotNull($gpu);
        $this->assertSame($gpu->bottom(), $vms?->y);
        $this->assertSame(40, $vms->bottom());
    }

    public function testTheCpuBoxGpuRowsCountTowardTheDashboardsMinimum(): void
    {
        $app = self::app(80, 30, config: Config::new()->with('shown_boxes', 'cpu vms'));
        $roster = $app->roster();
        $this->assertSame(2, $roster->gpuCount());
        $this->assertSame([60, 18], FrameBuilder::minSize(['cpu', 'vms'], 80, $roster), 'Auto: both GPUs ride in the cpu box');
        $this->assertSame([60, 16], FrameBuilder::minSize(['cpu', 'vms'], 80, $roster, null, 'Off'));
        $this->assertSame([60, 18], FrameBuilder::minSize(['cpu', 'vms'], 80, $roster, null, 'On'));
        $this->assertSame([80, 24], FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc'], 80, $roster), 'btop parity without the dashboard');

        // 80x16 would leave the dashboard 4 rows: the size notice instead.
        [$small] = $app->update(new WindowSizeMsg(80, 16));
        $this->assertStringContainsString('Terminal size too small', implode("\n", $small->surface()?->plainLines() ?? []));
        // `v` works behind the notice, so the dashboard can be left from there.
        [$left] = $small->update(new KeyMsg(KeyType::Char, 'v'));
        $this->assertSame(['cpu'], $left->config->shownBoxes());
        $this->assertStringNotContainsString('Terminal size too small', implode("\n", $left->surface()?->plainLines() ?? []));

        // At its minimum a row of cards draws and the range is real.
        [$fits] = $app->update(new WindowSizeMsg(80, 18));
        $text = implode("\n", $fits->surface()?->plainLines() ?? []);
        $this->assertStringContainsString('CPU', substr($text, (int) strpos($text, 'vms')));
        $this->assertStringNotContainsString('1-0/', $text);
        $this->assertMatchesRegularExpression('#1-\d+/7#', $text);
    }

    public function testProcWidthKeysDoNothingBehindTheDashboard(): void
    {
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc vms'));
        foreach ([new KeyMsg(KeyType::Left, shift: true), new KeyMsg(KeyType::Right, shift: true, alt: true), new KeyMsg(KeyType::Down, shift: true, ctrl: true)] as $key) {
            [$after, $cmd] = $app->update($key);
            $this->assertSame(55, $after->config->procBoxWidthPercent(), 'the hidden proc box is not resized');
            $this->assertFalse($after->writeNew, 'nothing to save');
            $this->assertNull($cmd);
        }
        [$off] = $app->update(new KeyMsg(KeyType::Char, 'v'));
        [$moved] = $off->update(new KeyMsg(KeyType::Left, shift: true));
        $this->assertNotSame(55, $moved->config->procBoxWidthPercent(), 'and works again once the dashboard is gone');
    }

    public function testMinimumSizeCountsOnlyWhatIsLaidOut(): void
    {
        $this->assertSame([60, 16], FrameBuilder::minSize(['cpu', 'vms']));
        $this->assertSame([60, 16], FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc', 'ctr', 'vms']), 'eclipsed boxes need no room');
        $this->assertSame([36, 8], FrameBuilder::minSize(['vms']));
        $this->assertSame(['cpu', 'vms'], VmsMode::effective(['cpu', 'mem', 'net', 'proc', 'ctr', 'vms']));
        $this->assertSame(['cpu', 'mem'], VmsMode::effective(['cpu', 'mem']));
        $this->assertSame(['cpu', 'mem', 'proc', 'ctr'], VmsMode::leaving(['cpu', 'mem', 'vms'], ['proc', 'ctr']));
    }

    public function testVTogglesAndOnlyLaidOutBoxesAreSampled(): void
    {
        $app = self::app();
        $this->assertNotContains('vms', self::ticked($app), 'hidden: never sampled');

        [$on, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'v'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc', 'vms'], $on->config->shownBoxes(), 'the eclipsed boxes stay listed');
        $this->assertSame(['cpu', 'vms'], array_keys($on->layout?->ordered() ?? []));
        $this->assertSame(['vms'], self::boxes($cmd), 'opened: the dashboard is sampled at once');
        $on = self::settle($on, $cmd);
        $this->assertSame(7, ($on->panel('vms') instanceof VmsPanel ? $on->panel('vms')->snapshot()?->count() : null));
        $ticked = self::ticked($on);
        $this->assertContains('vms', $ticked);
        foreach (['mem', 'net', 'proc', 'ctr'] as $box) {
            $this->assertNotContains($box, $ticked, "{$box} is hidden by the dashboard: no sampling");
        }
        $this->assertTrue($on->writeNew, 'a toggle is a config change, saved on exit');

        [$off, $back] = $on->update(new KeyMsg(KeyType::Char, 'v'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $off->config->shownBoxes());
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], array_keys($off->layout?->ordered() ?? []), 'the previous boxes come back');
        $this->assertSame(['mem', 'net', 'proc'], self::boxes($back), 'and are sampled at once');
    }

    public function testAnEclipsedBoxKeyLeavesTheDashboard(): void
    {
        $on = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc vms'));
        [$four] = $on->update(new KeyMsg(KeyType::Char, '4'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $four->config->shownBoxes(), '4 shows proc instead of hiding it unseen');
        [$x, $cmd] = $on->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc', 'ctr'], $x->config->shownBoxes());
        $this->assertContains('proc', self::boxes($cmd), 'the boxes it hid are sampled');
        [$one] = $on->update(new KeyMsg(KeyType::Char, '1'));
        $this->assertSame(['mem', 'net', 'proc', 'vms'], $one->config->shownBoxes(), 'cpu is not eclipsed: a plain toggle');
        $this->assertSame(['vms'], array_keys($one->layout?->ordered() ?? []));
    }

    public function testEnterOpensTheGuestInTheProcBox(): void
    {
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc vms'));
        $panel = $app->panel('vms');
        $this->assertInstanceOf(VmsPanel::class, $panel);
        $target = VmsView::indexOf($panel->ordered($app->config), self::WEB01);
        $this->assertGreaterThanOrEqual(0, $target);
        [$app] = $app->update(new KeyMsg(KeyType::Home));
        for ($i = 0; $i < $target; $i++) {
            [$app] = $app->update(new KeyMsg(KeyType::Right));
        }
        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $this->assertSame(['cpu', 'mem', 'net', 'proc', 'ctr'], $app->config->shownBoxes());
        $this->assertSame(self::WEB01, $app->config->string(Schema::CTR_SELECTED));
        $this->assertSame(['cpu', 'mem', 'net', 'ctr', 'proc'], array_keys($app->layout?->ordered() ?? []));
        $app = self::settle($app, $cmd);
        $this->assertSame(self::WEB01, $app->config->string(Schema::CTR_SELECTED), 'the ctr sample keeps the pick: the guest is listed');
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $pids = array_map(static fn ($e): int => $e->process->pid, $proc->rows($app->config));
        $this->assertSame([6969], $pids, 'only web01\'s qemu process');
        $this->assertFalse($app->config->persistedDiffers($app->config->with(Schema::CTR_SELECTED, '')), 'the pick is runtime only');
    }

    public function testEnterFiltersEvenWhenTheCtrBoxListsNoVms(): void
    {
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net proc vms')->with('ctr_show_vms', false));
        $panel = $app->panel('vms');
        $this->assertInstanceOf(VmsPanel::class, $panel);
        $target = VmsView::indexOf($panel->ordered($app->config), self::WEB01);
        [$app] = $app->update(new KeyMsg(KeyType::Home));
        for ($i = 0; $i < $target; $i++) {
            [$app] = $app->update(new KeyMsg(KeyType::Right));
        }
        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Enter));
        $app = self::settle($app, $cmd);
        [, $tick] = $app->update(new DataTickMsg($app->generation));
        $app = self::settle($app, $tick);
        $this->assertFalse($app->config->bool('ctr_show_vms'), 'the user\'s choice is not overwritten');
        $this->assertSame(self::WEB01, $app->config->string(Schema::CTR_SELECTED), 'the pick survives the ctr box not listing VMs');
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame([6969], array_map(static fn ($e): int => $e->process->pid, $proc->rows($app->config)));
        $this->assertStringContainsString('proc filter: kvm:web01', implode("\n", $app->surface()?->plainLines() ?? []), 'the hidden pick is on screen');
    }

    public function testAHiddenVmPickIsDroppedOnceTheGuestIsGone(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu ctr proc')->with('ctr_show_vms', false)->with(Schema::CTR_SELECTED, self::WEB01);
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $ctx = new PanelContext($config, $layout, $layout->box('ctr'));
        $ctr = CtrPanel::standard(8, true);
        [$alive] = FakeProcList::demo(8)->withContainers()->sample();
        $this->assertSame([], $ctr->update(new SampledMsg('proc', $alive, FakeProcList::new(8)), $ctx)->set, 'web01 still runs');
        $this->assertSame([Schema::CTR_SELECTED => ''], $ctr->update(new SampledMsg('proc', new ProcSnapshot([], 1), FakeProcList::new(8)), $ctx)->set, 'web01 is gone');
        $listed = new PanelContext($config->with('ctr_show_vms', true), $layout, $layout->box('ctr'));
        $this->assertSame([], $ctr->update(new SampledMsg('proc', new ProcSnapshot([], 1), FakeProcList::new(8)), $listed)->set, 'listed VMs: the ctr sample decides, as for containers');
    }

    public function testTheTitleButtonOnVmHosts(): void
    {
        $plain = self::app();
        $this->assertArrayNotHasKey('v', $plain->chromeButtons(), 'no VMs on this host: no button');

        $app = self::app(host: Harness::host()->withVmHost(true));
        $zone = $app->chromeButtons()['v'] ?? null;
        $this->assertNotNull($zone);
        $ctr = $app->chromeButtons()['x'];
        $this->assertSame($ctr[0] + $ctr[2] + 2, $zone[0], 'right after `x ctr`');
        $row = $app->surface()?->plainLines()[0] ?? '';
        $this->assertStringContainsString('┐vms┌', $row);
        [$on] = $app->update(new MouseMsg($zone[0] + 1, $zone[1] + 1, MouseButton::Left, MouseAction::Press));
        $this->assertContains('vms', $on->config->shownBoxes());
        // Too narrow for it before the clock: not drawn, not mapped.
        $narrow = self::app(80, 24, host: Harness::host()->withVmHost(true));
        $this->assertArrayNotHasKey('v', $narrow->chromeButtons());
        // Shown on any host while the dashboard is up, so it can be clicked away.
        $shown = self::app(config: Config::new()->with('shown_boxes', 'cpu vms'));
        $this->assertArrayHasKey('v', $shown->chromeButtons());
    }

    public function testPresetsAndTheSizeRule(): void
    {
        $app = self::app(config: Config::new()->with('presets', 'cpu:0:braille,vms:0:block'));
        [$zero] = $app->update(new KeyMsg(KeyType::Char, 'p'));
        [$preset] = $zero->update(new KeyMsg(KeyType::Char, 'p'));
        $this->assertSame(1, $preset->preset);
        $this->assertSame(['cpu', 'vms'], $preset->config->shownBoxes());
        $this->assertSame('braille', $preset->config->string('graph_symbol_cpu'));

        // 60x15 fits cpu alone (60x8) but not cpu + vms (60x16): refused with btop's size box.
        $small = self::app(60, 15, config: Config::new()->with('shown_boxes', 'cpu'));
        [$refused] = $small->update(new KeyMsg(KeyType::Char, 'v'));
        $this->assertSame(['cpu'], $refused->config->shownBoxes());
        $this->assertNotNull($refused->overlay());
    }

    public function testHostDetection(): void
    {
        $root = sys_get_temp_dir() . '/candy-top-vmhost-' . getmypid();
        @mkdir($root . '/run/libvirt/qemu', 0777, true);
        try {
            $this->assertTrue(HostInfo::detect(Paths::under($root))->vmHost);
            $this->assertFalse(HostInfo::detect(Paths::under($root . '/none'))->vmHost);
            $this->assertTrue(HostInfo::new('x')->withVmHost(true)->vmHost);
            $this->assertSame('x', HostInfo::new('x')->withVmHost(true)->cpuName);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /** The boxes a Cmd samples, sorted. */
    private static function boxes(?\Closure $cmd): array
    {
        $boxes = array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $cmd));
        sort($boxes);

        return array_values(array_unique($boxes));
    }

    /** The boxes one data tick samples. */
    private static function ticked(App $app): array
    {
        [, $cmd] = $app->update(new DataTickMsg($app->generation));

        return self::boxes($cmd);
    }

    private static function app(int $cols = 120, int $rows = 40, ?Config $config = null, ?HostInfo $host = null): App
    {
        $config ??= Config::new();
        $host ??= Harness::host();
        $panels = Panels::standard($host, $config, true);
        $app = App::start($config, ThemeConfig::new(), $host, $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));

        return self::settle($app, $app->init());
    }

    private static function settle(App $app, ?\Closure $cmd): App
    {
        foreach (Cmds::run($cmd) as $m) {
            if (!$m instanceof TickRequest) {
                [$app, $next] = $app->update($m);
                $app = self::settle($app, $next);
            }
        }

        return $app;
    }
}
