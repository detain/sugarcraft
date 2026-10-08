<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Gpu\GpuFeed;
use SugarCraft\Top\Panel\GpuPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Gpu\GpuHold;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\GpuRoster;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Surface;

/**
 * The gpu boxes (btop Gpu::draw, PR #1730 slots + selector, PR #1881
 * detail levels, #985/#1839 NPU box): sampling only while shown,
 * bounded #1008 hold, title-selector retargeting, paint goldens per
 * detail level under tests/fixtures/panels/gpu/.
 */
final class GpuPanelTest extends TestCase
{
    private static function config(string $boxes, array $options = []): Config
    {
        $config = Config::new()->with('shown_boxes', $boxes);
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return $config;
    }

    private static function layout(int $cols, int $rows, Config $config, GpuPanel $panel): Layout
    {
        return FrameBuilder::layout($cols, $rows, $config, 8, false, $panel->gpuRoster());
    }

    private static function feed(GpuPanel $panel, Config $config, ?Layout $layout, int $n): GpuPanel
    {
        for ($i = 0; $i < $n; $i++) {
            $ctx = new PanelContext($config, $layout);
            $cmd = $panel->collect($ctx);
            $msg = $cmd === null ? null : $cmd();
            if ($msg instanceof SampledMsg) {
                $next = $panel->update($msg, $ctx)->panel;
                self::assertInstanceOf(GpuPanel::class, $next);
                $panel = $next;
            }
        }

        return $panel;
    }

    /** Frame chrome plus every gpu box painted by `$panel`. */
    private static function surface(GpuPanel $panel, Config $config, Layout $layout, ?Ink $ink = null): Surface
    {
        $ink ??= Ink::new(ThemeConfig::new());
        $host = PanelPaint::host(false);
        $surface = Surface::new($layout->width, $layout->height, $ink->base());
        FrameBuilder::paintChrome($surface, $layout, $ink, $config, $host);
        foreach ($layout->gpuBoxes as $name => $box) {
            $panel->paint($surface->region($box->rect), new PanelFrame($layout, $box->rect, $ink, FrameBuilder::border($config), $config, $host, $name));
        }

        return $surface;
    }

    /** The gpu grid's rows, plain. */
    private static function gridRows(Surface $surface, Layout $layout): string
    {
        $first = min(array_map(static fn ($b): int => $b->rect->y, $layout->gpuBoxes));

        return implode("\n", array_slice($surface->plainLines(), $first, $layout->gpuHeight)) . "\n";
    }

    private static function fake(): GpuPanel
    {
        return GpuPanel::standard(true);
    }

    public function testStandardFakeSeedsTheRosterBeforeSampling(): void
    {
        $panel = self::fake();
        $this->assertSame('gpu', $panel->box());
        $this->assertFalse($panel->sampled());
        $roster = $panel->gpuRoster();
        $this->assertSame([3, 2], [$roster->count(), $roster->gpuCount()]);
        $this->assertSame([8, 8, 2], $roster->offsets, 'NVIDIA-shaped GPUs: util + pwr + enc/dec + 5 memory rows; NPU: util + mem');
        $this->assertSame(3, $panel->detectedGpus());
        $this->assertSame([], $panel->optionChoices());
        $this->assertSame(0, GpuPanel::new(null)->gpuRoster()->count());
    }

    public function testProbeAndRosterSeeding(): void
    {
        // FreeBSD reads nvidia-smi only; a host without it answers an empty snapshot, never throws.
        $snapshot = GpuPanel::probe(Config::new(), Platform::for('FreeBSD', new FixtureProbe()));
        $this->assertInstanceOf(GpuSnapshot::class, $snapshot);
        $seeded = GpuPanel::new(null)->withRoster(new GpuRoster([1, 2], ['a', 'b'], 2));
        $this->assertSame(2, $seeded->gpuRoster()->count(), 'the seed until a sample arrives');
        $probe = new GpuSnapshot([new GpuDevice(0, 'A', 1.0, 1, 2, 3.0, 4.0)], null, [new GpuDevice(0, 'N', 1.0, 1, -1, -1.0, -1.0)]);
        $live = GpuPanel::standard(false, Platform::for('FreeBSD', new FixtureProbe()), $probe);
        $this->assertSame(['A', 'N'], $live->gpuRoster()->names);
        $this->assertSame(1, $live->gpuRoster()->gpus);
    }

