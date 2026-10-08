<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gfx;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Panel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Drives one P-B panel outside the App: lays out a terminal, samples the
 * panel `$n` times through its own collect()/update(), paints the frame
 * chrome plus the panel, and compares box goldens under
 * tests/fixtures/panels/<box>/. Goldens fail when missing unless
 * CANDY_TOP_UPDATE_GOLDENS=1 (same rule as FrameSnapshotTest).
 */
final class PanelPaint
{
    public static function host(bool $sensors = true, int $cores = 8): HostInfo
    {
        return HostInfo::new('Ryzen 7 5800X', $cores, 'joe', 'box', $sensors, true);
    }

    public static function layout(int $cols, int $rows, Config $config, HostInfo $host): Layout
    {
        return FrameBuilder::layout($cols, $rows, $config, $host->coreCount, $config->bool('check_temp') && $host->hasSensors);
    }

    public static function context(string $box, Config $config, ?Layout $layout): PanelContext
    {
        return new PanelContext($config, $layout, $layout?->box($box));
    }

    /** Sample `$panel` `$n` times through its own Cmd, as the App would. */
    public static function feed(Panel $panel, Config $config, ?Layout $layout, int $n): Panel
    {
        for ($i = 0; $i < $n; $i++) {
            $ctx = self::context($panel->box(), $config, $layout);
            $cmd = $panel->collect($ctx);
            $msg = $cmd === null ? null : $cmd();
            if ($msg instanceof SampledMsg) {
                $panel = $panel->update($msg, $ctx)->panel;
            }
        }

        return $panel;
    }

    public static function surface(Panel $panel, Config $config, Layout $layout, HostInfo $host, ?Palette $palette = null, ColorProfile $profile = ColorProfile::TrueColor): Surface
    {
        $ink = Ink::new($palette ?? ThemeConfig::new(), $profile);
        $surface = Surface::new($layout->width, $layout->height, $ink->base());
        FrameBuilder::paintChrome($surface, $layout, $ink, $config, $host);
        $rect = $layout->box($panel->box());
        if ($rect !== null) {
            $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, FrameBuilder::border($config), $config, $host));
        }

        return $surface;
    }

    /** The box's rows as plain text. */
    public static function grid(Surface $surface, Rect $rect): string
    {
        $out = [];
        foreach (array_slice($surface->plainLines(), $rect->y, $rect->height) as $line) {
            $out[] = mb_substr($line, $rect->x, $rect->width);
        }

        return implode("\n", $out) . "\n";
    }

    /** The box's rendered rows (full terminal width) with SGR. */
    public static function sgr(Surface $surface, Rect $rect): string
    {
        return implode("\n", array_slice($surface->lines(), $rect->y, $rect->height)) . "\n";
    }

    public static function assertGolden(TestCase $test, string $box, string $name, string $actual): void
    {
        $path = __DIR__ . "/../../fixtures/panels/{$box}/{$name}";
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual);
            $test->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        TestCase::assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        TestCase::assertSame((string) file_get_contents($path), $actual);
    }
}
