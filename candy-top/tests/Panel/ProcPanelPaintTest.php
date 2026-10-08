<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Tests\Support\ProcRows;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Paint goldens for the proc box (frame chrome + panel, cropped to the
 * box). Regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/ProcPanelPaintTest.php`
 * and review the diff; a missing golden fails.
 */
final class ProcPanelPaintTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/panels/proc/';

    /**
     * Drive a demo panel through `$keys` and paint it.
     *
     * @param array<string, bool|int|string> $options
     * @param list<string> $keys
     * @return array{0: Surface, 1: Rect, 2: ProcPanel, 3: Config}
     */
    private static function painted(int $cols, int $rows, array $options = [], array $keys = [], int $extra = 0, ?Ink $ink = null): array
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        $layout = FrameBuilder::layout($cols, $rows, $config, 8);
        $box = $layout->box('proc');
        self::assertNotNull($box);
        $ctx = static function () use (&$config, $layout, $box): PanelContext {
            return new PanelContext($config, $layout, $box);
        };
        $panel = ProcPanel::new(FakeProcList::demo(8, $extra));
        for ($i = 0; $i < 3; $i++) {
            $panel = $panel->update(($panel->collect($ctx()))(), $ctx())->panel;
        }
        foreach ($keys as $k) {
            $msg = match ($k) {
                'down' => new KeyMsg(KeyType::Down),
                'enter' => new KeyMsg(KeyType::Enter),
                default => new KeyMsg(KeyType::Char, $k),
            };
            $result = $panel->update($msg, $ctx());
            foreach ($result->set as $opt => $v) {
                $config = $config->with($opt, $v);
            }
            $panel = $result->panel;
            if ($result->cmd !== null && ($m = ($result->cmd)()) instanceof SampledMsg) {
                $panel = $panel->update($m, $ctx())->panel;
            }
        }
        self::assertInstanceOf(ProcPanel::class, $panel);
        $panel = $panel->update(($panel->collect($ctx()))(), $ctx())->panel;
        self::assertInstanceOf(ProcPanel::class, $panel);

        return [self::paint($panel, $config, $layout, $box, $ink), $box, $panel, $config];
    }

    private static function paint(ProcPanel $panel, Config $config, Layout $layout, Rect $box, ?Ink $ink = null): Surface
    {
        $ink ??= Ink::new(ThemeConfig::new(), ColorProfile::TrueColor);
        $surface = Surface::new($layout->width, $layout->height, $ink->base());
        FrameBuilder::paintChrome($surface, $layout, $ink, $config, Harness::host());
        $panel->paint($surface->region($box), new PanelFrame($layout, $box, $ink, FrameBuilder::border($config), $config, Harness::host()));

        return $surface;
    }

    /** @return list<string> the box's cells, row by row */
    private static function crop(Surface $s, Rect $box): array
    {
        $out = [];
        foreach (array_slice($s->plainLines(), $box->y, $box->height) as $line) {
            $out[] = mb_substr($line, $box->x, $box->width);
        }

        return $out;
    }

    /**
     * `$count` rows of the box from `$from`, one line each: every style
     * change as its canonical SGR, then the glyphs — only the box's own
     * cells, so another box's chrome never leaks into the golden.
     */
    private static function cropSgr(Surface $s, Rect $box, int $from, int $count): string
    {
        $out = '';
        for ($y = $box->y + $from; $y < $box->y + $from + $count; $y++) {
            $active = null;
            for ($x = $box->x; $x < $box->right(); $x++) {
                $style = $s->style($x, $y);
                if ($style !== $active) {
                    $out .= "\x1b[0m" . $style;
                    $active = $style;
                }
                $out .= $s->glyph($x, $y);
            }
            $out .= "\x1b[0m\n";
        }

        return $out;
    }

    /** @return iterable<string, array{string, int, int, array<string, bool|int|string>, list<string>}> */
    public static function goldens(): iterable
    {
        yield 'default' => ['default-120x40', 120, 40, [], ['down', 'down']];
        yield 'wide io columns' => ['wide-180x50', 180, 50, ['proc_mem_bytes' => false, 'proc_reversed' => true], ['down']];
        yield 'tree collapsed' => ['tree-120x40', 120, 40, ['proc_sorting' => 'pid', 'proc_reversed' => true, 'proc_tree' => true], ['down', 'down', '-']];
        yield 'detailed view' => ['detail-120x40', 120, 40, [], ['down', 'down', 'enter']];
        yield 'filtering' => ['filter-120x40', 120, 40, [], ['f', 'o']];
        yield 'narrow basename' => ['narrow-80x24', 80, 24, ['proc_command_basename' => true, 'proc_cpu_graphs' => false], ['down']];
        // Phase P-F1: the paused / following banner and the action buttons.
        yield 'following' => ['following-120x40', 120, 40, [], ['down', 'down', 'down', 'F']];
        yield 'paused' => ['paused-120x40', 120, 40, [], ['u']];
        yield 'detail following' => ['detail-following-180x50', 180, 50, [], ['down', 'enter', 'u']];
    }

    /**
     * @param array<string, bool|int|string> $options
     * @param list<string> $keys
     */
    #[DataProvider('goldens')]
    public function testCellGridGolden(string $name, int $cols, int $rows, array $options, array $keys): void
    {
        [$surface, $box] = self::painted($cols, $rows, $options, $keys);
        $this->assertGolden(self::DIR . $name . '.txt', implode("\n", self::crop($surface, $box)) . "\n");
    }

    public function testSgrGolden(): void
    {
        [$surface, $box] = self::painted(120, 40, [], ['down', 'down']);
        $this->assertGolden(self::DIR . 'default-120x40.sgr', self::cropSgr($surface, $box, 0, 6));
    }

    public function testTtySgrGolden(): void
    {
        $ink = Ink::new(TtyTheme::new(), ColorProfile::Ansi);
        [$surface, $box] = self::painted(120, 40, ['tty_mode' => true], ['down'], 0, $ink);
        $grid = implode("\n", self::crop($surface, $box));
        $this->assertStringContainsString('░', $grid, 'tty graph_bg underlay');
        $this->assertGolden(self::DIR . 'tty-120x40.sgr', self::cropSgr($surface, $box, 1, 4));
    }

    public function testSelectedRowIsAHighlightBarAndOthersFade(): void
    {
        [$s, $box] = self::painted(120, 40, [], ['down', 'down']);
        $ink = Ink::new(ThemeConfig::new());
        $bar = Surface::canonical($ink->bg('selected_bg') . $ink->fg('selected_fg') . "\x1b[1m");
        $this->assertSame($bar, $s->style($box->x + 5, $box->y + 3), 'row 2 is selected');
        $this->assertSame($bar, $s->style($box->x + 1, $box->y + 3), 'the bar spans the row');
        // proc_gradient: the pid column dims with distance from the selection.
        $near = $s->style($box->x + 8, $box->y + 4);
        $far = $s->style($box->x + 8, $box->y + 14);
        $this->assertNotSame($near, $far);
        $this->assertSame(Surface::canonical($ink->gradient('proc', 0)), $near, 'calc 0 on the row below the bar (btop off-by-one)');
    }

    public function testFollowedRowAndBannerColours(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        [$s, $box, $panel] = self::painted(120, 40, [], ['down', 'down', 'F']);
        $this->assertNotNull($panel->followedPid());
        $followed = Surface::canonical($ink->bg('followed_bg') . $ink->fg('followed_fg') . "\x1b[1m");
        $this->assertSame($followed, $s->style($box->x + 5, $box->y + 3), 'btop: the followed row wins over the selection colours');
        $bannerY = $box->y + $box->height - 2;
        $this->assertSame(Surface::canonical($ink->bg('proc_follow_bg') . $ink->fg('proc_banner_fg') . "\x1b[1m"), $s->style($box->x + 1, $bannerY));
        $this->assertStringContainsString('Following process', self::crop($s, $box)[$box->height - 2]);

        [$s, $box] = self::painted(120, 40, [], ['u']);
        $this->assertSame(Surface::canonical($ink->bg('proc_pause_bg') . $ink->fg('proc_banner_fg') . "\x1b[1m"), $s->style($box->x + 1, $box->y + $box->height - 2));
        $this->assertStringContainsString('Process list paused', self::crop($s, $box)[$box->height - 2]);

        [$s, $box] = self::painted(120, 40, [], ['down', 'F', 'u']);
        $this->assertSame(Surface::canonical($ink->bg('proc_banner_bg') . $ink->fg('proc_banner_fg') . "\x1b[1m"), $s->style($box->x + 1, $box->y + $box->height - 2));
        $this->assertStringContainsString('Paused list and Following process', self::crop($s, $box)[$box->height - 2]);
    }

    public function testActionButtonsGreyOutWithoutASelection(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        [$s, $box] = self::painted(120, 40);
        $row = self::crop($s, $box)[$box->height - 1];
        $x = $box->x + mb_strpos($row, 'terminate');
        $this->assertSame(Surface::canonical($ink->fg('inactive_fg') . "\x1b[1m"), $s->style($x, $box->bottom() - 1), 'nothing selected: inactive');
        [$s, $box] = self::painted(120, 40, [], ['down']);
        $this->assertSame(Surface::canonical($ink->fg('hi_fg') . "\x1b[1m"), $s->style($x, $box->bottom() - 1), 'the key letter lights up');
        $this->assertSame(Surface::canonical($ink->fg('title') . "\x1b[1m"), $s->style($x + 1, $box->bottom() - 1));
    }

    public function testProcColorsOffMakesMetricsBoldInTheRowColor(): void
    {
        [$s, $box] = self::painted(120, 40, ['proc_colors' => false, 'proc_gradient' => false]);
        $ink = Ink::new(ThemeConfig::new());
        $this->assertSame(Surface::canonical($ink->fg('main_fg') . "\x1b[1m"), $s->style($box->x + 10, $box->y + 2), 'name: bold main_fg');
        $this->assertSame(Surface::canonical($ink->fg('main_fg')), $s->style($box->x + 5, $box->y + 2), 'pid: plain main_fg');
    }

    public function testContainersVmsBasenameAndIoCells(): void
    {
        [$s, $box] = self::painted(240, 50, ['proc_sorting' => 'pid', 'proc_command_basename' => true]);
        $grid = implode("\n", self::crop($s, $box));
        $this->assertStringContainsString('[docker:3f2a1b9c0d1e] python3 /app/worker.py', $grid, '#1873 tag + #1859 basename');
        $this->assertMatchesRegularExpression('/6969 web01 +\[kvm:web01\] qemu-system-x86_64/', $grid, 'U1b: the guest name in the program column');
        $this->assertStringContainsString('vim notes.md', $grid);
        $this->assertStringNotContainsString('/usr/bin/vim', $grid);
        $this->assertMatchesRegularExpression('/ 5120 .* -      - /', $grid, '#1823: unreadable io renders "-", never 0');
        $this->assertStringContainsString('IO/R   IO/W', $grid);
    }

    public function testNarrowBoxDropsTheIoColumns(): void
    {
        [$s, $box] = self::painted(120, 40);
        $this->assertLessThan(90, $box->width);
        $this->assertStringNotContainsString('IO/R', implode("\n", self::crop($s, $box)));
    }

    public function testScrollbarAppearsWhenTheListOverflows(): void
    {
        [$s, $box] = self::painted(120, 40, [], ['down'], 100);
        $col = $box->x + $box->width - 2;
        $this->assertSame('↑', $s->glyph($col, $box->y + 1));
        $this->assertSame('↓', $s->glyph($col, $box->y + $box->height - 2));
        $this->assertSame('█', $s->glyph($col, $box->y + 2), 'thumb at the top');
        $grid = self::crop($s, $box);
        $this->assertStringContainsString('1/113', $grid[$box->height - 1]);

        [$small, $box2] = self::painted(120, 40);
        $this->assertNotSame('↑', $small->glyph($box2->x + $box2->width - 2, $box2->y + 1), 'no scrollbar when it fits');
    }

    public function testMiniGraphsSitOnTheGraphBgUnderlay(): void
    {
        [$s, $box] = self::painted(120, 40, ['proc_sorting' => 'pid', 'proc_reversed' => true]);
        $grid = self::crop($s, $box);
        $kthreadd = array_values(array_filter($grid, static fn (string $l): bool => str_contains($l, 'kthreadd')))[0] ?? '';
        $this->assertStringContainsString('⣀⣀⣀⣀⣀', $kthreadd, 'idle: underlay only');
        $ink = Ink::new(ThemeConfig::new());
        $row = array_search($kthreadd, $grid, true);
        $x = $box->x + mb_strpos($kthreadd, '⣀⣀⣀⣀⣀');
        $this->assertSame(Surface::canonical($ink->fg('inactive_fg')), $s->style($x, $box->y + (int) $row));
    }

    /** @return iterable<string, array{int, int}> */
    public static function tinyRegions(): iterable
    {
        yield 'btop minimum proc box' => [44, 16];
        yield 'too short for the detail rows' => [44, 10];
        yield 'smaller than any btop box' => [12, 5];
        yield 'degenerate' => [3, 3];
    }

    #[DataProvider('tinyRegions')]
    public function testTinyRegionsNeverThrowOrSpill(int $w, int $h): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(200, 60, $config, 8);
        $box = Rect::new(2, 3, $w, $h);
        $ctx = new PanelContext($config, $layout, $box);
        $panel = ProcPanel::new(FakeProcList::demo(8, 30));
        $panel = $panel->update(($panel->collect($ctx))(), $ctx)->panel;
        $panel = $panel->update(new KeyMsg(KeyType::Down), $ctx)->panel;
        $result = $panel->update(new KeyMsg(KeyType::Enter), $ctx);
        $config = $config->with('show_detailed', true);
        $this->assertInstanceOf(ProcPanel::class, $result->panel);
        $s = Surface::new(60, 30);
        $ink = Ink::new(ThemeConfig::new());
        $result->panel->paint($s->region($box), new PanelFrame($layout, $box, $ink, FrameBuilder::border($config), $config, Harness::host()));
        foreach ($s->plainLines() as $y => $line) {
            foreach (mb_str_split($line) as $x => $ch) {
                if ($ch !== ' ') {
                    $this->assertTrue($box->contains($x, $y), "painted outside the box at $x,$y");
                }
            }
        }
    }

    public function testResizeRepaintsWithinTheNewBox(): void
    {
        [, , $panel, $config] = self::painted(120, 40, [], ['down', 'down'], 100);
        foreach ([[100, 30], [80, 24], [200, 60]] as [$cols, $rows]) {
            $layout = FrameBuilder::layout($cols, $rows, $config, 8);
            $box = $layout->box('proc');
            $this->assertNotNull($box);
            $s = self::paint($panel, $config, $layout, $box);
            $grid = self::crop($s, $box);
            $this->assertCount($box->height, $grid);
            $this->assertStringContainsString('/113', $grid[$box->height - 1], "{$cols}x{$rows}");
        }
    }

    public function testPaintBeforeTheFirstSampleDrawsTheEmptyList(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $box = $layout->box('proc');
        $this->assertNotNull($box);
        $s = self::paint(ProcPanel::new(FakeProcList::demo()), $config, $layout, $box);
        $grid = self::crop($s, $box);
        $this->assertStringContainsString('Pid:', $grid[1]);
        $this->assertStringContainsString('0/0', $grid[$box->height - 1]);
    }

    public function testMemPercentUsesTheSnapshotTotal(): void
    {
        $config = Config::new()->with('proc_mem_bytes', false);
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $box = $layout->box('proc');
        $this->assertNotNull($box);
        $ctx = new PanelContext($config, $layout, $box);
        $snap = new ProcSnapshot([ProcRows::process(1, ['mem' => 25 * 1024 * 1024])], 1, 100 * 1024 * 1024);
        [, $src] = FakeProcList::demo()->sample();
        $panel = ProcPanel::new($src)->update(new SampledMsg('proc', $snap, $src), $ctx)->panel;
        $this->assertInstanceOf(ProcPanel::class, $panel);
        $grid = implode("\n", self::crop(self::paint($panel, $config, $layout, $box), $box));
        $this->assertStringContainsString('25%', $grid);
        $this->assertStringContainsString('Mem%', $grid);
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
