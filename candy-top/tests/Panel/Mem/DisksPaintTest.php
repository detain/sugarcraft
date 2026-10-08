<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Mem;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Mem\Disks;
use SugarCraft\Top\Panel\Mem\DisksSample;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Tests\Panel\Gfx\ScriptedSource;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Goldens for the disks half of the mem box (tests/fixtures/panels/disks/),
 * driven through MemPanel::standard's fake roster. Regenerate with
 * CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/Mem/DisksPaintTest.php.
 */
final class DisksPaintTest extends TestCase
{
    /** @param array<string, bool|string> $options */
    private static function config(array $options): Config
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return $config;
    }

    private static function panel(Config $config, Layout $layout, int $n = 20): MemPanel
    {
        $panel = PanelPaint::feed(MemPanel::standard($config, true), $config, $layout, $n);
        self::assertInstanceOf(MemPanel::class, $panel);

        return $panel;
    }

    /** @return iterable<string, array{int, int, array<string, bool|string>}> */
    public static function goldens(): iterable
    {
        yield 'meters-120x40' => [120, 40, []];
        yield 'io-120x40' => [120, 40, ['io_mode' => true]];
        yield 'io-combined-120x40' => [120, 40, ['io_mode' => true, 'io_graph_combined' => true, 'io_graph_speeds' => '/:20']];
        yield 'narrow-80x24' => [80, 24, []];
        yield 'narrow-io-80x24' => [80, 24, ['io_mode' => true]];
        yield 'tall-free-160x50' => [160, 50, ['swap_disk' => false, 'show_io_stat' => false, 'disks_filter' => 'exclude=/boot/efi']];
        yield 'below-net-order-120x40' => [120, 40, ['mem_below_net' => true, 'disks_order' => '/home swap', 'graph_symbol_mem' => 'block']];
        yield 'all-mounts-140x45' => [140, 45, ['use_fstab' => false, 'only_physical' => false, 'mem_graphs' => false]];
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('goldens')]
    public function testCellGridGolden(int $cols, int $rows, array $options): void
    {
        $config = self::config($options);
        $host = PanelPaint::host();
        $layout = PanelPaint::layout($cols, $rows, $config, $host);
        $grid = PanelPaint::grid(PanelPaint::surface(self::panel($config, $layout), $config, $layout, $host), $layout->box('mem'));
        PanelPaint::assertGolden($this, 'disks', $this->dataName() . '.txt', $grid);
    }

    public function testSgrGolden(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $sgr = PanelPaint::sgr(PanelPaint::surface(self::panel($config, $layout, 8), $config, $layout, $host), $layout->box('mem'));
        PanelPaint::assertGolden($this, 'disks', 'meters-120x40.sgr.txt', $sgr);
    }

    public function testTtySgrGolden(): void
    {
        $config = Config::new()->with('tty_mode', true)->with('io_mode', true);
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(100, 30, $config, $host);
        $sgr = PanelPaint::sgr(PanelPaint::surface(self::panel($config, $layout, 8), $config, $layout, $host, TtyTheme::new(), ColorProfile::Ansi), $layout->box('mem'));
        $this->assertStringNotContainsString('38;2;', $sgr);
        PanelPaint::assertGolden($this, 'disks', 'io-tty-100x30.sgr.txt', $sgr);
    }

    public function testTitleRowAndMetersFollowBtop(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $rect = $layout->box('mem');
        $lines = explode("\n", PanelPaint::grid(PanelPaint::surface(self::panel($config, $layout), $config, $layout, $host), $rect));
        $a = $layout->memDivider - $rect->x;
        // `├─root ... 476 GiB┤`: junction on the divider, name at a+2, total ends before the border.
        $this->assertSame('├─root', mb_substr($lines[1], $a, 6));
        $this->assertSame('476─GiB─┤', mb_substr($lines[1], -9));
        $this->assertSame(' IO% ', mb_substr($lines[2], $a + 1, 5));
        // The activity graph starts at btop's fixed column a+6 (graph_bg underlay glyph).
        $this->assertSame('⣀', mb_substr($lines[2], $a + 6, 1));
        $this->assertSame(' ', mb_substr($lines[2], $a + 5, 1));
        $this->assertSame(' Used: 4', mb_substr($lines[3], $a + 1, 8));
        // btop's swap pseudo-disk sits right after root.
        $this->assertStringContainsString('├─swap', $lines[4]);
    }

    public function testNarrowActivityGraphStartsAtAPlusFive(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(80, 24, $config, $host);
        $rect = $layout->box('mem');
        $lines = explode("\n", PanelPaint::grid(PanelPaint::surface(self::panel($config, $layout), $config, $layout, $host), $rect));
        $a = $layout->memDivider - $rect->x;
        $efi = 7; // ├─efi on row 6, its ` IO` row below
        $this->assertStringContainsString('├─efi', $lines[$efi - 1]);
        $this->assertSame(' IO ', mb_substr($lines[$efi], $a + 1, 4));
        $this->assertSame('⣀', mb_substr($lines[$efi], $a + 5, 1));
    }

    public function testEmptyAndHiddenSectionPaintsNothing(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        $empty = MemPanel::new(FakeMemory::new())->withDisks(Disks::new(new ScriptedSource([new DisksSample([], [])])));
        $grid = PanelPaint::grid(PanelPaint::surface(PanelPaint::feed($empty, $config, $layout, 2), $config, $layout, $host), $layout->box('mem'));
        $this->assertStringNotContainsString('├─root', $grid);
        $flat = $config->with('show_disks', false);
        $hidden = PanelPaint::grid(PanelPaint::surface(self::panel($flat, PanelPaint::layout(120, 40, $flat, $host)), $flat, PanelPaint::layout(120, 40, $flat, $host), $host), PanelPaint::layout(120, 40, $flat, $host)->box('mem'));
        $this->assertStringNotContainsString('├─root', $hidden);
    }

    /** Every option mix paints any region size without throwing or spilling out of the box. */
    public function testTinyRegionsNeverThrow(): void
    {
        $host = PanelPaint::host();
        $ink = Ink::new(ThemeConfig::new());
        $configs = [
            Config::new(),
            Config::new()->with('io_mode', true),
            Config::new()->with('io_mode', true)->with('io_graph_combined', true),
            Config::new()->with('show_io_stat', false)->with('swap_disk', false),
        ];
        foreach ($configs as $config) {
            $panel = self::panel($config, PanelPaint::layout(120, 40, $config, $host), 5);
            foreach ([[1, 1], [2, 2], [3, 3], [4, 4], [6, 5], [12, 4], [30, 3], [36, 5], [40, 30], [9, 40]] as [$w, $h]) {
                $rect = Rect::new(0, 0, $w, $h);
                $layout = new Layout(120, 40, ['mem' => $rect], memWidth: intdiv($w, 2), disksWidth: max(0, $w - intdiv($w, 2) - 2), memDivider: intdiv($w, 2), showDisks: true);
                $surface = Surface::new(120, 40);
                $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border($config), $config, $host));
                foreach ($surface->plainLines() as $y => $line) {
                    $this->assertSame($y < $h ? mb_substr($line, $w) : $line, str_repeat(' ', $y < $h ? 120 - $w : 120), "{$w}x{$h} spilled");
                }
                // The bottom border row is never drawn over by the section.
                if ($h >= 3) {
                    $this->assertSame(str_repeat(' ', $w - intdiv($w, 2)), mb_substr($surface->plainLines()[$h - 1], intdiv($w, 2), $w - intdiv($w, 2)), "{$w}x{$h} bottom row");
                }
            }
        }
    }

    public function testResizeKeepsHistoryAndRelaysOut(): void
    {
        $config = Config::new()->with('io_mode', true);
        $host = PanelPaint::host();
        $panel = self::panel($config, PanelPaint::layout(120, 40, $config, $host), 8);
        $disks = $panel->disks();
        $this->assertInstanceOf(Disks::class, $disks);
        $this->assertCount(8, $disks->history()->series(Disks::key('read', '/')));
        $small = PanelPaint::layout(80, 24, $config, $host);
        $grid = PanelPaint::grid(PanelPaint::surface($panel, $config, $small, $host), $small->box('mem'));
        $this->assertSame($small->box('mem')->height, substr_count($grid, "\n"));
        $this->assertStringContainsString('├─root', $grid);
    }
}
