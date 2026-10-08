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
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\LoadResult;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Msg\ConfigLoadedMsg;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Msg\SetOptionMsg;
use SugarCraft\Top\Overlay\MsgBox;
use SugarCraft\Top\Panel\GpuPanel;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;

/**
 * The App side of the gpu boxes (btop PR #1730 / #1881): slot keys 5-0,
 * the size gate, the title selector, routing every gpuN box to the one
 * gpu panel, and re-laying out when the detected accelerators change.
 * The fake roster has two GPUs and one NPU.
 */
final class AppGpuTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    /** @param array<string, Panel>|null $panels */
    private static function app(int $cols = 160, int $rows = 50, ?Config $config = null, ?array $panels = null): App
    {
        $config ??= Config::new();
        $panels ??= Panels::standard(Harness::host(), $config, true);
        $app = App::start($config, ThemeConfig::new(), Harness::host(), $panels, static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0), ColorProfile::TrueColor);
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

    private static function key(App $app, string $key): App
    {
        [$app, $cmd] = $app->update(new KeyMsg(KeyType::Char, $key));

        return self::settle($app, $cmd);
    }

    private static function boxes(string $boxes): Config
    {
        return Config::new()->with('shown_boxes', $boxes);
    }

    public function testSlotKeysOpenAndCloseGpuBoxes(): void
    {
        $app = self::app();
        [$opened, $cmd] = $app->update(new KeyMsg(KeyType::Char, '5'));
        $this->assertSame('cpu mem net proc gpu0', $opened->config->string('shown_boxes'));
        $this->assertSame('0', $opened->config->string(GpuPanels::SLOTS_KEY));
        $this->assertTrue($opened->writeNew, 'btop toggle_gpu_box goes through Config::set');
        $this->assertCount(1, Cmds::of(SampledMsg::class, $cmd), 'the gpu panel is sampled at once');
        $this->assertNotNull($opened->layout?->gpuBox('gpu0'));
        $opened = self::settle($opened, $cmd);

        // A second box: the panel already samples, no extra Cmd.
        [$two, $cmd] = $opened->update(new KeyMsg(KeyType::Char, '7'));
        $this->assertSame('cpu mem net proc gpu0 gpu2', $two->config->string('shown_boxes'));
        $this->assertNull($cmd);
        $this->assertSame(7, $two->layout?->gpuBox('gpu2')?->key());
        $frame = implode("\n", $two->surface()?->plainLines() ?? []);
        $this->assertStringContainsString('┐⁵gpu┌', $frame);
        $this->assertStringContainsString('┐⁷npu┌', $frame, 'gpu2 past the two GPUs is the NPU (#985)');

        // Closing slot 0 keeps slot 2's title key.
        $closed = self::key($two, '5');
        $this->assertSame('cpu mem net proc gpu2', $closed->config->string('shown_boxes'));
        $this->assertSame([2], GpuPanels::slots($closed->config));
        $this->assertSame(7, $closed->layout?->gpuBox('gpu2')?->key());
    }

    /** btop PR #1730 toggle_box keeps current_gpu_panel_slots: 1-4 never reshuffle gpu slots. */
    public function testBoxTogglesKeepTheGpuSlots(): void
    {
        $app = self::key(self::app(), '7');
        $this->assertSame([2], GpuPanels::slots($app->config));
        $app = self::key($app, '2');
        $this->assertSame(['cpu', 'net', 'proc', 'gpu2'], $app->config->shownBoxes());
        $this->assertSame([2], GpuPanels::slots($app->config), 'hiding mem kept slot 2');
        $this->assertSame(7, $app->layout?->gpuBox('gpu2')?->key());
        $app = self::key($app, '7');
        $this->assertSame(['cpu', 'net', 'proc'], $app->config->shownBoxes(), '7 closes the box it opened');
        $this->assertNull($app->layout?->gpuBox('gpu2'));
    }

    public function testASlotWithoutItsGpuDoesNothing(): void
    {
        $app = self::app();
        foreach (['8', '9', '0'] as $key) {
            [$next, $cmd] = $app->update(new KeyMsg(KeyType::Char, $key));
            $this->assertSame($app->config->toArray(), $next->config->toArray(), $key);
            $this->assertNull($next->overlay());
            $this->assertNull($cmd);
        }
    }

    public function testAToggleDropsThePreset(): void
    {
        $app = self::key(self::app(), 'p');
        $this->assertNotNull($app->preset);
        $this->assertNull(self::key($app, '5')->preset);
    }

    public function testARefusedToggleOpensTheSizeErrorBox(): void
    {
        // 80 x 30: cpu 8 + proc 16 + one Full gpu box (12) = 36 rows needed.
        $app = self::app(80, 30);
        $refused = self::key($app, '5');
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $refused->config->shownBoxes());
        $this->assertInstanceOf(MsgBox::class, $refused->overlay());
    }

    public function testBehindTheSizeNoticeTheSlotKeysStillToggle(): void
    {
        $app = self::app(80, 30, self::boxes('cpu mem net proc gpu0'));
        $this->assertStringContainsString('Terminal size too small', implode("\n", $app->surface()?->plainLines() ?? []));
        $fits = self::key($app, '5');
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $fits->config->shownBoxes());
        $this->assertNull($fits->overlay());
        $this->assertStringNotContainsString('Terminal size too small', implode("\n", $fits->surface()?->plainLines() ?? []));
        // Opening one that does not fit is silently ignored there (btop's resize loop).
        $ignored = self::key(self::app(60, 20), '5');
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $ignored->config->shownBoxes());
        $this->assertNull($ignored->overlay());
    }

    public function testTheTitleSelectorRetargetsTheBox(): void
    {
        $app = self::app(160, 50, self::boxes('cpu gpu0 mem net proc'));
        $box = $app->layout?->gpuBox('gpu0');
        $this->assertNotNull($box?->selector);
        $click = new MouseMsg($box->selector[1] + 1, $box->rect->y + 1, MouseButton::Left, MouseAction::Press);
        [$next] = $app->update($click);
        $this->assertSame('cpu gpu1 mem net proc', $next->config->string('shown_boxes'));
        $this->assertNotNull($next->layout?->gpuBox('gpu1'));
        $back = new MouseMsg($box->selector[0] + 1, $box->rect->y + 1, MouseButton::Left, MouseAction::Press);
        [$prev] = $next->update($back);
        $this->assertSame('cpu gpu0 mem net proc', $prev->config->string('shown_boxes'));
    }

    public function testShownBoxesWritesNeedADetectedGpu(): void
    {
        $app = self::app();
        [$refused] = $app->update(new SetOptionMsg('shown_boxes', 'cpu gpu5'));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $refused->config->shownBoxes(), 'btop set_boxes: gpu5 >= Gpu::count');
        $this->assertNull($refused->overlay());
        [$ok] = $app->update(new SetOptionMsg('shown_boxes', 'cpu gpu1 proc'));
        $this->assertSame(['cpu', 'gpu1', 'proc'], $ok->config->shownBoxes());
    }

    public function testAPresetNamingAMissingGpuIsSkipped(): void
    {
        // `p` first lands on the built-in preset 0, then on preset 1.
        $app = self::key(self::app(160, 50, Config::new()->with('presets', 'cpu:0:default,gpu7:0:default')), 'p');
        $this->assertSame(0, $app->preset);
        $next = self::key($app, 'p');
        $this->assertSame($app->config->shownBoxes(), $next->config->shownBoxes());
        $this->assertSame(0, $next->preset, 'btop apply_preset fails on a gpuN beyond Gpu::count');
        $this->assertInstanceOf(MsgBox::class, $next->overlay(), 'btop shows the size-error box');
        // btop restores the old preset, so `p` stays stuck before the failing one.
        $again = self::key(self::key($next, 'enter'), 'p');
        $this->assertSame(0, $again->preset);
        $this->assertInstanceOf(MsgBox::class, $again->overlay());
        $ok = self::key(self::key(self::app(160, 50, Config::new()->with('presets', 'cpu:0:default,gpu1:0:block')), 'p'), 'p');
        $this->assertSame(1, $ok->preset);
        $this->assertSame(['cpu', 'gpu1'], $ok->config->shownBoxes());
        $this->assertSame('block', $ok->config->string('graph_symbol_gpu'));
    }

    public function testEveryGpuBoxIsDrawnByTheGpuPanel(): void
    {
        $app = self::app();
        $this->assertInstanceOf(GpuPanel::class, $app->panelFor('gpu7'));
        $this->assertSame($app->panel('gpu'), $app->panelFor('gpu0'));
        $this->assertSame($app->panel('cpu'), $app->panelFor('cpu'));
        $this->assertNull($app->panelFor('disks'));
        $this->assertSame(3, $app->roster()->count());
    }

    public function testANewAcceleratorReLaysTheGrid(): void
    {
        $one = new GpuSnapshot([new GpuDevice(0, 'A', 10.0, 1, 2, 30.0, 1.0)]);
        $two = new GpuSnapshot([new GpuDevice(0, 'A', 10.0, 1, 2, 30.0, 1.0), new GpuDevice(1, 'B', 20.0, 1, 2, 30.0, 1.0)]);
        $config = self::boxes('cpu gpu0 mem net proc');
        $panels = Panels::standard(Harness::host(), $config, true);
        $panels['gpu'] = GpuPanel::new(new ScriptedSource([$one, $two]));
        $app = self::app(160, 50, $config, $panels);
        $this->assertSame(1, $app->roster()->count());
        $this->assertNull($app->layout?->gpuBox('gpu0')?->selector, 'one accelerator: no selector');
        $cmd = $app->panel('gpu')?->collect($app->context('gpu0'));
        $app = self::settle($app, $cmd);
        $this->assertSame(2, $app->roster()->count());
        $this->assertNotNull($app->layout?->gpuBox('gpu0')?->selector, 'the second GPU re-ran the layout');
    }

    public function testAReloadSettlesGpuBoxesAgainstTheDetectedCount(): void
    {
        $app = self::app();
        [$kept] = $app->update(new ConfigLoadedMsg(new LoadResult(self::boxes('cpu gpu2'), [], false)));
        $this->assertSame(['cpu', 'gpu2'], $kept->config->shownBoxes());
        $bad = Config::new()->withParsed('shown_boxes', 'cpu gpu3', true);
        [$reset] = $app->update(new ConfigLoadedMsg(new LoadResult($bad, [], false)));
        $this->assertSame(['cpu', 'mem', 'net', 'proc'], $reset->config->shownBoxes());
    }
}
