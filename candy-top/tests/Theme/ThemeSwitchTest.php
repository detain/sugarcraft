<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Theme;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Cpu\BorderBattery;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeRegistry;
use SugarCraft\Top\View\Ink;

/**
 * btop #1849: switching color_theme must re-resolve every gradient, the
 * battery meter's included, instead of keeping the previous theme's stops.
 * The meter is btop `Meter{10, "cpu", invert}` (BorderBattery →
 * PositionMeter::render(ink, 10, pct, 'cpu', true)): cell i (1..10) sits at
 * y = round(i * 100 / 10) and takes the cpu stop at 100 - y, so a full
 * meter reads cpu@90, 80, …, 0.
 */
final class ThemeSwitchTest extends TestCase
{
    private static function registry(): ThemeRegistry
    {
        return ThemeRegistry::new(null, []);
    }

    public function testSwitchingThemeReResolvesEveryGradient(): void
    {
        $r = self::registry();
        self::assertContains('mellow', $r->names());
        $from = $r->load('nord');
        $to = $r->load('mellow');

        self::assertSame($from->gradientNames(), $to->gradientNames());
        $ramp = static fn (Palette $p, string $g): array => array_map(
            static fn (int $pct): ?string => $p->at($g, $pct)?->toHex(),
            range(0, 100),
        );
        foreach ($to->gradientNames() as $g) {
            self::assertNotSame($ramp($from, $g), $ramp($to, $g), "$g gradient follows the theme switch");
        }

        // And back again: the original palette is restored exactly.
        self::assertEquals($from, $r->load('nord'));
    }

    public function testBatteryMeterFollowsTheThemeSwitch(): void
    {
        $r = self::registry();
        $nord = Ink::new($r->load('nord'));
        $mellow = Ink::new($r->load('mellow'));

        $before = PositionMeter::render($nord, 10, 100, 'cpu', true);
        $after = PositionMeter::render($mellow, 10, 100, 'cpu', true);
        self::assertNotSame($before, $after);

        $expected = '';
        foreach ([90, 80, 70, 60, 50, 40, 30, 20, 10, 0] as $stop) {
            $expected .= $mellow->gradient('cpu', $stop) . PositionMeter::GLYPH;
        }
        self::assertSame($expected . "\x1b[0m", $after);
        self::assertStringStartsWith($mellow->gradient('cpu', 90) . PositionMeter::GLYPH, $after);
        self::assertNotSame($nord->gradient('cpu', 90), $mellow->gradient('cpu', 90));
    }

    public function testBorderBatteryPaintFollowsTheThemeSwitch(): void
    {
        $config = Config::new();
        $host = PanelPaint::host();
        $layout = PanelPaint::layout(120, 40, $config, $host);
        // FakeBattery after 4 samples reads 86 %: 8 filled cells (cpu@90..20) + 2 meter_bg.
        $panel = PanelPaint::feed(
            CpuPanel::new(FakeCpu::new(8))->withBattery(BorderBattery::standard($config, true)),
            $config,
            $layout,
            4,
        );
        $y = $layout->box('cpu')->y;
        $r = self::registry();

        $cells = static function (string $theme) use ($r, $panel, $config, $layout, $host, $y): array {
            $line = PanelPaint::surface($panel, $config, $layout, $host, $r->load($theme))->lines()[$y];
            self::assertStringContainsString('BAT▼ 86%', $line);
            preg_match_all('/(\x1b\[38;2;\d+;\d+;\d+m)(■+)/u', $line, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as [, $fg, $run]) {
                array_push($out, ...array_fill(0, mb_strlen($run), $fg));
            }

            return $out;
        };

        $expect = static function (Ink $ink): array {
            $out = [];
            foreach ([90, 80, 70, 60, 50, 40, 30, 20] as $stop) {
                $out[] = $ink->gradient('cpu', $stop);
            }

            return [...$out, $ink->fg('meter_bg'), $ink->fg('meter_bg')];
        };

        $nord = $cells('nord');
        $mellow = $cells('mellow');
        self::assertSame($expect(Ink::new($r->load('nord'))), $nord);
        self::assertSame($expect(Ink::new($r->load('mellow'))), $mellow);
        self::assertNotSame($nord, $mellow);
    }
}