    public function testCollectsOnlyWhileAGpuBoxIsShown(): void
    {
        $panel = self::fake();
        $this->assertNull($panel->collect(new PanelContext(Config::new())), '#1858: no gpu box, no sample');
        $this->assertNull(GpuPanel::new(null)->collect(new PanelContext(self::config('cpu gpu0'))));
        $cmd = $panel->collect(new PanelContext(self::config('cpu gpu0')));
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('gpu', $msg->box);
        $this->assertInstanceOf(GpuSnapshot::class, $msg->snapshot);
        $this->assertInstanceOf(GpuFeed::class, $msg->next, 'the panel asks the shared feed');
        $this->assertInstanceOf(FakeGpu::class, $msg->next->source());
    }

    public function testNeverModalAndClaimsNoKeys(): void
    {
        $ctx = new PanelContext(self::config('gpu0'));
        $panel = self::fake();
        $this->assertFalse($panel->modal($ctx));
        foreach (['5', '0', 'g'] as $k) {
            $this->assertFalse($panel->capturesKey(new KeyMsg(KeyType::Char, $k), $ctx));
        }
    }

    public function testNpusIndexAfterTheGpus(): void
    {
        $config = self::config('cpu gpu0 gpu2');
        $panel = self::feed(self::fake(), $config, null, 2);
        $this->assertTrue($panel->sampled());
        $this->assertSame(AcceleratorKind::Gpu, $panel->device(1)?->kind);
        $this->assertSame(AcceleratorKind::Npu, $panel->device(2)?->kind);
        $this->assertNull($panel->device(3));
        $this->assertCount(2, $panel->history()->series(GpuPanel::key(2, 'util')));
        $this->assertSame([], $panel->history()->series(GpuPanel::key(2, 'temp')), 'the NPU never measured a temperature');
        $this->assertSame('a2:util', GpuPanel::key(2, 'util'));
    }

    public function testUpdateIsImmutableAndIgnoresForeignMessages(): void
    {
        $panel = self::fake();
        $ctx = new PanelContext(self::config('gpu0'));
        $this->assertSame($panel, $panel->update(new SampledMsg('cpu', new GpuSnapshot([]), FakeGpu::new()), $ctx)->panel);
        $this->assertSame($panel, $panel->update(new KeyMsg(KeyType::Char, '5'), $ctx)->panel);
        $msg = ($panel->collect($ctx))();
        $next = $panel->update($msg, $ctx)->panel;
        $this->assertNotSame($panel, $next);
        $this->assertFalse($panel->sampled());
    }

    /** #1008 bounded: a GPU that stops answering reads n/a after GpuHold::limit() samples. */
    public function testHoldExpiresAfterTheLimit(): void
    {
        $config = self::config('gpu0', ['update_ms' => 10000]);
        $limit = GpuHold::limit(10000);
        $this->assertSame(5, $limit, 'max(5, 30 s / 10 s)');
        $good = new GpuSnapshot([new GpuDevice(0, 'RTX', 40.0, 2 * 1024 ** 3, 8 * 1024 ** 3, 60.0, 50.0, powerLimit: 100.0)]);
        $script = [$good, ...array_fill(0, $limit + 1, new GpuSnapshot([]))];
        $panel = GpuPanel::new(new ScriptedSource($script));
        $held = self::feed($panel, $config, null, $limit + 1);
        $this->assertSame(40.0, $held->device(0)?->utilization, 'still held after the limit');
        $this->assertSame(40, $held->history()->last(GpuPanel::key(0, 'util')));
        $gone = self::feed($held, $config, null, 1);
        $this->assertSame(-1.0, $gone->device(0)?->utilization, 'n/a once the hold expired');
        $this->assertSame('RTX', $gone->device(0)?->name, 'identity kept');
        $this->assertNull($gone->history()->last(GpuPanel::key(0, 'util')), 'graphs restart empty');
    }

    public function testSelectorClicksRetargetTheBox(): void
    {
        $panel = self::fake();
        $config = self::config('cpu gpu0 mem net proc');
        $layout = self::layout(160, 50, $config, $panel);
        $box = $layout->gpuBox('gpu0');
        $this->assertNotNull($box);
        $this->assertNotNull($box->selector);
        [$prev, $next] = $box->selector;
        $ctx = new PanelContext($config, $layout, $box->rect);
        $click = static fn (int $x, MouseButton $b = MouseButton::Left, MouseAction $a = MouseAction::Press): MouseMsg => new MouseMsg($x + 1, $box->rect->y + 1, $b, $a);

        $this->assertSame([0, 1], $panel->clickedSelector($click($next), $ctx));
        $this->assertSame([0, -1], $panel->clickedSelector($click($prev), $ctx));
        $this->assertTrue($panel->capturesClick($click($next), $ctx));
        $this->assertFalse($panel->capturesClick($click($next - 1), $ctx), 'the label is not a zone');
        $this->assertFalse($panel->capturesClick($click($next, MouseButton::Right), $ctx));
        $this->assertFalse($panel->capturesClick($click($next, MouseButton::Left, MouseAction::Release), $ctx));

        $result = $panel->update($click($next), $ctx);
        $this->assertSame(['shown_boxes' => 'cpu gpu1 mem net proc', GpuPanels::SLOTS_KEY => '0'], $result->set);
        $this->assertSame($panel, $result->panel);
        // Previous from GPU 0 wraps to the last accelerator — the NPU.
        $this->assertSame('cpu gpu2 mem net proc', $panel->update($click($prev), $ctx)->set['shown_boxes']);
        // No layout: nothing to check the fit against, nothing written.
        $this->assertSame([], $panel->update($click($next), new PanelContext($config))->set);
    }

