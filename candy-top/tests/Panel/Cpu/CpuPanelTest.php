<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Cpu;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\FreqSnapshot;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Cpu\CpuView;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\Gfx\NamedSources;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeFreq;
use SugarCraft\Top\Source\Fake\FakeTemp;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Panel\Gfx\RecordingBadge;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class CpuPanelTest extends TestCase
{
    private static function fake(int $cores = 8, bool $temps = true): CpuPanel
    {
        return CpuPanel::new(FakeCpu::new($cores, 2.0), FakeFreq::new($cores), $temps ? FakeTemp::new(intdiv($cores, 2)) : null);
    }

    private static function snap(float $total, array $cores = [10.0, 20.0], array $load = [1.0, 2.0, 3.0], float $uptime = 100.0): CpuSnapshot
    {
        $fields = array_fill_keys(Cpu::FIELDS, $total < 0 ? Sentinel::UNMEASURED : 1.0);

        return new CpuSnapshot($total, $cores, $fields, $load, $uptime);
    }

    // ---- roster / seam -------------------------------------------------

    public function testStandardRosterUsesTheCpuPanel(): void
    {
        foreach ([true, false] as $fake) {
            $panel = Panels::standard(PanelPaint::host(), Config::new(), $fake)['cpu'];
            $this->assertInstanceOf(CpuPanel::class, $panel);
            $this->assertSame('cpu', $panel->box());
        }
    }

    public function testNeverModalAndClaimsNoKeys(): void
    {
        $panel = self::fake();
        $ctx = PanelPaint::context('cpu', Config::new(), null);
        $this->assertFalse($panel->modal($ctx));
        foreach (['+', '-', '=', 'd', 'i', 'q', 'm'] as $key) {
            $this->assertFalse($panel->capturesKey(new KeyMsg(KeyType::Char, $key), $ctx), $key);
        }
        $result = $panel->update(new KeyMsg(KeyType::Char, '+'), $ctx);
        $this->assertSame($panel, $result->panel);
        $this->assertSame([], $result->set);
    }

    public function testCollectSamplesTheMembersTheConfigAsksFor(): void
    {
        $panel = CpuPanel::new(FakeCpu::new(2), FakeFreq::new(2), FakeTemp::new(1), new ScriptedSource([new GpuSnapshot([])]));
        $members = static function (Config $c) use ($panel): array {
            $msg = ($panel->collect(PanelPaint::context('cpu', $c, null)))();
            self::assertInstanceOf(SampledMsg::class, $msg);
            self::assertSame('cpu', $msg->box);
            self::assertInstanceOf(Samples::class, $msg->snapshot);

            return array_keys($msg->snapshot->snapshots);
        };
        $this->assertSame(['cpu', 'freq', 'temp', 'gpu'], $members(Config::new()));
        $this->assertSame(['cpu', 'freq', 'temp'], $members(Config::new()->with('show_gpu_info', 'Off')));
        $this->assertSame(['cpu', 'freq', 'gpu'], $members(Config::new()->with('check_temp', false)));
        $this->assertSame(['cpu', 'temp', 'gpu'], $members(Config::new()->with('show_cpu_freq', false)));
        // Per-core freq still needs the freq collector with show_cpu_freq off.
        $this->assertSame(['cpu', 'freq', 'temp', 'gpu'], $members(Config::new()->with('show_cpu_freq', false)->with('show_core_freq', 'value')));
    }

    public function testCollectRetunesPerCoreFreqFromTheCurrentConfig(): void
    {
        $panel = self::fake(4);
        $off = ($panel->collect(PanelPaint::context('cpu', Config::new(), null)))();
        $this->assertSame([], $off->snapshot->get('freq')->perCore);
        $on = ($panel->collect(PanelPaint::context('cpu', Config::new()->with('show_core_freq', 'graph'), null)))();
        $this->assertCount(4, $on->snapshot->get('freq')->perCore);
        $this->assertInstanceOf(NamedSources::class, $on->next);
        $this->assertInstanceOf(FakeFreq::class, $on->next->get('freq'));
        $this->assertTrue($on->next->get('freq')->perCore());
    }

    public function testUpdateFoldsSamplesIntoHistoryImmutably(): void
    {
        $panel = self::fake(4);
        $ctx = PanelPaint::context('cpu', Config::new(), null);
        $next = PanelPaint::feed($panel, Config::new(), null, 3);
        $this->assertInstanceOf(CpuPanel::class, $next);
        $this->assertFalse($panel->sampled(), 'update never mutates');
        $this->assertTrue($next->sampled());
        $this->assertCount(3, $next->history()->series('total'));
        $this->assertCount(3, $next->history()->series('core:3'));
        $this->assertSame(4, $next->coreCount());
        $this->assertNotNull($next->freq());
        $this->assertNotNull($next->temp());
        $this->assertGreaterThan(86_400.0, $next->uptime());
        $this->assertContains('user', $next->graphFields());

        $this->assertSame($next, $next->update(new FocusGainedMsg(), $ctx)->panel);
        [$mem, $src] = [MemorySnapshot::unmeasured(), new ScriptedSource([MemorySnapshot::unmeasured()])];
        $this->assertSame($next, $next->update(new SampledMsg('mem', $mem, $src), $ctx)->panel);
        $this->assertSame($next, $next->update(new WindowSizeMsg(80, 24), $ctx)->panel);
    }

    /** btop #1008: an UNMEASURED read holds the last value — no dip, no spike, no n/a. */
    public function testUnmeasuredSampleHoldsTheLastGoodValue(): void
    {
        $config = Config::new()->with('check_temp', false);
        $source = new ScriptedSource([
            self::snap(40.0, [30.0, 50.0]),
            CpuSnapshot::unmeasured([Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED], Sentinel::UNMEASURED),
            self::snap(Sentinel::UNMEASURED, [Sentinel::UNMEASURED, 70.0], [Sentinel::UNMEASURED, 2.5, Sentinel::UNMEASURED]),
        ]);
        $layout = PanelPaint::layout(80, 24, $config, PanelPaint::host(false, 2));
        $panel = PanelPaint::feed(CpuPanel::new($source), $config, $layout, 3);
        $this->assertSame([40, 40, 40], $panel->history()->series('total'));
        $this->assertSame([30, 30], $panel->history()->series('core:0'), 'empty core list skips, unmeasured core repeats');
        $this->assertSame([50, 70], $panel->history()->series('core:1'));
        $this->assertSame([1.0, 2.5, 3.0], $panel->load());
        $this->assertSame(100.0, $panel->uptime());
        $this->assertFalse($panel->inactive(0), 'measured once: an UNMEASURED read keeps it active');
        $this->assertFalse($panel->inactive(1));

        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, PanelPaint::host(false, 2)), $layout->box('cpu'));
        $this->assertStringContainsString('40%', $grid);
        $this->assertStringContainsString('1.00 2.50 3.00', $grid);
        $this->assertStringNotContainsString('n/a', $grid);
    }

    public function testNeverMeasuredValuesStayEmptyAndLoadPrintsUnavailable(): void
    {
        $config = Config::new()->with('check_temp', false);
        $host = PanelPaint::host(false, 2);
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $panel = PanelPaint::feed(CpuPanel::new(new ScriptedSource([CpuSnapshot::unmeasured([-1.0, -1.0, -1.0], -1.0)])), $config, $layout, 2);
        $this->assertSame([], $panel->history()->series('total'));
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $this->assertStringContainsString('Load avg: n/a n/a n/a', $grid);
        $this->assertStringNotContainsString(' up ', $grid);
    }

    public function testInactiveCoreIsDimmed(): void
    {
        $config = Config::new()->with('check_temp', false);
        $host = PanelPaint::host(false, 2);
        $layout = PanelPaint::layout(80, 24, $config, $host);
        // Core 0 never measured (offline since start) -> dimmed.
        $panel = PanelPaint::feed(CpuPanel::new(new ScriptedSource([self::snap(40.0, [Sentinel::UNMEASURED, 50.0]), self::snap(40.0, [Sentinel::UNMEASURED, 50.0])])), $config, $layout, 2);
        $surface = PanelPaint::surface($panel, $config, $layout, $host);
        $ink = Ink::new(ThemeConfig::new());
        $c = $layout->cpuCores;
        [$x0, $y0] = [$c->x + 1, $c->y + 2];
        $this->assertSame('C', $surface->glyph($x0, $y0));
        $this->assertSame(Surface::canonical($ink->fg('inactive_fg') . "\x1b[1m"), $surface->style($x0, $y0), 'C0 inactive');
        $x1 = mb_strpos($surface->plainLines()[$y0], 'C1');
        $this->assertNotFalse($x1);
        $this->assertSame(Surface::canonical($ink->fg('main_fg') . "\x1b[1m"), $surface->style($x1, $y0), 'C1 active');
        $this->assertMatchesRegularExpression('/C0 .*  0%/u', implode("\n", $surface->plainLines()), 'never measured: 0, not n/a');

        // A fast resample (every core Δ=0) must not dim the grid.
        $fast = PanelPaint::feed(CpuPanel::new(new ScriptedSource([self::snap(40.0, [30.0, 50.0]), self::snap(Sentinel::UNMEASURED, [Sentinel::UNMEASURED, Sentinel::UNMEASURED])])), $config, $layout, 2);
        $this->assertFalse($fast->inactive(0));
        $this->assertFalse($fast->inactive(1));
        $this->assertSame([30, 30], $fast->history()->series('core:0'));
    }

    public function testHistoriesAreCapped(): void
    {
        $config = Config::new();
        $layout = PanelPaint::layout(60, 20, $config->with('shown_boxes', 'cpu'), PanelPaint::host());
        $panel = PanelPaint::feed(self::fake(2), $config, $layout, 150);
        $this->assertCount(120, $panel->history()->series('total'), 'btop width * 2');
        $this->assertCount(CpuPanel::CORE_HISTORY, $panel->history()->series('core:0'));
        $this->assertCount(CpuPanel::TEMP_HISTORY, $panel->history()->series('temp:cpu'));
    }

    // ---- battery seam ---------------------------------------------------

    public function testBatteryBadgeIsSampledAndPaintedThroughTheSeam(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $badge = new RecordingBadge(new ScriptedSource([(object) ['value' => 87], (object) ['value' => 86]]));
        $panel = self::fake()->withBattery($badge);
        $this->assertSame($badge, $panel->battery());
        $panel = PanelPaint::feed($panel, $config, $layout, 2);
        $this->assertInstanceOf(RecordingBadge::class, $panel->battery());
        $this->assertSame(86, $panel->battery()->last->value);
        $this->assertStringContainsString('BAT86', explode("\n", PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu')))[0]);

        // show_battery off: the badge's source() says null and nothing is sampled.
        $msg = ($panel->collect(PanelPaint::context('cpu', $config->with('show_battery', false), $layout)))();
        $this->assertNull($msg->snapshot->get('battery'));
        $this->assertNull(self::fake()->withBattery($badge)->withBattery(null)->battery());
    }

    // ---- geometry helpers -------------------------------------------------

    /** btop #1614: every per-GPU graph width >= 1, remainder to the last, one separator between. */
    #[DataProvider('gpuWidths')]
    public function testGpuGraphWidths(int $graphW, int $count, array $expected): void
    {
        $this->assertSame($expected, CpuView::gpuGraphWidths($graphW, $count));
    }

    /** @return iterable<string, array{int, int, list<array{int, int}>}> */
    public static function gpuWidths(): iterable
    {
        yield 'none' => [40, 0, []];
        yield 'one gpu takes it all' => [40, 1, [[1, 40]]];
        yield 'two with separator, remainder last' => [40, 2, [[1, 19], [21, 20]]];
        yield 'three' => [40, 3, [[1, 12], [14, 12], [27, 14]]];
        yield 'eight in a narrow box clamp to 1, remainder last' => [10, 8, [[1, 1], [3, 1], [5, 1], [7, 1], [9, 1], [11, 1], [13, 1], [15, 4]]];
        yield 'negative drawable' => [0, 3, [[1, 1], [3, 1], [5, 1]]];
    }

    public function testFreqFootprintDegradesInsteadOfOverflowing(): void
    {
        $this->assertSame(['off', 0], CpuView::freqFootprint('off', 30));
        $this->assertSame(['graph', 12], CpuView::freqFootprint('graph', 22));
        $this->assertSame(['graph', 12], CpuView::freqFootprint('graph', 12));
        $this->assertSame(['value', 6], CpuView::freqFootprint('graph', 11));
        $this->assertSame(['value', 6], CpuView::freqFootprint('graph', 10));
        $this->assertSame(['value', 6], CpuView::freqFootprint('value', 6));
        $this->assertSame(['off', 0], CpuView::freqFootprint('value', 5));
        $this->assertSame(['off', 0], CpuView::freqFootprint('graph', 0));
    }

    public function testShortFreqUsesFreqLabel(): void
    {
        $this->assertSame('3.4G', CpuView::shortFreq(3400));
        $this->assertSame('800M', CpuView::shortFreq(800));
        $this->assertSame('12G', CpuView::shortFreq(12000));
        $this->assertSame('', CpuView::shortFreq(null));
        $this->assertSame('', CpuView::shortFreq(0));
    }

    public function testCelsiusToAndSensorMap(): void
    {
        $this->assertSame([50, '°C'], CpuView::celsiusTo(50, 'celsius'));
        $this->assertSame([122, '°F'], CpuView::celsiusTo(50, 'fahrenheit'));
        $this->assertSame([323, 'K '], CpuView::celsiusTo(50, 'kelvin'));
        $this->assertSame([582, '°R'], CpuView::celsiusTo(50, 'rankine'));
        $this->assertSame(1, CpuView::sensorFor(5, 4, []));
        $this->assertSame(3, CpuView::sensorFor(5, 4, [5 => 3]));
        $this->assertSame(1, CpuView::sensorFor(5, 4, [5 => 9]), 'out-of-range map entry ignored');
    }

    // ---- paint -----------------------------------------------------------

    public function testPaintBeforeTheFirstSampleIsANoOp(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $rect = $layout->box('cpu');
        $surface = Surface::new(80, 24);
        self::fake()->paint($surface->region($rect), new PanelFrame($layout, $rect, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, $host));
        $this->assertSame(array_fill(0, 24, str_repeat(' ', 80)), $surface->plainLines());
    }

    /** @return iterable<string, array{int, int, array<string, bool|string>, bool, int}> */
    public static function goldens(): iterable
    {
        yield '120x40' => [120, 40, [], true, 8];
        yield '80x24-no-sensors' => [80, 24, [], false, 8];
        yield '120x40-core-freq-value' => [120, 40, ['show_core_freq' => 'value', 'cpu_graph_lower' => 'user'], false, 8];
        yield '120x40-core-freq-graph-temps-hidden' => [120, 40, ['show_core_freq' => 'graph', 'show_coretemp' => false], true, 8];
        yield '100x30-single-block2-fahrenheit' => [100, 30, ['cpu_single_graph' => true, 'graph_symbol' => 'block2', 'temp_scale' => 'fahrenheit', 'cpu_bottom' => true], true, 8];
        yield '60x8-cpu-only-16-cores' => [60, 8, ['shown_boxes' => 'cpu', 'cpu_invert_lower' => false], true, 16];
        yield '120x40-range-freq-upper-system' => [120, 40, ['freq_mode' => 'range', 'cpu_graph_upper' => 'system', 'show_uptime' => false], false, 4];
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('goldens')]
    public function testCellGridGolden(int $cols, int $rows, array $options, bool $sensors, int $cores): void
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $host = PanelPaint::host($sensors, $cores);
        $layout = PanelPaint::layout($cols, $rows, $config, $host);
        $panel = PanelPaint::feed(self::fake($cores), $config, $layout, 24);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $name = $cols . 'x' . $rows . ($options === [] ? '' : '-' . substr(md5(serialize($options) . $sensors . $cores), 0, 6));
        PanelPaint::assertGolden($this, 'cpu', $name . '.txt', $grid);
    }

    public function testSgrGolden(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(100, 30, $config, $host);
        $panel = PanelPaint::feed(self::fake(), $config, $layout, 12);
        PanelPaint::assertGolden($this, 'cpu', '100x30.sgr.txt', PanelPaint::sgr(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu')));
    }

    public function testTtyThemeSgrGoldenHasNoTrueColor(): void
    {
        $config = Config::new()->with('tty_mode', true)->with('rounded_corners', false);
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $panel = PanelPaint::feed(self::fake(), $config, $layout, 12);
        $sgr = PanelPaint::sgr(PanelPaint::surface($panel, $config, $layout, $host, TtyTheme::new(), ColorProfile::Ansi), $layout->box('cpu'));
        $this->assertStringNotContainsString('38;2;', $sgr);
        $this->assertStringContainsString('░', $sgr, 'tty graph symbols');
        PanelPaint::assertGolden($this, 'cpu', '80x24-tty.sgr.txt', $sgr);
    }

    public function testGpuGraphsAndBriefRows(): void
    {
        $config = Config::new()->with('check_temp', true);
        $host = PanelPaint::host(true, 8);
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $gpu = static fn (int $step): GpuSnapshot => new GpuSnapshot([
            new GpuDevice(0, 'RTX 4090', 20.0 + $step, 4 * 1024 ** 3, 24 * 1024 ** 3, 55.0, 120.5, powerLimit: 450.0),
            new GpuDevice(1, 'RTX 3060', 80.0 - $step, 2 * 1024 ** 3, 12 * 1024 ** 3, 61.0, 8.25, powerLimit: 170.0),
        ]);
        $panel = CpuPanel::new(FakeCpu::new(8), FakeFreq::new(8), FakeTemp::new(4), new ScriptedSource(array_map($gpu, range(0, 20))));
        $panel = PanelPaint::feed($panel, $config, $layout, 20);
        $this->assertContains('gpu-totals', $panel->graphFields());
        $this->assertCount(2, $panel->gpus());
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $this->assertStringContainsString('total─▲▼─gpu-totals', $grid, 'Auto lower graph = per-GPU when GPUs show');
        $this->assertStringContainsString('GPU0', $grid);
        $this->assertStringContainsString('GPU1', $grid);
        $this->assertStringContainsString('4.0G/24G', $grid);
        $this->assertStringContainsString(' 120W', $grid);
        $this->assertStringContainsString('8.25W', $grid);
        PanelPaint::assertGolden($this, 'cpu', '120x40-gpu.txt', $grid);

        // A transient empty GPU snapshot keeps the devices (#1008).
        $held = $panel->update(new SampledMsg('cpu', new Samples(['gpu' => new GpuSnapshot([])]), NamedSources::of([])), PanelPaint::context('cpu', $config, $layout))->panel;
        $this->assertCount(2, $held->gpus());

        // show_gpu_info Off hides rows and the per-GPU lower graph.
        $off = $config->with('show_gpu_info', 'Off');
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $off, PanelPaint::layout(120, 40, $off, $host), $host), $layout->box('cpu'));
        $this->assertStringNotContainsString('GPU', $grid);
    }

    /** #1008: one N/A answer never drops a GPU column; the last reading stays. */
    public function testGpuColumnsHoldThroughAnUnmeasuredQuery(): void
    {
        $config = Config::new();
        $host = PanelPaint::host(true, 8);
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $good = new GpuSnapshot([new GpuDevice(0, 'RTX', 40.0, 2 * 1024 ** 3, 8 * 1024 ** 3, 60.0, 50.0, powerLimit: 100.0)]);
        $na = new GpuSnapshot([new GpuDevice(0, 'RTX', -1.0, -1, -1, -1.0, -1.0)]);
        $panel = PanelPaint::feed(CpuPanel::new(FakeCpu::new(8), null, FakeTemp::new(4), new ScriptedSource([$good, $na])), $config, $layout, 2);
        $g = $panel->gpus()[0];
        $this->assertSame([40.0, 2 * 1024 ** 3, 8 * 1024 ** 3, 60.0, 50.0, 100.0], [$g->utilization, $g->memUsed, $g->memTotal, $g->temp, $g->watts, $g->powerLimit]);
        $this->assertSame([40, 40], $panel->history()->series('gpu:0:gpu-totals'));
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $this->assertMatchesRegularExpression('/GPU .* 40% .*2\.0G\/8\.0G .* 60°C .*50\.0W/u', $grid);
    }

    /** One core column: no meter, no mini graphs, so btop never resets bold — the % is bold main_fg. */
    public function testSingleColumnGpuRowStaysBold(): void
    {
        $config = Config::new()->with('check_temp', false)->with('shown_boxes', 'cpu');
        $host = PanelPaint::host(false, 1);
        $layout = new Layout(80, 10, ['cpu' => Rect::new(0, 0, 80, 10)], Rect::new(50, 1, 29, 8), 1, 0);
        $panel = PanelPaint::feed(CpuPanel::new(FakeCpu::new(1), null, null, new ScriptedSource([new GpuSnapshot([new GpuDevice(0, 'G', 37.0, -1, -1, -1.0, -1.0)])])), $config, $layout, 2);
        $surface = Surface::new(80, 10);
        $panel->paint($surface->region(Rect::new(0, 0, 80, 10)), new PanelFrame($layout, Rect::new(0, 0, 80, 10), $ink = Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, $host));
        $row = null;
        foreach ($surface->plainLines() as $y => $line) {
            if (str_contains($line, 'GPU')) {
                $row = $y;
            }
        }
        $this->assertNotNull($row);
        $at = mb_strpos($surface->plainLines()[$row], '37%');
        $this->assertSame(Surface::canonical($ink->fg('main_fg') . "\x1b[1m"), $surface->style($at, $row));
        $this->assertSame(Surface::canonical($ink->fg('main_fg') . "\x1b[1m"), $surface->style($at + 2, $row), '% too');
    }

    /** btop trans(upstr): the space in `1d 00:00` lets the graph show through. */
    public function testUptimeSpacesAreTransparent(): void
    {
        $config = Config::new()->with('cpu_invert_lower', false)->with('check_temp', false);
        $host = PanelPaint::host(false, 2);
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $full = array_fill(0, 200, 100.0);
        $panel = PanelPaint::feed(CpuPanel::new(new ScriptedSource(array_map(static fn (float $v): CpuSnapshot => self::snap($v, [$v, $v], uptime: 90_000.0), $full))), $config, $layout, 200);
        $line = PanelPaint::surface($panel, $config, $layout, $host)->plainLines()[1];
        $this->assertSame('│⣿up⣿1d⣿01:00⣿', mb_substr($line, 0, 14));
    }

    public function testEightGpusInANarrowBoxNeverThrow(): void
    {
        $config = Config::new()->with('show_gpu_info', 'On')->with('shown_boxes', 'cpu');
        $host = PanelPaint::host(false, 8);
        $devices = array_map(static fn (int $i): GpuDevice => new GpuDevice($i, 'GPU', 50.0, -1, -1, -1.0, -1.0), range(0, 7));
        $panel = PanelPaint::feed(CpuPanel::new(FakeCpu::new(8), null, null, new ScriptedSource([new GpuSnapshot($devices)])), $config, null, 3);
        foreach ([[60, 8], [61, 9], [70, 12]] as [$w, $h]) {
            $layout = PanelPaint::layout($w, $h, $config, $host);
            $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
            $this->assertSame($h, substr_count($grid, "\n"));
        }
    }

    /** Graph dimensions are clamped >= 1: any region, however small, paints without throwing. */
    public function testTinyRegionsNeverThrow(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $panel = PanelPaint::feed(self::fake(), $config, $layout, 4);
        $ink = Ink::new(ThemeConfig::new());
        foreach ([[1, 1], [2, 2], [3, 3], [4, 3], [5, 4], [10, 5], [60, 3], [60, 4], [3, 40]] as [$w, $h]) {
            foreach ([true, false] as $single) {
                $c = $config->with('cpu_single_graph', $single);
                $rect = Rect::new(0, 0, $w, $h);
                $tiny = new Layout(120, 40, ['cpu' => $rect], Rect::new(max(0, $w - 3), 0, min($w, 3), min($h, 3)), 1, 0);
                $surface = Surface::new(120, 40);
                $panel->paint($surface->region($rect), new PanelFrame($tiny, $rect, $ink, FrameBuilder::border($c), $c, $host));
                foreach ($surface->plainLines() as $y => $line) {
                    if ($y >= $h) {
                        $this->assertSame(str_repeat(' ', 120), $line, "{$w}x{$h} painted outside its box");
                    }
                }
            }
        }
    }

    /** History survives a resize; the next frame re-lays out from the new Layout. */
    public function testResizeRepaintsFromTheNewLayout(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $big = PanelPaint::layout(120, 40, $config, $host);
        $panel = PanelPaint::feed(self::fake(), $config, $big, 10);
        $small = PanelPaint::layout(80, 24, $config, $host);
        $before = PanelPaint::grid(PanelPaint::surface($panel, $config, $small, $host), $small->box('cpu'));
        $after = PanelPaint::grid(PanelPaint::surface($panel->update(new WindowSizeMsg(80, 24), PanelPaint::context('cpu', $config, $small))->panel, $config, $small, $host), $small->box('cpu'));
        $this->assertSame($before, $after);
        $this->assertSame(8, substr_count($after, "\n"));
        $this->assertSame(10, count($panel->history()->series('total')));
    }

    public function testGraphFieldFallbacks(): void
    {
        $config = Config::new()->with('cpu_graph_upper', 'bogus')->with('cpu_graph_lower', 'iowait')->with('check_temp', false);
        $host = PanelPaint::host(false);
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $panel = PanelPaint::feed(self::fake(), $config, $layout, 3);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $this->assertStringContainsString('total─▲▼─iowait', $grid);

        $same = $config->with('cpu_graph_upper', 'iowait');
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $same, $layout, $host), $layout->box('cpu'));
        $this->assertStringNotContainsString('▲▼', $grid, 'equal fields: no divider row');
    }

    public function testFreqLabelOnTheCoresBox(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $panel = PanelPaint::feed(CpuPanel::new(FakeCpu::new(8), new ScriptedSource([
            new FreqSnapshot(3400.0, [3400.0], 800.0, 4600.0, null, '3.4 GHz'),
            new FreqSnapshot(Sentinel::UNMEASURED, [], -1.0, -1.0, null, ''),
        ])), $config, $layout, 2);
        $this->assertSame('3.4 GHz', $panel->freq()?->label, '#1008 keeps the last good label');
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('cpu'));
        $this->assertStringContainsString('┐3.4 GHz┌', $grid);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config->with('show_cpu_freq', false), $layout, $host), $layout->box('cpu'));
        $this->assertStringNotContainsString('GHz', $grid);
    }
}
