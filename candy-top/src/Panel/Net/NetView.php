<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Net;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints a {@see NetPanel} into its box — the per-frame half of btop's
 * Net::draw. The App has already drawn the box, its `net` title and the
 * stats sub-box outline with its download / upload titles (btop's cached
 * `Net::box`); this adds the title-row buttons, the address, the two
 * graphs with their scale labels, and the stats text.
 *
 * Box-local coordinates: btop's `Mv::to(y + r, x + c)` is local (c, r).
 *
 * Mirrors aristocratos/btop Net::draw (src/btop_draw.cpp:1498-1593).
 */
final class NetView
{
    private const DOWN_SYMBOL = '▼';
    private const UP_SYMBOL = '▲';

    private function __construct()
    {
    }

    public static function paint(Region $region, PanelFrame $frame, NetPanel $panel): void
    {
        $iface = $panel->selectedInterface();
        if ($iface === null) {
            return;
        }
        $width = $region->width();
        $height = $region->height();
        $config = $frame->config;
        $ink = $frame->ink;
        $swap = $config->bool('swap_upload_download');
        $human = Humanizer::new($config->bool('base_10_sizes'), $config->string('base_10_bitrate'));

        self::paintTitle($region, $frame, $panel);

        $stats = $frame->layout->netStats !== null
            ? $frame->local($frame->layout->netStats)
            : self::statsRect($width, $height);
        $bWidth = $stats->width;
        $bHeight = $stats->height;
        $graphWidth = max(1, $width - $bWidth - 2);
        $dHeight = (int) round(($height - 2) / 2);
        $uHeight = $height - 2 - $dHeight;
        $family = $config->graphSymbolFor('net');
        $statsRegion = $region->sub($stats);

        foreach ([NetPanel::DOWNLOAD, NetPanel::UPLOAD] as $dir) {
            $isDown = $dir === NetPanel::DOWNLOAD;
            $onTop = $isDown !== $swap;
            $max = $panel->graphMax($dir, $config);

            // Download is u_graph_height tall, upload d_graph_height (btop's naming).
            $graph = DualSampleGraph::new(
                $graphWidth,
                max(1, $isDown ? $uHeight : $dHeight),
                $family,
                invert: $isDown ? $swap : !$swap,
                noZero: true,
                maxValue: $max,
            );
            $stops = self::stops($frame, $dir);
            if ($stops !== null) {
                $graph = $graph->withGradient($stops);
            }
            $history = $panel->history($dir);
            if ($history !== []) {
                $graph = $graph->withData(...$history);
            }
            $row = $onTop ? 1 : $uHeight + 1 + ($swap ? $height % 2 : 0);
            foreach (explode("\n", $graph->render($ink->profile())) as $k => $line) {
                $region->ansi(1, $row + $k, $line);
            }
            $labelRow = 1 + (($dir === NetPanel::UPLOAD) === !$swap ? $height - 3 : 0);
            $region->put(1, $labelRow, $human->format($max, true), $ink->fg('graph_text'));

            self::paintStats($statsRegion, $frame, $panel, $human, $dir, $onTop, $bWidth, $bHeight);
        }
    }

    /**
     * The stats sub-box in box-local coordinates, btop's b_x/b_y/b_width/
     * b_height (btop_draw.cpp:2522-2525) — what FrameBuilder puts in
     * {@see \SugarCraft\Top\View\Layout::$netStats}; used when a layout
     * carries none.
     */
    public static function statsRect(int $width, int $height): Rect
    {
        $bWidth = $width > 45 ? 27 : 19;
        $bHeight = $height > 10 ? 9 : $height - 2;

        return Rect::new(
            $width - $bWidth - 1,
            intdiv($height - 2, 2) - intdiv($bHeight, 2) + 1,
            $bWidth,
            max(0, $bHeight),
        );
    }

