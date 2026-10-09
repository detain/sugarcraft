<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Ipmi;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Tests\Panel\Gfx\PanelPaint;
use SugarCraft\Top\Theme\Palette;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\BorderFlow;
use SugarCraft\Top\View\BoxChrome;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * Drives the ipmi panel outside the App (phase 1: the box is not wired
 * into the layout yet): feeds it through its own collect()/update() with
 * a context whose box is a bare Rect, then paints the box outline (title
 * `ipmi`, in the `<family>_box` colour plus the BorderFlow sweep, as the
 * App will) and the panel into a Rect at the terminal's top-left.
 */
final class IpmiPaintKit
{
    /** ipmitool outputs of a capture dir, keyed for FakeIpmi::fromCaptures(). */
    public static function captures(string $dir): array
    {
        $read = static fn (string $glob): string => (string) @file_get_contents((glob("$dir/$glob") ?: [''])[0]);

        return [
            'mc' => $read('mc-info.txt'),
            'fru' => $read('fru-print-0.txt'),
            'lan' => $read('lan-print-*.txt'),
            'dcmi' => $read('dcmi-power-reading.txt'),
            'chassis' => $read('chassis-status.txt'),
            'sel' => $read('sel-info.txt'),
            'sel_last' => $read('sel-elist-last-5.txt'),
            'sensors' => $read('sensor-list.txt'),
        ];
    }

    public static function context(Config $config, int $w, int $h): PanelContext
    {
        return new PanelContext($config, null, Rect::new(0, 0, $w, $h));
    }

    public static function feed(IpmiPanel $panel, Config $config, int $w, int $h, int $n): IpmiPanel
    {
        for ($i = 0; $i < $n; $i++) {
            $ctx = self::context($config, $w, $h);
            $cmd = $panel->collect($ctx);
            $msg = $cmd === null ? null : $cmd();
            if ($msg instanceof SampledMsg) {
                $next = $panel->update($msg, $ctx)->panel;
                \assert($next instanceof IpmiPanel);
                $panel = $next;
            }
        }

        return $panel;
    }

    public static function surface(
        IpmiPanel $panel,
        Config $config,
        int $cols,
        int $rows,
        int $w,
        int $h,
        ?Palette $palette = null,
        ColorProfile $profile = ColorProfile::TrueColor,
        string $family = 'net',
    ): Surface {
        $ink = Ink::new($palette ?? ThemeConfig::new(), $profile);
        $surface = Surface::new($cols, $rows, $ink->base());
        $rect = Rect::new(0, 0, min($w, $cols), min($h, $rows));
        $border = FrameBuilder::border($config);
        $line = $ink->fg($family . '_box');
        BoxChrome::paint($surface, $rect, $line, $ink, $border, true, 'ipmi', '', 0, $config->ttyMode());
        $layout = PanelPaint::layout(max(80, $cols), max(24, $rows), $config, PanelPaint::host());
        $panel->paint($surface->region($rect), new PanelFrame($layout, $rect, $ink, $border, $config, PanelPaint::host(), 'ipmi'));
        $p = $ink->palette();
        if (BorderFlow::enabled($ink, $config) && $p instanceof ThemeConfig && ($flow = $p->boxFlow($family)) !== null) {
            BorderFlow::sweep($surface, $rect, $line, $flow, $ink->fg('div_line'), $p->color('div_line'), $ink->profile());
        }

        return $surface;
    }
}
