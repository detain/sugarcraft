<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Collect\Containers;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Presets;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\DataTickMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Panel\CtrPanel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Panel\Proc\ProcEntry;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;

/**
 * btop PR #1873 end to end through the App: the `x` toggle, the proc
 * column split, the proc tap, the selection filtering the proc list, and
 * the config law (shown_boxes / presets / runtime ctr_selected).
 */
final class AppCtrTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    public function testLayoutSplitsTheProcColumn(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu mem net ctr proc');
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $plain = FrameBuilder::layout(120, 40, Config::new(), 8);
        $proc = $plain->box('proc');
        $this->assertNotNull($proc);
        // btop: Ctr::height = clamp(height / 3, 6, max(6, height - 16)) at the column's top.
        $h = intdiv($proc->height, 3);
        $this->assertTrue($layout->box('ctr')?->equals(Rect::new($proc->x, $proc->y, $proc->width, $h)));
        $this->assertTrue($layout->box('proc')?->equals(Rect::new($proc->x, $proc->y + $h, $proc->width, $proc->height - $h)));
        $this->assertSame($proc->height - $h - 3, $layout->procSelectMax);
        foreach (['cpu', 'mem', 'net'] as $box) {
            $this->assertTrue($layout->box($box)?->equals($plain->box($box) ?? Rect::new(0, 0, 0, 0)), "{$box} does not move");
        }
        $this->assertSame(['cpu', 'mem', 'net', 'ctr', 'proc'], array_keys($layout->ordered()), 'btop draws ctr before proc');

        // proc keeps its 16 rows: 30 rows → column 20 → ctr 6 (20/3), never more than 20 - 16 = 4 → 6 floor.
        $small = FrameBuilder::layout(80, 30, Config::new()->with('shown_boxes', 'cpu ctr proc'), 8);
        $this->assertSame([6, 14], [$small->box('ctr')?->height, $small->box('proc')?->height]);

        // ctr alone takes the column (and the side boxes still leave room for it, proc_left included).
        $alone = FrameBuilder::layout(120, 40, Config::new()->with('shown_boxes', 'cpu mem ctr')->with('proc_left', true), 8);
        $this->assertNull($alone->box('proc'));
        $this->assertSame(0, $alone->procSelectMax);
        $this->assertSame([0, 66], [$alone->box('ctr')?->x, $alone->box('mem')?->x], 'proc_left mirrors the ctr column');
        $this->assertSame($alone->height - ($alone->box('cpu')?->height ?? 0), $alone->box('ctr')?->height);
    }

    public function testMinimumSize(): void
    {
        // btop PR #1873 Term::get_min_size.
        $this->assertSame([80, 30], FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc', 'ctr']));
        $this->assertSame([80, 24], FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc']), 'unchanged without ctr');
        $this->assertSame([60, 14], FrameBuilder::minSize(['cpu', 'ctr']), 'ctr alone: 44 wide, 6 tall');
        $this->assertSame([80, 24], FrameBuilder::minSize(['cpu', 'mem', 'net', 'ctr']), 'max(ctr, mem + net) beside');
        $this->assertSame([44, 6], FrameBuilder::minSize(['ctr']));
    }

    public function testXTogglesTheBoxAndSamplesItAtOnce(): void
    {
        $app = self::app();
        $this->assertNull($app->layout?->box('ctr'));
        [$on, $cmd] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc', 'ctr'], $on->config->shownBoxes());
        $this->assertNotNull($on->layout?->box('ctr'));
        $msgs = Cmds::of(SampledMsg::class, $cmd);
        $this->assertSame(['proc'], array_map(static fn (SampledMsg $m): string => $m->box, $msgs), 'toggled on beside proc: proc rescans at once and the tap fills ctr — no second /proc scan');
        $on = self::settle($on, $cmd);
        $ctr = $on->panel('ctr');
        $this->assertInstanceOf(CtrPanel::class, $ctr);
        $this->assertSame(7, $ctr->snapshot()?->count(), 'six containers and the demo libvirt guest');

        [$on] = $on->update(new KeyMsg(KeyType::Char, ']'));
        $this->assertNotSame('', $on->config->string(Schema::CTR_SELECTED));
        [$off] = $on->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNotContains('ctr', $off->config->shownBoxes());
        $this->assertSame('', $off->config->string(Schema::CTR_SELECTED), 'btop calcSizes: hiding the box clears the selection');
        $this->assertFalse($off->config->persistedDiffers($off->config->with(Schema::CTR_SELECTED, '')), 'runtime only');

        // Without the proc box the ctr box scans for itself.
        $lone = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net'));
        [, $own] = $lone->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertSame(['ctr'], array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $own)));

        // Behind the size notice btop's resize loop reads only 1-4.
        $tiny = self::app(70, 20);
        [$still] = $tiny->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNotContains('ctr', $still->config->shownBoxes());
        // A toggle that would not fit opens the size-error box.
        [$refused] = self::app(80, 24)->update(new KeyMsg(KeyType::Char, 'x'));
        $this->assertNotContains('ctr', $refused->config->shownBoxes());
        $this->assertNotNull($refused->overlay());
    }

    public function testAContainerEngineReplacesTheCtrButton(): void
    {
        $host = HostInfo::new('Ryzen 7 5800X', 8, 'joe', 'box', containerEngine: 'docker');
        $config = Config::new();
        $app = App::start($config, ThemeConfig::new(), $host, Panels::standard($host, $config, true), static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        $app = self::settle($app, $app->init());
        $cpu = $app->layout?->box('cpu');
        $this->assertNotNull($cpu);
        $top = $app->surface()?->plainLines()[$cpu->y] ?? '';
        // btop Cpu::draw: the engine name in the title colour where `x ctr` would be.
        $this->assertSame('docker', mb_substr($top, $cpu->x + 27, 6));
        $this->assertStringNotContainsString('ctr', $top);
        $this->assertSame([$cpu->x + 27, $cpu->y, 6, 1], $app->chromeButtons()['x'], 'candy-top keeps the label clickable (btop maps nothing)');
        [$on] = $app->update(new MouseMsg($cpu->x + 28, $cpu->y + 1, MouseButton::Left, MouseAction::Press));
        $this->assertContains('ctr', $on->config->shownBoxes(), 'a click on the label toggles the box like `x`');

        // Without an engine the btop button is unchanged.
        $plain = self::app();
        $this->assertSame([$cpu->x + 27, $cpu->y, 5, 1], $plain->chromeButtons()['x']);
        $this->assertStringContainsString('x ctr', $plain->surface()?->plainLines()[$cpu->y] ?? '');
    }

    public function testALongClockCutsTheLabelInsteadOfPaintingOverIt(): void
    {
        // 54+ cells: clockBudget(120) = 54, so the clock's left junction lands at cpu.x + 33.
        // show_battery off: the badge's 22-cell clock reserve would shorten the clock.
        $config = Config::new()->with('clock_format', '%A %d %B %Y %X %A %d %B %Y %X')->with('show_battery', false);
        foreach (['docker' => 'docke', '' => 'x ctr'] as $engine => $drawn) {
            $host = HostInfo::new('Ryzen 7 5800X', 8, 'joe', 'box', containerEngine: $engine);
            $app = App::start($config, ThemeConfig::new(), $host, Panels::standard($host, $config, true), static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
            [$app] = $app->update(new WindowSizeMsg(120, 40));
            $app = self::settle($app, $app->init());
            [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
            $cpu = $app->layout?->box('cpu');
            $this->assertNotNull($cpu);
            $layout = $app->layout;
            $this->assertNotNull($layout);
            $width = FrameBuilder::clockWidth($layout, $app->clockText(), $app->clockReserved());
            $this->assertSame(54, $width, 'the clock is cut to its budget');
            $top = $app->surface()?->plainLines()[$cpu->y] ?? '';
            $clockAt = $cpu->x + intdiv($cpu->width, 2) - intdiv($width, 2);
            $this->assertSame($drawn, mb_substr($top, $cpu->x + 27, 5), "engine '{$engine}'");
            $this->assertNotSame(' ', mb_substr($top, $cpu->x + 32, 1), 'its closing junction is drawn, not overwritten');
            $this->assertSame(mb_substr(Width::truncate($app->clockText(), 54), 0, 10), mb_substr($top, $clockAt + 1, 10), 'the clock is intact');
            $this->assertSame([$cpu->x + 27, $cpu->y, 5, 1], $app->chromeButtons()['x'], 'the click zone covers exactly what is drawn');
        }
        $this->assertNull(FrameBuilder::engineLabel(Rect::new(0, 0, 120, 10), 'docker', 60), 'a clock past its budget would leave no room at all');
        $this->assertNull(FrameBuilder::ctrZone(Rect::new(0, 0, 120, 10), 0, '', 60));
        $this->assertSame('containerd', FrameBuilder::engineLabel(Rect::new(0, 0, 76, 10), 'containerd', 0), 'no clock yet: the room runs to the interval button');
    }

    public function testTheEngineLabelIsCutToTheRoomBeforeTheClock(): void
    {
        $cpu = Rect::new(0, 0, 76, 10);
        $this->assertSame('docker', FrameBuilder::engineLabel($cpu, 'docker'), '76 wide: 6 cells before a centred 8-cell clock');
        $this->assertSame('contai', FrameBuilder::engineLabel($cpu, 'containerd'));
        $this->assertSame('containerd', FrameBuilder::engineLabel(Rect::new(0, 0, 120, 10), 'containerd'));
        $this->assertSame('doc', FrameBuilder::engineLabel(Rect::new(0, 0, 70, 10), 'docker'));
        $this->assertNull(FrameBuilder::engineLabel(Rect::new(0, 0, 68, 10), 'docker'), 'under 3 cells: not drawn');
        $this->assertNull(FrameBuilder::engineLabel($cpu, ''));
        $this->assertNull(FrameBuilder::ctrZone(Rect::new(0, 0, 68, 10), 0, 'docker'), 'no label, and no `x ctr` either while in a container');
        $this->assertNull(FrameBuilder::ctrZone(Rect::new(0, 0, 70, 10), 0, ''));
    }

    public function testTheProcSampleIsTappedOnlyWhileTheBoxIsShown(): void
    {
        $sample = new SampledMsg('proc', FakeProcList::demo(8)->withContainers()->sample()[0], FakeProcList::demo(8)->withContainers());
        [, $hidden] = self::app()->update($sample);
        $this->assertSame([], Cmds::of(SampledMsg::class, $hidden), 'no ctr box: the tap costs nothing');

        $shown = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net ctr proc'));
        [, $cmd] = $shown->update($sample);
        $this->assertSame(['ctr'], array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $cmd)));
        $data = Cmds::of(SampledMsg::class, $shown->update(new DataTickMsg($shown->generation))[1]);
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], array_map(static fn (SampledMsg $m): string => $m->box, $data), 'a data tick does not rescan for ctr while proc is shown');
        $alone = self::app(config: Config::new()->with('shown_boxes', 'cpu ctr'));
        $this->assertContains('ctr', array_map(static fn (SampledMsg $m): string => $m->box, Cmds::of(SampledMsg::class, $alone->update(new DataTickMsg($alone->generation))[1])), 'a lone ctr box scans itself');
    }

    public function testSelectingAContainerFiltersTheProcList(): void
    {
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net ctr proc'));
        // Select a proc row first: btop resets proc_selected / proc_start on a container pick.
        [$app] = $app->update(new KeyMsg(KeyType::Down));
        [$app] = $app->update(new KeyMsg(KeyType::Down));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(2, $proc->selection()->selected);
        $all = count($proc->rows($app->config));

        $path = '/lxc.payload.build';
        [$app] = $app->update(new SetOptionMsg(Schema::CTR_SELECTED, $path));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $rows = $proc->rows($app->config);
        $pids = array_map(static fn (ProcEntry $e): int => $e->pid(), $rows);
        sort($pids);
        $this->assertSame([7140, 7141], $pids, 'only the container\'s processes');
        $this->assertLessThan($all, count($rows));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'X'));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(0, $proc->selection()->selected, 'the pick reset the proc selection');

        // Tree view: the same filter.
        [$tree] = $app->update(new SetOptionMsg('proc_tree', true));
        $proc = $tree->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame([7140, 7141], array_map(static fn (ProcEntry $e): int => $e->pid(), $proc->rows($tree->config)));

        // The selection beats proc_filter_containers (btop ctr_hidden).
        [$both] = $app->update(new SetOptionMsg('proc_filter_containers', true));
        $proc = $both->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertCount(2, $proc->rows($both->config));
    }

    public function testBracketKeyAndRowClickDriveTheProcFilter(): void
    {
        $app = self::app(config: Config::new()->with('shown_boxes', 'cpu mem net ctr proc'));
        $ctr = $app->panel('ctr');
        $this->assertInstanceOf(CtrPanel::class, $ctr);
        $this->assertSame(7, $ctr->snapshot()?->count(), 'filled from the startup proc scan through the tap');
        [$app] = $app->update(new KeyMsg(KeyType::Down));
        [$app] = $app->update(new KeyMsg(KeyType::Down));

        // `]` from none: the first container by name (the k8s one, 9a0c…).
        [$app] = $app->update(new KeyMsg(KeyType::Char, ']'));
        // The proc panel applies the reset on whatever reaches it next (its paint or any update).
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'X'));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame([7130], array_map(static fn (ProcEntry $e): int => $e->pid(), $proc->rows($app->config)));
        $this->assertSame(0, $proc->selection()->selected, 'btop: proc_selected = 0 on a pick');

        // A click on the third row (build) through the App's click routing.
        $box = $app->layout?->box('ctr');
        $this->assertNotNull($box);
        [$app] = $app->update(new MouseMsg($box->x + 6, $box->y + 5, MouseButton::Left, MouseAction::Press));
        $this->assertSame('/lxc.payload.build', $app->config->string(Schema::CTR_SELECTED));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $pids = array_map(static fn (ProcEntry $e): int => $e->pid(), $proc->rows($app->config));
        sort($pids);
        $this->assertSame([7140, 7141], $pids);

        // Hiding the box clears the pick — and, like any pick change, resets the proc selection.
        [$app] = $app->update(new KeyMsg(KeyType::Down));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame('', $app->config->string(Schema::CTR_SELECTED));
        [$app] = $app->update(new KeyMsg(KeyType::Char, 'X'));
        $proc = $app->panel('proc');
        $this->assertInstanceOf(ProcPanel::class, $proc);
        $this->assertSame(0, $proc->selection()->selected);
    }

    public function testAQuickHideAndReopenCountsItsFirstSample(): void
    {
        // A frozen clock: without the rebase on reopen, every sample after the
        // first would fall inside update_ms / 2 and be skipped.
        $config = Config::new()->with('shown_boxes', 'cpu mem net ctr proc');
        $panels = Panels::standard(Harness::host(), $config, true);
        $panels['ctr'] = CtrPanel::new(FakeProcList::demo(8)->withContainers(), Containers::new(sys_get_temp_dir() . '/candy-top-no-cgroup', static fn (): string => '', static fn (): int => 42_000_000));
        $app = App::start($config, ThemeConfig::new(), Harness::host(), $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg(120, 40));
        $app = self::settle($app, $app->init());
        $ctr = $app->panel('ctr');
        $this->assertInstanceOf(CtrPanel::class, $ctr);
        $this->assertSame(7, $ctr->snapshot()?->count(), 'the first sample counts');
        $sample = new SampledMsg('proc', FakeProcList::demo(8)->withContainers()->sample()[0], FakeProcList::demo(8));
        [, $again] = $app->update($sample);
        $this->assertSame([], Cmds::of(SampledMsg::class, $again), 'a second sample in the same instant is skipped');

        [$off] = $app->update(new KeyMsg(KeyType::Char, 'x'));
        [$on, $cmd] = $off->update(new KeyMsg(KeyType::Char, 'x'));
        $proc = Cmds::of(SampledMsg::class, $cmd);
        $this->assertSame(['proc'], array_map(static fn (SampledMsg $m): string => $m->box, $proc), 'reopening samples proc at once');
        [$on, $tapCmd] = $on->update($proc[0]);
        $counted = Cmds::of(SampledMsg::class, $tapCmd);
        $this->assertSame(['ctr'], array_map(static fn (SampledMsg $m): string => $m->box, $counted), 'the same instant, but the reopened box counts its first sample');
        [$on] = $on->update($counted[0]);
        [, $tap] = $on->update($sample);
        $this->assertSame([], Cmds::of(SampledMsg::class, $tap), 'after that the window applies again');
    }

    public function testConfigLaw(): void
    {
        $config = Config::new()->with('shown_boxes', 'ctr cpu');
        $this->assertSame(['ctr', 'cpu'], $config->shownBoxes());
        $this->assertSame('', $config->with(Schema::CTR_SELECTED, '/x')->with('shown_boxes', 'cpu')->string(Schema::CTR_SELECTED));
        $this->assertSame('', Config::new()->with(Schema::CTR_SELECTED, '/x')->string(Schema::CTR_SELECTED), 'no ctr box: nothing to select');
        $this->assertNotContains(Schema::CTR_SELECTED, Schema::persistedNames());

        // A preset may name ctr; its position and graph symbol are ignored (btop apply_preset).
        $preset = Presets::parse('cpu:0:default,ctr:1:tty,proc:0:block')->at(1);
        $applied = Config::new()->withPreset($preset);
        $this->assertSame(['cpu', 'ctr', 'proc'], $applied->shownBoxes());
        $this->assertSame('block', $applied->string('graph_symbol_proc'));
        $this->assertSame('default', $applied->string('graph_symbol_cpu'));
        $this->assertFalse($applied->bool('proc_left'));
    }

    private static function app(int $cols = 120, int $rows = 40, ?Config $config = null): App
    {
        $config ??= Config::new();
        $app = App::start($config, ThemeConfig::new(), Harness::host(), Panels::standard(Harness::host(), $config, true), static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));

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