    /**
     * The address as the title prints it: btop's inet_ntop text never
     * carries an IPv6 zone, so anything from `%` on is cut; control
     * characters are stripped so the address cannot smuggle an escape.
     */
    public static function displayIp(string $ip): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]/', '', explode('%', $ip, 2)[0]);
    }

    private static function paintTitle(Region $region, PanelFrame $frame, NetPanel $panel): void
    {
        $iface = $panel->selectedInterface();
        if ($iface === null) {
            return;
        }
        $ink = $frame->ink;
        $config = $frame->config;
        $width = $region->width();
        $name = NetPanel::ifaceLabel($iface->name);
        $iSize = Width::string($name);
        $hi = $ink->fg('hi_fg');
        $title = $ink->fg('title');

        self::embed($region, $frame, NetButtons::ifaceAt($width, $iSize), Symbols::BOLD . $hi . '←b ' . $title . $name . $hi . ' n→');
        self::embed($region, $frame, NetButtons::zeroAt($width, $iSize), ($panel->zeroed() ? Symbols::BOLD : '') . self::button('net.zero', 'z', $frame));
        if (NetButtons::hasAuto($width, $iSize)) {
            self::embed($region, $frame, NetButtons::autoAt($width, $iSize), ($config->bool('net_auto') ? Symbols::BOLD : '') . self::button('net.auto', 'a', $frame));
        }
        if (NetButtons::hasSync($width, $iSize)) {
            self::embed($region, $frame, NetButtons::syncAt($width, $iSize), ($config->bool('net_sync') ? Symbols::BOLD : '') . self::button('net.sync', 'y', $frame));
        }

        // btop #1573 (net_hide_ip): IPv4, else IPv6.
        $ip = self::displayIp($iface->ip());
        if (!$config->bool('net_hide_ip') && NetButtons::ipFits($width, $iSize, strlen($ip))) {
            self::embed($region, $frame, NetButtons::IP_AT, $title . Symbols::BOLD . $ip);
        }
    }

    /**
     * One stats block (btop_draw.cpp:1567-1590): the speed with its bit
     * rate, the peak and the total; three rows on a stats box at least 8
     * tall, two from 6, one below that.
     */
    private static function paintStats(Region $stats, PanelFrame $frame, NetPanel $panel, Humanizer $human, string $dir, bool $onTop, int $bWidth, int $bHeight): void
    {
        $wide = $bWidth >= 20;
        $speed = $panel->speed($dir);
        $symbol = $dir === NetPanel::UPLOAD ? self::UP_SYMBOL : self::DOWN_SYMBOL;
        $sgr = $frame->ink->fg('main_fg');

        $first = $onTop ? 1 : $bHeight - intdiv($bHeight, 2);
        $line = $symbol . ' ' . self::ljust($human->format($speed, perSecond: true), 10)
            . ($wide ? self::rjust('(' . $human->format($speed, bit: true, perSecond: true) . ')', 13) : '');
        $stats->put(1, $first, $line, $sgr);
        if ($bHeight >= 8) {
            $top = $human->format($panel->top($dir), bit: true, perSecond: true);
            $stats->put(1, $first + 1, $symbol . ' ' . Lang::t('net.top') . ' ' . self::rjust('(' . $top, $wide ? 17 : 9) . ')', $sgr);
        }
        if ($bHeight >= 6) {
            $total = $human->format($panel->total($dir));
            $stats->put(1, $first + 1 + ($bHeight >= 8 ? 1 : 0), $symbol . ' ' . Lang::t('net.total') . ' ' . self::rjust($total, $wide ? 16 : 8), $sgr);
        }
    }

    /**
     * A title-row button label: btop hard-codes four-letter words at fixed
     * columns, so a translation is held to four cells; the hotkey letter
     * is lit wherever it falls ({@see FrameBuilder::hotkey()}).
     */
    private static function button(string $langKey, string $key, PanelFrame $frame): string
    {
        return FrameBuilder::hotkey(Width::truncate(Lang::t($langKey), 4), $key, $frame->ink);
    }

    /**
     * `┐inner┌` on the title row at local column `$x`, kept off the box
     * corners like {@see \SugarCraft\Top\View\BoxChrome::embed()}.
     */
    private static function embed(Region $region, PanelFrame $frame, int $x, string $inner): void
    {
        $edge = $region->sub(Rect::new(1, 0, max(0, $region->width() - 2), 1));
        $line = $frame->ink->fg('net_box');
        [$open, $close] = $frame->border->embedJunctions(false);
        $col = $x - 1;
        $col += $edge->put($col, 0, $open, $line);
        $col += $edge->ansi($col, 0, $inner);
        $edge->put($col, 0, $close, $line);
    }

    /**
     * The theme's 101-stop ramp for `$name`, or null when it has none.
     *
     * @return list<Color>|null
     */
    private static function stops(PanelFrame $frame, string $name): ?array
    {
        $palette = $frame->ink->palette();
        if (!$palette->hasGradient($name)) {
            return null;
        }
        $stops = [];
        for ($i = 0; $i <= 100; $i++) {
            $color = $palette->at($name, $i);
            if ($color === null) {
                return null;
            }
            $stops[] = $color;
        }

        return $stops;
    }

    /** btop `ljust` (limit on): pad right, or cut to `$width`. */
    private static function ljust(string $s, int $width): string
    {
        return strlen($s) > $width ? substr($s, 0, $width) : str_pad($s, $width);
    }

    /** btop `rjust` (limit on): pad left, or cut to `$width`. */
    private static function rjust(string $s, int $width): string
    {
        return strlen($s) > $width ? substr($s, 0, $width) : str_pad($s, $width, ' ', STR_PAD_LEFT);
    }
}
