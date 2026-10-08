<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Mem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Mem\MemView;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Panel\Gfx\RecordingDisks;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class MemPanelTest extends TestCase
{
    private const GIB = 1024 ** 3;

    /** 16 GiB host, 4 GiB swap of which 1 GiB used, 384 MiB of it in zswap (96 MiB compressed). */
    private static function zswapped(int $step = 0): MemorySnapshot
    {
        $used = (int) ((6.0 + $step * 0.25) * self::GIB);

        return new MemorySnapshot(16 * self::GIB, $used, 16 * self::GIB - $used, 3 * self::GIB, 2 * self::GIB, 4 * self::GIB, self::GIB, 3 * self::GIB, 96 * 1024 ** 2, 384 * 1024 ** 2);
    }

    private static function scripted(): MemPanel
    {
        return MemPanel::new(new ScriptedSource(array_map(self::zswapped(...), range(0, 30))));
    }

    private static function grid(MemPanel $panel, Config $config, int $cols = 120, int $rows = 40): string
    {
        $host = PanelPaint::host();
        $layout = PanelPaint::layout($cols, $rows, $config, $host);

        return PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('mem'));
    }

    private static function key(string $rune, bool $ctrl = false): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl);
    }

    // ---- roster / keys ----------------------------------------------------

    public function testStandardRosterUsesTheMemPanel(): void
    {
        foreach ([true, false] as $fake) {
            $panel = Panels::standard(PanelPaint::host(), Config::new(), $fake)['mem'];
            $this->assertInstanceOf(MemPanel::class, $panel);
            $this->assertSame('mem', $panel->box());
        }
    }

    public function testClaimsOnlyTheMemBoxKeys(): void
    {
        $panel = MemPanel::new(FakeMemory::new());
        $ctx = PanelPaint::context('mem', Config::new(), null);
        $this->assertFalse($panel->modal($ctx));
        $this->assertTrue($panel->capturesKey(self::key('d'), $ctx));
        $this->assertTrue($panel->capturesKey(self::key('i'), $ctx));
        foreach ([self::key('D'), self::key('d', true), self::key('x'), self::key('+'), new KeyMsg(KeyType::Enter)] as $key) {
            $this->assertFalse($panel->capturesKey($key, $ctx), $key->string());
        }
    }

    public function testDFlipsShowDisksSynchronouslyAndResamples(): void
    {
        $panel = MemPanel::new(FakeMemory::new());
        $config = Config::new();
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $result = $panel->update(self::key('d'), PanelPaint::context('mem', $config, $layout));
        $this->assertSame(['show_disks' => false], $result->set);
        $this->assertSame($panel, $result->panel);
        $this->assertNotNull($result->cmd, 'btop runs the mem collector after d');
        $this->assertInstanceOf(SampledMsg::class, ($result->cmd)());

        $back = $panel->update(self::key('d'), PanelPaint::context('mem', $config->with('show_disks', false), $layout));
        $this->assertSame(['show_disks' => true], $back->set);
    }

    public function testIFlipsIoMode(): void
    {
        $panel = MemPanel::new(FakeMemory::new());
        $result = $panel->update(self::key('i'), PanelPaint::context('mem', Config::new(), null));
        $this->assertSame(['io_mode' => true], $result->set);
        $this->assertNull($result->cmd);
        $this->assertSame(['io_mode' => false], $panel->update(self::key('i'), PanelPaint::context('mem', Config::new()->with('io_mode', true), null))->set);
    }

    /** btop mouse_mappings["d"] / ["i"]: clicks on the border buttons. */
    public function testBorderButtonClicks(): void
    {
        $panel = MemPanel::new(FakeMemory::new());
        $config = Config::new();
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $ctx = PanelPaint::context('mem', $config, $layout);
        $box = $layout->box('mem');
        $buttons = MemPanel::buttons($ctx);
        $this->assertSame(['show_disks', 'io_mode'], array_keys($buttons));

        // The `disks` label the chrome draws sits under the d mapping.
        $surface = PanelPaint::surface($panel, $config, $layout, PanelPaint::host());
        $d = $buttons['show_disks'];
        $this->assertSame('disks', mb_substr($surface->plainLines()[$box->y], $box->x + $d->x, 5));
        $click = static fn (int $x, int $y, MouseButton $b = MouseButton::Left, bool $shift = false): MouseMsg => new MouseMsg($x, $y, $b, MouseAction::Press, $shift);

        $this->assertSame(['show_disks' => false], $panel->update($click($box->x + $d->x + 1, $box->y + 1), $ctx)->set);
        $this->assertSame(['show_disks' => false], $panel->update($click($box->x + $d->x + 5, $box->y + 1), $ctx)->set);
        $this->assertSame([], $panel->update($click($box->x + $d->x + 6, $box->y + 1), $ctx)->set);
        $this->assertSame([], $panel->update($click($box->x + $d->x + 1, $box->y + 2), $ctx)->set);
        $this->assertSame([], $panel->update($click($box->x + $d->x + 1, $box->y + 1, MouseButton::Right), $ctx)->set);
        $this->assertSame([], $panel->update($click($box->x + $d->x + 1, $box->y + 1, MouseButton::Left, true), $ctx)->set);
        $io = $buttons['io_mode'];
        $this->assertSame('io', mb_substr($surface->plainLines()[$box->y], $box->x + $io->x, 2));
        $this->assertSame(['io_mode' => true], $panel->update($click($box->x + $io->x + 1, $box->y + 1), $ctx)->set);

        // Without the disks half: `disks` sits left of the corner, no io button.
        $flat = $config->with('show_disks', false);
        $flatLayout = PanelPaint::layout(120, 40, $flat, PanelPaint::host());
        $flatButtons = MemPanel::buttons(PanelPaint::context('mem', $flat, $flatLayout));
        $this->assertSame(['show_disks'], array_keys($flatButtons));
        $this->assertSame('disks', mb_substr(PanelPaint::surface($panel, $flat, $flatLayout, PanelPaint::host())->plainLines()[$box->y], $box->x + $flatButtons['show_disks']->x, 5));
        $this->assertSame([], MemPanel::buttons(PanelPaint::context('mem', $config, null)));
        $release = new MouseMsg($box->x + $d->x + 1, $box->y + 1, MouseButton::Left, MouseAction::Release);
        $this->assertSame([], $panel->update($release, $ctx)->set);
    }

    // ---- data -------------------------------------------------------------

    public function testUpdateFoldsSamplesAndIgnoresOthers(): void
    {
        $panel = self::scripted();
        $next = PanelPaint::feed($panel, Config::new(), null, 3);
        $this->assertNull($panel->snapshot(), 'immutable');
        $this->assertNotNull($next->snapshot());
        $this->assertCount(3, $next->history()->series('used'));
        $this->assertSame(25, $next->history()->last('swap_used'));
        $this->assertSame(16, $next->history()->last('swap_used_disk'), '(1 GiB - 384 MiB) / 4 GiB');
        $this->assertSame(2, $next->history()->last('zswap'));
        $ctx = PanelPaint::context('mem', Config::new(), null);
        $this->assertSame($next, $next->update(new FocusGainedMsg(), $ctx)->panel);
        [$s, $src] = FakeMemory::new()->sample();
        $this->assertSame($next, $next->update(new SampledMsg('cpu', $s, $src), $ctx)->panel);
    }

    /** btop #1008: a failed /proc/meminfo read keeps the last snapshot and holds every graph. */
    public function testUnmeasuredSampleHoldsTheLastGoodValue(): void
    {
        $good = self::zswapped();
        $panel = PanelPaint::feed(MemPanel::new(new ScriptedSource([$good, MemorySnapshot::unmeasured()])), Config::new(), null, 3);
        $this->assertSame($good, $panel->snapshot());
        $this->assertSame([38, 38, 38], $panel->history()->series('used'));
        $grid = self::grid($panel, Config::new());
        $this->assertStringContainsString('6.00─GiB', $grid, 'trans(): the divider shows through the space');
        $this->assertStringNotContainsString('n/a', $grid);

        $never = PanelPaint::feed(MemPanel::new(new ScriptedSource([MemorySnapshot::unmeasured()])), Config::new(), null, 2);
        $this->assertNull($never->snapshot());
        $this->assertSame([], $never->history()->series('used'));
    }

    /** btop #1739: the Zswap row, and swap "Used" becoming on-disk only. */
    public function testZswapRow(): void
    {
        $config = Config::new()->with('swap_disk', false)->with('show_disks', false)->with('mem_graphs', false);
        $panel = PanelPaint::feed(self::scripted(), $config, null, 4);
        $grid = self::grid($panel, $config);
        $this->assertStringContainsString('Swap:', $grid);
        $this->assertStringContainsString('Zswap', $grid);
        $this->assertMatchesRegularExpression('/Used .* 640 MiB/u', $grid, 'swap Used = 1 GiB - 384 MiB Zswapped');
        $this->assertMatchesRegularExpression('/Zswap .* 96\.0 MiB/u', $grid);

        $off = $config->with('show_zswap', false);
        $grid = self::grid($panel, $off);
        $this->assertStringNotContainsString('Zswap', $grid);
        $this->assertMatchesRegularExpression('/Used .* 1\.00 GiB/u', $grid);

        // A kernel without zswap lines (UNMEASURED) never shows the row.
        $plain = PanelPaint::feed(MemPanel::new(new ScriptedSource([new MemorySnapshot(16 * self::GIB, self::GIB, 15 * self::GIB, 0, 0, self::GIB, 0, self::GIB)])), $config, null, 1);
        $this->assertStringNotContainsString('Zswap', self::grid($plain, $config));
    }

    public function testSwapDiskMovesSwapOutOfTheMemColumn(): void
    {
        $panel = PanelPaint::feed(self::scripted(), Config::new(), null, 2);
        $this->assertStringNotContainsString('Swap:', self::grid($panel, Config::new()), 'swap_disk default true');
        $this->assertStringContainsString('Swap:', self::grid($panel, Config::new()->with('swap_disk', false)));
        $this->assertStringNotContainsString('Swap:', self::grid($panel, Config::new()->with('swap_disk', false)->with('show_swap', false)));
    }

    /** btop #1747: one focused graph instead of the stacked classes. */
    public function testMemSelectedFocusedGraph(): void
    {
        $config = Config::new()->with('mem_selected', 'available');
        $panel = PanelPaint::feed(self::scripted(), $config, null, 10);
        $grid = self::grid($panel, $config);
        $this->assertStringContainsString('Available:', $grid);
        $this->assertStringNotContainsString('Cached', $grid);
        $this->assertStringContainsString('Total:', $grid);

        $swap = $config->with('mem_selected', 'swap_used');
        $grid = self::grid($panel, $swap);
        $this->assertStringContainsString('Swap:', $grid);
        $this->assertStringNotContainsString('Total:', $grid);

        // No swap at all: swap_used falls back to the stacked view.
        $noSwap = PanelPaint::feed(MemPanel::new(new ScriptedSource([new MemorySnapshot(16 * self::GIB, self::GIB, 15 * self::GIB, 0, 0, 0, 0, 0)])), $swap, null, 1);
        $this->assertStringContainsString('Cached', self::grid($noSwap, $swap));
    }

    /**
     * btop calcSizes (Mem): item_height, mem_size, mem_meter, graph_height.
     *
     * @param array{itemHeight: int, memSize: int, meter: int, graphHeight: int} $expected
     */
    #[DataProvider('geometries')]
    public function testGeometryPortsCalcSizes(int $height, int $memWidth, bool $swap, bool $graphs, bool $zswap, array $expected): void
    {
        $mem = new MemorySnapshot(100, 50, 50, 10, 40, $swap ? 100 : 0, 0, $swap ? 100 : 0);
        $geo = MemView::geometry($height, $memWidth, $mem, false, $graphs, $zswap);
        $this->assertSame($expected, array_intersect_key($geo, $expected));
    }

    /** @return iterable<string, array{int, int, bool, bool, bool, array<string, int>}> */
    public static function geometries(): iterable
    {
        yield '120x40 mem, no swap, graphs' => [15, 26, false, true, false, ['itemHeight' => 4, 'memSize' => 3, 'meter' => 25, 'graphHeight' => 2]];
        yield 'swap, meters' => [15, 26, true, false, false, ['itemHeight' => 6, 'memSize' => 2, 'meter' => 9, 'graphHeight' => 0]];
        yield 'tall swap graphs' => [30, 26, true, true, false, ['itemHeight' => 6, 'memSize' => 3, 'meter' => 25, 'graphHeight' => 3]];
        yield 'zswap adds an item' => [30, 26, true, true, true, ['itemHeight' => 7, 'memSize' => 3, 'meter' => 25, 'graphHeight' => 2]];
        yield 'narrow mem_size 1' => [10, 18, false, true, false, ['itemHeight' => 4, 'memSize' => 1, 'meter' => 7, 'graphHeight' => 1]];
        yield 'graph height floors at 1' => [3, 10, true, true, false, ['memSize' => 1, 'meter' => 6, 'graphHeight' => 1]];
    }

    // ---- disks seam ---------------------------------------------------------

    public function testDisksSectionIsSampledAndPaintedRightOfTheDivider(): void
    {
        RecordingDisks::$areas = [];
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $disks = new RecordingDisks(new ScriptedSource([(object) ['value' => 1], (object) ['value' => 2]]));
        $panel = MemPanel::new(FakeMemory::new())->withDisks($disks);
        $this->assertSame($disks, $panel->disks());
        $panel = PanelPaint::feed($panel, $config, $layout, 2);
        $this->assertSame(2, $panel->disks()->last->value);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('mem'));
        $this->assertStringContainsString('DISKS2', $grid);
        $area = RecordingDisks::$areas[0];
        $this->assertSame($layout->memDivider - $layout->box('mem')->x, $area->x);
        $this->assertSame($layout->box('mem')->height, $area->height);
        $this->assertSame($layout->box('mem')->width, $area->x + $area->width);

        // Hidden disks half: not sampled, not painted.
        RecordingDisks::$areas = [];
        $flat = $config->with('show_disks', false);
        $msg = ($panel->collect(PanelPaint::context('mem', $flat, $layout)))();
        $this->assertNull($msg->snapshot->get('disks'));
        PanelPaint::surface($panel, $flat, PanelPaint::layout(120, 40, $flat, $host), $host);
        $this->assertSame([], RecordingDisks::$areas);
        $this->assertNull($panel->withDisks(null)->disks());
    }

    // ---- paint -------------------------------------------------------------

    public function testPaintBeforeTheFirstSampleDrawsOnlyTheIoButton(): void
    {
        $config = Config::new();
        $layout = PanelPaint::layout(120, 40, $config, PanelPaint::host());
        $rect = $layout->box('mem');
        $surface = Surface::new(120, 40);
        MemPanel::new(FakeMemory::new())->paint($surface->region($rect), new PanelFrame($layout, $rect, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, PanelPaint::host()));
        $lines = $surface->plainLines();
        $this->assertStringContainsString('io', $lines[$rect->y]);
        for ($y = $rect->y + 1; $y < 40; $y++) {
            $this->assertSame(str_repeat(' ', 120), $lines[$y]);
        }
    }

    /** @return iterable<string, array{int, int, array<string, bool|string>}> */
    public static function goldens(): iterable
    {
        yield '120x40' => [120, 40, []];
        yield '80x24' => [80, 24, []];
        yield '120x40-meters-swap-zswap' => [120, 40, ['mem_graphs' => false, 'swap_disk' => false, 'show_disks' => false]];
        yield '120x40-graphs-swap-zswap' => [120, 40, ['swap_disk' => false, 'show_disks' => false]];
        yield '120x40-focused-used' => [120, 40, ['mem_selected' => 'used']];
        yield '120x40-mem-below-net-block' => [120, 40, ['mem_below_net' => true, 'graph_symbol_mem' => 'block', 'swap_disk' => false, 'io_mode' => true]];
        yield '100x50-tall-swap' => [100, 50, ['swap_disk' => false]];
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('goldens')]
    public function testCellGridGolden(int $cols, int $rows, array $options): void
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $layout = PanelPaint::layout($cols, $rows, $config, PanelPaint::host());
        $panel = PanelPaint::feed(self::scripted(), $config, $layout, 24);
        $name = $cols . 'x' . $rows . ($options === [] ? '' : '-' . substr(md5(serialize($options)), 0, 6));
        PanelPaint::assertGolden($this, 'mem', $name . '.txt', self::grid($panel, $config, $cols, $rows));
    }

    public function testSgrGolden(): void
    {
        $config = Config::new()->with('swap_disk', false);
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(100, 30, $config, $host);
        $panel = PanelPaint::feed(self::scripted(), $config, $layout, 12);
        PanelPaint::assertGolden($this, 'mem', '100x30.sgr.txt', PanelPaint::sgr(PanelPaint::surface($panel, $config, $layout, $host), $layout->box('mem')));
    }

    public function testTtySgrHasNoTrueColor(): void
    {
        $config = Config::new()->with('tty_mode', true)->with('mem_graphs', false)->with('swap_disk', false);
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $panel = PanelPaint::feed(self::scripted(), $config, $layout, 6);
        $sgr = PanelPaint::sgr(PanelPaint::surface($panel, $config, $layout, $host, TtyTheme::new(), ColorProfile::Ansi), $layout->box('mem'));
        $this->assertStringNotContainsString('38;2;', $sgr);
        $this->assertStringContainsString('■', $sgr);
    }

    /** Graph sizes are clamped >= 1: any region paints without throwing or spilling. */
    public function testTinyRegionsNeverThrow(): void
    {
        $host = PanelPaint::host();
        $ink = Ink::new(ThemeConfig::new());
        foreach ([Config::new(), Config::new()->with('mem_selected', 'free'), Config::new()->with('swap_disk', false)->with('mem_graphs', false)] as $config) {
            $panel = PanelPaint::feed(self::scripted(), $config, null, 5);
            foreach ([[1, 1], [2, 2], [3, 3], [4, 4], [8, 5], [36, 3], [36, 4], [5, 30]] as [$w, $h]) {
                $rect = Rect::new(0, 0, $w, $h);
                foreach ([true, false] as $disks) {
                    $layout = new Layout(120, 40, ['mem' => $rect], memWidth: max(0, intdiv($w, 2)), memDivider: $disks ? intdiv($w, 2) : null, showDisks: $disks);
                    $surface = Surface::new(120, 40);
                    $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border($config), $config, $host));
                    foreach ($surface->plainLines() as $y => $line) {
                        $this->assertSame($y < $h ? mb_substr($line, $w) : $line, str_repeat(' ', $y < $h ? 120 - $w : 120), "{$w}x{$h} spilled");
                    }
                }
            }
        }
    }

    public function testResizeKeepsHistoryAndRelaysOut(): void
    {
        $config = Config::new();
        $panel = PanelPaint::feed(self::scripted(), $config, PanelPaint::layout(120, 40, $config, PanelPaint::host()), 8);
        $small = self::grid($panel, $config, 80, 24);
        $this->assertSame(8, count($panel->history()->series('used')));
        $this->assertSame(PanelPaint::layout(80, 24, $config, PanelPaint::host())->box('mem')->height, substr_count($small, "\n"));
        $this->assertStringContainsString('Total:', $small);
    }
}
