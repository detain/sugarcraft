<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\TickRequest;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\App;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\ClockTickMsg;
use SugarCraft\Top\Panel\Panels;
use SugarCraft\Top\Tests\Support\Cmds;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Surface;

/**
 * candy-top's default theme, `pastel`: the full `--fake` frame at 120x40 as
 * SGR (palette resolved from Config::new()'s color_theme through the real
 * ThemeRegistry, so this golden is what a fresh install draws), its border
 * flows, its 256-colour fallback and its legibility.
 *
 * Golden: tests/fixtures/frames/pastel-120x40.sgr; regenerate with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/View/PastelThemeFrameTest.php`.
 */
final class PastelThemeFrameTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function testPastelIsTheDefaultAndResolves(): void
    {
        $this->assertSame('pastel', Config::new()->colorTheme());
        $palette = ThemeRegistry::new(null, [])->load(Config::new()->colorTheme());
        $this->assertSame('pastel', $palette->name());
        $this->assertSame('Default', ThemeRegistry::new(null, [])->load('Default')->name(), 'btop\'s Default stays selectable');
    }

    public function testSgrGolden120x40(): void
    {
        $this->assertGolden(__DIR__ . '/../fixtures/frames/pastel-120x40.sgr', (string) self::running(Config::new())->view() . "\n");
    }

    public function testOutlinesFlowInTruecolor(): void
    {
        $app = self::running(Config::new());
        $surface = $app->surface();
        $this->assertNotNull($surface);
        $styles = [];
        foreach ($surface->plainLines() as $y => $line) {
            foreach (mb_str_split($line) as $x => $glyph) {
                $cp = mb_ord($glyph);
                if ($cp >= 0x2500 && $cp <= 0x257F) {
                    $styles[$surface->style($x, $y)] = true;
                }
            }
        }
        $this->assertGreaterThan(40, count($styles), 'the outlines carry a gradient, not four flat colours');
        // Every box's top-left corner is exactly its *_box colour (flow t = 0).
        foreach (['cpu', 'mem', 'net', 'proc'] as $box) {
            $rect = $app->layout?->box($box);
            $this->assertNotNull($rect);
            $this->assertSame(Surface::canonical($app->ink->fg($box . '_box')), $surface->style($rect->x, $rect->y), $box);
        }
    }

    /**
     * gpu and ctr boxes flow in their family colours (cpu / proc) without
     * their views knowing; under a menu with a frozen backdrop the dim pass
     * wins (no flow leaks through), and closing the menu brings it back.
     */
    public function testGpuAndCtrBoxesFlowAndTheFrozenBackdropDims(): void
    {
        $config = Config::new()->with('shown_boxes', 'cpu mem net proc ctr gpu0')->with('background_update', false);
        $app = self::running($config, ColorProfile::TrueColor, 200, 60, 2);
        $surface = $app->surface();
        $layout = $app->layout;
        $this->assertNotNull($surface);
        $this->assertNotNull($layout);
        $ink = $app->ink;
        foreach (['gpu0' => 'cpu_box', 'ctr' => 'proc_box'] as $box => $key) {
            $rect = $layout->box($box);
            $this->assertNotNull($rect, $box);
            $flat = Surface::canonical($ink->fg($key));
            $this->assertSame($flat, $surface->style($rect->x, $rect->y), "{$box} starts at {$key}");
            $corner = $surface->style($rect->right() - 1, $rect->bottom() - 1);
            $this->assertNotSame($flat, $corner, "{$box} flows to its end colour");
            $this->assertStringStartsWith("\x1b[38;2;", $corner);
        }

        [$menu] = $app->update(new KeyMsg(KeyType::Char, 'o'));
        $this->assertNotNull($menu->overlay());
        $this->assertTrue($menu->freezesBackdrop());
        $dimmed = $menu->surface();
        $this->assertNotNull($dimmed);
        $dim = Surface::canonical($ink->fg('inactive_fg'));
        $proc = $layout->box('proc');
        $this->assertNotNull($proc);
        // The proc box's bottom-right corner sits outside the centred menu.
        $this->assertSame($dim, $dimmed->style($proc->right() - 1, $proc->bottom() - 1), 'the backdrop is dimmed flat, flows included');

        [$closed] = $menu->update(new KeyMsg(KeyType::Escape));
        $this->assertNull($closed->overlay());
        $this->assertSame($surface->render(), $closed->surface()?->render(), 'closing the menu restores the flowing frame');
    }

    public function testLowcolorFallsBackToFlatOutlinesWithTheSameGrid(): void
    {
        $true = self::running(Config::new());
        $low = self::running(Config::new()->with('truecolor', false)->with('lowcolor', true), ColorProfile::Ansi256);
        $this->assertSame($true->surface()?->plainLines(), $low->surface()?->plainLines());
        $this->assertDoesNotMatchRegularExpression('/[34]8;2;/', (string) $low->view(), 'no 24-bit colour without truecolor');
    }

    /** WCAG 2 contrast: the text that must be read sits well clear of its background. */
    public function testLegibility(): void
    {
        $t = ThemeConfig::fromFile(ThemeRegistry::bundledDir() . '/pastel.theme');
        $c = static fn (string $k): Color => $t->color($k) ?? throw new \LogicException($k);
        $bg = $c('main_bg');
        $this->assertGreaterThanOrEqual(7.0, self::contrast($c('main_fg'), $bg), 'main_fg (AAA)');
        $this->assertGreaterThanOrEqual(7.0, self::contrast($c('title'), $bg), 'title');
        $this->assertGreaterThanOrEqual(7.0, self::contrast($c('selected_fg'), $c('selected_bg')), 'selected row');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($c('hi_fg'), $bg), 'hotkeys');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($c('graph_text'), $bg), 'graph text');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($c('followed_fg'), $c('followed_bg')), 'followed row');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($c('proc_banner_fg'), $c('proc_banner_bg')), 'banner');
        $this->assertGreaterThanOrEqual(4.5, self::contrast($c('proc_banner_fg'), $c('proc_pause_bg')), 'pause banner');
        $this->assertGreaterThanOrEqual(3.5, self::contrast($c('inactive_fg'), $bg), 'inactive text is dim but readable');
        // Non-text UI (WCAG 1.4.11): inner divider boxes and empty meter tracks must not vanish.
        $this->assertGreaterThanOrEqual(3.0, self::contrast($c('div_line'), $bg), 'div_line');
        $this->assertGreaterThanOrEqual(3.0, self::contrast($c('meter_bg'), $bg), 'meter_bg');
        foreach (ThemeConfig::FAMILIES as $family) {
            $this->assertGreaterThanOrEqual(4.5, self::contrast($t->at($family, 100) ?? $bg, $bg), "{$family} high end");
        }
    }

    private static function contrast(Color $a, Color $b): float
    {
        $l = static function (Color $c): float {
            $lin = static fn (int $v): float => ($s = $v / 255) <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;

            return 0.2126 * $lin($c->r) + 0.7152 * $lin($c->g) + 0.0722 * $lin($c->b);
        };
        [$hi, $lo] = [max($l($a), $l($b)), min($l($a), $l($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    private static function running(Config $config, ColorProfile $profile = ColorProfile::TrueColor, int $cols = 120, int $rows = 40, int $gpus = 0): App
    {
        $config = $config->withShownBoxesSettled($gpus);
        $host = Harness::host();
        $palette = ThemeRegistry::new(null, [])->load($config->colorTheme(), $config->bool('theme_background'), $config->ttyMode());
        $app = App::start(
            $config,
            $palette,
            $host,
            Panels::standard($host, $config, true),
            static fn (): ClockTickMsg => new ClockTickMsg(Harness::TIME, 3600.0),
            $profile,
        );
        [$app] = $app->update(new WindowSizeMsg($cols, $rows));
        [$app] = $app->update(new ClockTickMsg(Harness::TIME, 3600.0));
        foreach (Cmds::run($app->init()) as $msg) {
            if (!$msg instanceof TickRequest) {
                [$app] = $app->update($msg);
            }
        }

        return $app;
    }

    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        // A missing golden is a failure, never a silent first-run pass.
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}