    public function testFullDetailGoldens(): void
    {
        $config = self::config('cpu gpu0 gpu1 mem net proc');
        $panel = self::feed(self::fake(), $config, null, 6);
        $layout = self::layout(160, 50, $config, $panel);
        $surface = self::surface($panel, $config, $layout);
        $grid = self::gridRows($surface, $layout);
        $this->assertStringContainsString('┐⁵gpu┌', $grid);
        $this->assertStringContainsString('┐⁶gpu┌', $grid);
        $this->assertStringContainsString('┐← gpu1 →┌', $grid);
        $this->assertStringContainsString('NVIDIA GeForce RTX 4090', $grid);
        $this->assertMatchesRegularExpression('/PWR .*W P-state:  P2/u', $grid);
        $this->assertMatchesRegularExpression('/ENC .*%│DEC .*%/u', $grid);
        $this->assertMatchesRegularExpression('/├─┐vram┌─┐10501 MHz┌┬─Used:─+\d/u', $grid);
        $this->assertStringContainsString('├─Utilization:', $grid);
        PanelPaint::assertGolden($this, 'gpu', 'full-160x50.txt', $grid);
    }

    public function testFullDetailSgrGolden(): void
    {
        $config = self::config('cpu gpu0 gpu1 mem net proc');
        $panel = self::feed(self::fake(), $config, null, 6);
        $layout = self::layout(160, 50, $config, $panel);
        $box = $layout->gpuBox('gpu0');
        $this->assertNotNull($box);
        PanelPaint::assertGolden($this, 'gpu', 'full-160x50.sgr.txt', PanelPaint::sgr(self::surface($panel, $config, $layout), $box->rect));
    }

    public function testMinimalDetailAndTheNpuBox(): void
    {
        // Three boxes at 120 columns: (120 - 2) / 3 = 39 wide -> Minimal (no power / memory rows).
        $config = self::config('cpu gpu0 gpu1 gpu2 mem net proc');
        $panel = self::feed(self::fake(), $config, null, 6);
        $layout = self::layout(120, 50, $config, $panel);
        $grid = self::gridRows(self::surface($panel, $config, $layout), $layout);
        $this->assertStringContainsString('┐⁷npu┌', $grid);
        $this->assertStringContainsString('┐← npu0 →┌', $grid);
        $this->assertStringContainsString('│NPU ■', $grid);
        $this->assertStringNotContainsString('PWR', $grid);
        $this->assertStringNotContainsString('vram', $grid);
        PanelPaint::assertGolden($this, 'gpu', 'minimal-npu-120x50.txt', $grid);
    }

    public function testStackedRowsAndNpuMemory(): void
    {
        // Two boxes, one per row (gpu_box_columns 1), 100 wide: Full.
        $config = self::config('gpu0 gpu2 proc', ['gpu_box_columns' => '1']);
        $panel = self::feed(self::fake(), $config, null, 4);
        $layout = self::layout(100, 40, $config, $panel);
        $grid = self::gridRows(self::surface($panel, $config, $layout), $layout);
        $this->assertStringContainsString('RAM usage:', $grid, '#985: an NPU reports RAM, not VRAM');
        PanelPaint::assertGolden($this, 'gpu', 'stacked-npu-100x40.txt', $grid);
    }

    public function testCompactDetail(): void
    {
        // Two columns at 100: (100 - 1) / 2 = 49 wide, Compact.
        $compact = self::config('gpu0 gpu1 proc');
        $panel = self::feed(self::fake(), $compact, null, 4);
        $layout = self::layout(100, 40, $compact, $panel);
        $grid = self::gridRows(self::surface($panel, $compact, $layout), $layout);
        $this->assertStringContainsString('GPU ■', $grid);
        $this->assertStringNotContainsString('ENC', $grid, 'Compact drops enc/dec');
        $this->assertStringNotContainsString('vram', $grid, 'Compact drops memory');
        PanelPaint::assertGolden($this, 'gpu', 'compact-100x40.txt', $grid);
    }

