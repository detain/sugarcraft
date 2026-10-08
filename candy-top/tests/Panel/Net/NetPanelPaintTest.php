<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Net;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Net\NetPanel;
use SugarCraft\Top\Panel\Net\NetView;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Source\Fake\FakeNet;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Paint goldens for the net box. Regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/Net/NetPanelPaintTest.php`
 * and review the diff; a missing golden fails.
 */
final class NetPanelPaintTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/panels/net/';

    /** 24 fake samples (one per second), so every graph has scrolled. */
    private static function panel(Config $config, Layout $layout, int $ticks = 24): NetPanel
    {
        $ctx = new PanelContext($config, $layout, $layout->box('net'));
        $panel = NetPanel::new(FakeNet::new(1.0));
        for ($i = 0; $i < $ticks; $i++) {
            $next = $panel->update(($panel->collect($ctx))(), $ctx)->panel;
            self::assertInstanceOf(NetPanel::class, $next);
            $panel = $next;
        }

        return $panel;
    }

    /**
     * App chrome + the net panel on a full-size surface.
     */
    private static function render(int $cols, int $rows, Config $config, ?Ink $ink = null, ?NetPanel $panel = null, bool $chrome = true): array
    {
        $layout = FrameBuilder::layout($cols, $rows, $config, 8);
        $box = $layout->box('net');
        self::assertNotNull($box);
        $ink ??= Ink::new(ThemeConfig::new());
        $panel ??= self::panel($config, $layout);
        $surface = Surface::new($cols, $rows, $ink->base());
        if ($chrome) {
            FrameBuilder::paintChrome($surface, $layout, $ink, $config, Harness::host());
        }
        $panel->paint($surface->region($box), new PanelFrame($layout, $box, $ink, FrameBuilder::border($config), $config, Harness::host()));

        return [$surface, $box];
    }

    /** The net box's rows, cut to its columns. */
    private static function boxGrid(Surface $surface, Rect $box): string
    {
        $out = '';
        foreach (array_slice($surface->plainLines(), $box->y, $box->height) as $row) {
            $out .= mb_substr($row, $box->x, $box->width) . "\n";
        }

        return $out;
    }

    /** @return iterable<string, array{int, int, array<string, bool|int|string>}> */
    public static function grids(): iterable
    {
        yield '120x40' => [120, 40, []];
        yield '80x24' => [80, 24, []];
        yield '160x50-swap-block' => [160, 50, ['swap_upload_download' => true, 'graph_symbol_net' => 'block']];
        yield '120x40-hide-ip-no-sync-no-auto' => [120, 40, ['net_hide_ip' => true, 'net_sync' => false, 'net_auto' => false]];
        yield '120x40-base10-block2' => [120, 40, ['base_10_sizes' => true, 'graph_symbol' => 'block2']];
    }

    /** @param array<string, bool|int|string> $options */
    #[DataProvider('grids')]
    public function testCellGridGolden(int $cols, int $rows, array $options): void
    {
        $config = Config::new();
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }
        [$surface, $box] = self::render($cols, $rows, $config);
        $this->assertGolden(self::FIXTURES . $this->dataName() . '.txt', self::boxGrid($surface, $box));
    }

    public function testSgrGolden(): void
    {
        [$surface, $box] = self::render(120, 40, Config::new(), chrome: false);
        $rows = array_slice($surface->lines(), $box->y, $box->height);
        $this->assertGolden(self::FIXTURES . 'sgr-120x40.txt', implode("\n", $rows) . "\n");
    }

    public function testTtySgrGolden(): void
    {
        $config = Config::new()->with('tty_mode', true)->with('rounded_corners', false);
        [$surface, $box] = self::render(100, 30, $config, Ink::new(TtyTheme::new(), ColorProfile::Ansi), chrome: false);
        $rows = array_slice($surface->lines(), $box->y, $box->height);
        $this->assertGolden(self::FIXTURES . 'sgr-tty-100x30.txt', implode("\n", $rows) . "\n");
        $this->assertStringContainsString('░', implode('', $surface->plainLines()), 'tty mode draws the tty graph family');
    }

    public function testTitleButtonsMarkActiveStateInBold(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $panel = self::panel($config, $layout);
        [$surface, $box] = self::render(120, 40, $config, panel: $panel);
        $title = mb_substr($surface->plainLines()[$box->y], $box->x, $box->width);
        $zx = $box->x + mb_strpos($title, 'zero');
        $ax = $box->x + mb_strpos($title, 'auto');
        $this->assertStringNotContainsString("\x1b[1", $surface->style($zx, $box->y), 'zero is plain until z');
        $this->assertStringContainsString('[1;', $surface->style($ax, $box->y), 'auto is bold while net_auto');

        $zeroed = $panel->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'z'), new PanelContext($config, $layout, $box))->panel;
        $this->assertInstanceOf(NetPanel::class, $zeroed);
        [$after] = self::render(120, 40, $config, panel: $zeroed);
        $this->assertStringContainsString('[1;', $after->style($zx, $box->y), 'zero is bold once offset');
        $this->assertMatchesRegularExpression('/▼ Total: +0 Byte/', implode("\n", $after->plainLines()));
    }

    public function testIpShowsOnlyWhenEnabledAndItFits(): void
    {
        [$wide] = self::render(120, 40, Config::new());
        $this->assertStringContainsString('┐192.0.2.10┌', implode("\n", $wide->plainLines()));
        [$hidden] = self::render(120, 40, Config::new()->with('net_hide_ip', true));
        $this->assertStringNotContainsString('192.0.2.10', implode("\n", $hidden->plainLines()));
        // 80 cols: net box 36 wide, 36 - 4 - 36 is never > 10.
        [$narrow] = self::render(80, 24, Config::new());
        $this->assertStringNotContainsString('192.0.2.10', implode("\n", $narrow->plainLines()));
    }

    public function testIpv6IsTheFallbackAddress(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(160, 40, $config, 8);
        $box = $layout->box('net');
        $ctx = new PanelContext($config, $layout, $box);
        $source = ScriptedNetSource::of(new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 1.0, 1.0, 1, 1, ipv6: '2001:db8::7')], 'eth0'));
        $panel = NetPanel::new($source);
        $panel = $panel->update(($panel->collect($ctx))(), $ctx)->panel;
        $this->assertInstanceOf(NetPanel::class, $panel);
        [$surface] = self::render(160, 40, $config, panel: $panel);
        $this->assertStringContainsString('┐2001:db8::7┌', implode("\n", $surface->plainLines()));
    }

    public function testNothingIsPaintedBeforeTheFirstSample(): void
    {
        $config = Config::new();
        [$surface] = self::render(120, 40, $config, panel: NetPanel::new(FakeNet::new()), chrome: false);
        $this->assertSame(array_fill(0, 40, str_repeat(' ', 120)), $surface->plainLines());
    }

    /** @return iterable<string, array{int, int}> */
    public static function tinyBoxes(): iterable
    {
        yield '1x1' => [1, 1];
        yield '2x2' => [2, 2];
        yield '10x3' => [10, 3];
        yield '20x4' => [20, 4];
        yield '36x6 btop minimum' => [36, 6];
    }

    /**
     * btop #1614/#1858: a box too small for its graphs must clamp every
     * graph to >= 1 cell (DualSampleGraph throws below that) and never
     * write outside the box.
     */
    #[DataProvider('tinyBoxes')]
    public function testTinyBoxPaintsInsideItsRectWithoutThrowing(int $w, int $h): void
    {
        $config = Config::new();
        $panel = self::panel($config, FrameBuilder::layout(120, 40, $config, 8), 4);
        $box = Rect::new(3, 2, $w, $h);
        // The box's OWN stats sub-box (btop's b_* formula at this size), as
        // FrameBuilder would lay it out, not the 120x40 one.
        $layout = new Layout(40, 12, ['net' => $box], netStats: NetView::statsRect($w, $h)->translate($box->x, $box->y));
        $surface = Surface::new(40, 12);
        $panel->paint($surface->region($box), new PanelFrame($layout, $box, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host()));
        foreach ($surface->plainLines() as $y => $row) {
            foreach (mb_str_split($row) as $x => $ch) {
                if ($ch !== ' ') {
                    $this->assertTrue($box->contains($x, $y), "painted ($x, $y) outside a {$w}x{$h} box");
                }
            }
        }
        // A layout without netStats falls back to the same formula.
        $bare = Surface::new(40, 12);
        $panel->paint($bare->region($box), new PanelFrame(new Layout(40, 12, ['net' => $box]), $box, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host()));
        $this->assertSame($surface->plainLines(), $bare->plainLines());
    }

    /** @return iterable<string, array{int, int}> */
    public static function layouts(): iterable
    {
        yield '80x24' => [80, 24];
        yield '100x30' => [100, 30];
        yield '120x40' => [120, 40];
        yield '160x50' => [160, 50];
        yield '200x60' => [200, 60];
    }

    #[DataProvider('layouts')]
    public function testStatsRectMatchesTheFrameBuilderLayout(int $cols, int $rows): void
    {
        $layout = FrameBuilder::layout($cols, $rows, Config::new(), 8);
        $box = $layout->box('net');
        $this->assertNotNull($box);
        $this->assertNotNull($layout->netStats);
        $this->assertTrue(
            $layout->netStats->translate(-$box->x, -$box->y)->equals(NetView::statsRect($box->width, $box->height)),
        );
    }

    public function testDisplayIpCutsTheZoneAndControlCharacters(): void
    {
        $this->assertSame('fe80::1', NetView::displayIp('fe80::1%eth0'));
        $this->assertSame('2001:db8::7', NetView::displayIp('2001:db8::7'));
        $this->assertSame('10.0.0.1', NetView::displayIp("10.0\x00.0.1\x7f"));
        $this->assertStringNotContainsString("\x1b", NetView::displayIp("10.0\x1b[31m.0.1"), 'an escape is defused');
        $this->assertSame('', NetView::displayIp(''));
    }

    public function testScopedIpv6IsPaintedWithoutItsZone(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(160, 40, $config, 8);
        $ctx = new PanelContext($config, $layout, $layout->box('net'));
        $panel = NetPanel::new(ScriptedNetSource::of(new NetSnapshot(['eth0' => ScriptedNetSource::iface('eth0', 1.0, 1.0, 1, 1, ipv6: 'fe80::1%eth0')], 'eth0')));
        $panel = $panel->update(($panel->collect($ctx))(), $ctx)->panel;
        $this->assertInstanceOf(NetPanel::class, $panel);
        [$surface] = self::render(160, 40, $config, panel: $panel);
        $this->assertStringContainsString('┐fe80::1┌', implode("\n", $surface->plainLines()));
    }

    public function testResizeRepaintsFromTheSameHistory(): void
    {
        $config = Config::new();
        $big = FrameBuilder::layout(160, 50, $config, 8);
        $panel = self::panel($config, $big);
        [$s1, $b1] = self::render(160, 50, $config, panel: $panel);
        [$s2, $b2] = self::render(80, 24, $config, panel: $panel);
        [$s3, $b3] = self::render(160, 50, $config, panel: $panel);
        $this->assertNotSame($b1->width, $b2->width);
        $this->assertSame(self::boxGrid($s1, $b1), self::boxGrid($s3, $b3), 'growing back redraws the same frame');
        $this->assertStringContainsString('←b eth0 n→', self::boxGrid($s2, $b2));
    }

    public function testPanelsStandardRegistersTheNetPanel(): void
    {
        foreach ([true, false] as $fake) {
            $this->assertInstanceOf(NetPanel::class, \SugarCraft\Top\Panel\Panels::standard(Harness::host(), Config::new(), $fake)['net']);
        }
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