    public function testTtyModeSgrGoldenHasNoWideColours(): void
    {
        $config = self::config('cpu gpu0 mem net proc')->withTtyModeResolved(true, false);
        $panel = self::feed(self::fake(), $config, null, 6);
        $layout = self::layout(120, 50, $config, $panel);
        $palette = ThemeRegistry::new(null, [])->load('Default', true, true);
        $box = $layout->gpuBox('gpu0');
        $this->assertNotNull($box);
        $sgr = PanelPaint::sgr(self::surface($panel, $config, $layout, Ink::new($palette, ColorProfile::Ansi)), $box->rect);
        $this->assertDoesNotMatchRegularExpression('/\e\[[0-9;]*[34]8;[25];/', $sgr);
        $this->assertStringContainsString('5gpu', (string) preg_replace('/\e\[[0-9;]*m/', '', $sgr), 'tty: plain digit');
        PanelPaint::assertGolden($this, 'gpu', 'tty-120x50.sgr.txt', $sgr);
    }

    public function testCheckTempOffAndSingleGraph(): void
    {
        $config = self::config('cpu gpu0 mem net proc', ['check_temp' => false, 'gpu_mirror_graph' => false]);
        $panel = self::feed(self::fake(), $config, null, 6);
        $layout = self::layout(160, 50, $config, $panel);
        $grid = self::gridRows(self::surface($panel, $config, $layout), $layout);
        $this->assertStringNotContainsString('°C', $grid);
        // The meter takes btop's `b_width - 12` cells without temps: 55 - 12.
        $this->assertMatchesRegularExpression('/│GPU ■{43}\s+\d+%│/u', $grid);
    }

    public function testPaintNeverTouchesTheBottomBorderOrOtherBoxes(): void
    {
        foreach ([[160, 50, 'cpu gpu0 gpu1 mem net proc'], [100, 40, 'gpu0 gpu2 proc'], [80, 40, 'gpu0 gpu1']] as [$cols, $rows, $boxes]) {
            $config = self::config($boxes);
            $panel = self::feed(self::fake(), $config, null, 3);
            $layout = self::layout($cols, $rows, $config, $panel);
            $chrome = Surface::new($cols, $rows);
            FrameBuilder::paintChrome($chrome, $layout, Ink::new(ThemeConfig::new()), $config, PanelPaint::host(false));
            $painted = self::surface($panel, $config, $layout);
            $before = $chrome->plainLines();
            $after = $painted->plainLines();
            foreach ($layout->gpuBoxes as $box) {
                $bottom = $box->rect->bottom() - 1;
                $this->assertSame(mb_substr($before[$bottom], $box->rect->x, $box->rect->width), mb_substr($after[$bottom], $box->rect->x, $box->rect->width), "{$cols}x{$rows} bottom border");
            }
            foreach ($after as $y => $line) {
                foreach (mb_str_split($line) as $x => $ch) {
                    $inside = false;
                    foreach ($layout->gpuBoxes as $box) {
                        $inside = $inside || $box->rect->contains($x, $y);
                    }
                    if (!$inside) {
                        $this->assertSame(mb_substr($before[$y], $x, 1), $ch, "{$cols}x{$rows} painted outside at {$x},{$y}");
                    }
                }
            }
        }
    }

    public function testTinyAndOddSizesNeverThrow(): void
    {
        $panel = self::feed(self::fake(), self::config('gpu0 gpu1 gpu2'), null, 2);
        foreach ([[34, 8], [20, 8], [40, 5], [70, 9], [200, 12], [56, 60], [44, 10]] as [$cols, $rows]) {
            $config = self::config('gpu0 gpu1 gpu2');
            $layout = self::layout($cols, $rows, $config, $panel);
            $surface = self::surface($panel, $config, $layout);
            $this->assertCount($rows, $surface->plainLines());
        }
    }

    public function testPaintBeforeTheFirstSampleDrawsOnlyChrome(): void
    {
        $config = self::config('gpu0');
        $panel = self::fake();
        $layout = self::layout(100, 30, $config, $panel);
        $chrome = Surface::new(100, 30);
        FrameBuilder::paintChrome($chrome, $layout, Ink::new(ThemeConfig::new()), $config, PanelPaint::host(false));
        $this->assertSame($chrome->plainLines(), self::surface($panel, $config, $layout)->plainLines());
    }
}
