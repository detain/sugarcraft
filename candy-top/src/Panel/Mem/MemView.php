<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Net\Humanizer;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\Gfx\Trans;
use SugarCraft\Top\Panel\MemPanel;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints a {@see MemPanel}: a port of btop Mem::draw's memory half
 * (src/btop_draw.cpp:1237-1389) plus the calcSizes mem sub-geometry
 * (btop_draw.cpp calcSizes "Mem": item_height, mem_size, mem_meter,
 * graph_height), recomputed per paint because it depends on has_swap
 * (data) and the current config. Local (r, c) = btop `Mv::to(y + r, x + c)`.
 *
 * The box outline, the `disks` button and the mem|disks divider are App
 * chrome; the `io` button (shown with the disks half) is painted here, as
 * btop's Mem::draw does. Right of the divider belongs to the
 * {@see DisksSection} (P-D).
 */
final class MemView
{
    private const DIV_LEFT = '├';

    private const DIV_RIGHT = '┤';

    private function __construct()
    {
    }

    public static function paint(Region $box, PanelFrame $f, MemPanel $p): void
    {
        $width = $box->width();
        $height = $box->height();
        if ($width < 3 || $height < 3) {
            return;
        }
        $layout = $f->layout;
        if ($layout->showDisks && $layout->memDivider !== null) {
            self::ioButton($box, $f);
        }
        $mem = $p->snapshot();
        if ($mem !== null) {
            $memWidth = $layout->memWidth > 0 ? $layout->memWidth : $width - 1;
            $selected = $f->config->memSelected();
            if ($selected !== 'default' && ($selected !== 'swap_used' || $mem->swapTotal > 0)) {
                self::focused($box, $f, $p, $mem, $memWidth, $selected);
            } else {
                self::stacked($box, $f, $p, $mem, $memWidth);
            }
        }
        if ($layout->showDisks && $layout->memDivider !== null) {
            $x = $layout->memDivider - $f->box->x;
            $p->disks()?->paint($box, $f, Rect::new($x, 0, $width - $x, $height));
        }
    }

    /**
     * btop calcSizes mem geometry for this box and snapshot.
     *
     * @return array{itemHeight: int, memSize: int, meter: int, graphHeight: int, swap: bool, zswap: bool}
     */
    public static function geometry(int $height, int $memWidth, MemorySnapshot $mem, bool $swapDisk, bool $graphs, bool $zswapRow): array
    {
        $swap = $mem->swapTotal > 0 && !$swapDisk;
        // #1739: the zswap row is one more item in the swap section.
        $itemHeight = $swap ? 6 + ($zswapRow ? 1 : 0) : 4;
        $memSize = $height - ($swap ? 3 : 2) > 2 * $itemHeight ? 3 : ($memWidth > 25 ? 2 : 1);
        $meter = max(0, $memWidth - ($memSize > 2 ? 7 : 17));
        if ($memSize === 1) {
            $meter += 6;
        }
        $graphHeight = 0;
        if ($graphs) {
            $graphHeight = max(1, (int) round((($height - ($swap ? 2 : 1)) - ($memSize === 3 ? 2 : 1) * $itemHeight) / $itemHeight));
            if ($graphHeight > 1) {
                $meter += 6;
            }
        }

        return ['itemHeight' => $itemHeight, 'memSize' => $memSize, 'meter' => $meter, 'graphHeight' => $graphHeight, 'swap' => $swap, 'zswap' => $zswapRow];
    }

    private static function stacked(Region $box, PanelFrame $f, MemPanel $p, MemorySnapshot $mem, int $memWidth): void
    {
        $config = $f->config;
        $ink = $f->ink;
        $height = $box->height();
        $showSwap = $config->bool('show_swap');
        $swapDisk = $config->bool('swap_disk');
        $zswapRow = $showSwap && $mem->swapTotal > 0 && !$swapDisk && $config->bool('show_zswap') && $mem->hasZswap();
        $geo = self::geometry($height, $memWidth, $mem, $swapDisk, $config->bool('mem_graphs'), $zswapRow);
        $gh = $geo['graphHeight'];
        $memSize = $geo['memSize'];
        $meterW = $geo['meter'];
        $bigMem = $memWidth > 21;
        $family = $config->graphSymbolFor('mem');
        $base10 = $config->bool('base_10_sizes');
        $divider = $gh > 0;
        $mainFg = $ink->fg('main_fg');
        // The colour btop's stream is in when a title/value is written: the
        // Total/Swap lines and the divider end in main_fg, a graph or meter
        // ends in Fx::reset (the base style, ''), a mem_size <= 2 value
        // leaves `title` set for the next row's label.
        $state = $mainFg;

        self::total($box, $f, $mem, $memWidth, $base10);

        $names = ['used', 'available', 'cached', 'free'];
        if ($showSwap && $mem->swapTotal > 0 && !$swapDisk) {
            $names = [...$names, 'swap_used', ...($zswapRow ? ['zswap'] : []), 'swap_free'];
        }
        $cy = 1;
        foreach ($names as $name) {
            if ($cy > $height - 4) {
                break;
            }
            if ($name === 'swap_used') {
                if ($cy > $height - 5) {
                    break;
                }
                if ($height - $cy > 6) {
                    if ($divider) {
                        self::divider($box, $f, 1 + $cy, $memWidth);
                    }
                    $cy++;
                }
                $box->put(2, 1 + $cy, Lang::t('mem.swap'), $ink->fg('title') . Symbols::BOLD);
                $total = Humanizer::new($base10)->format($mem->swapTotal, false);
                $box->put(2 + Width::string(Lang::t('mem.swap')), 1 + $cy, Just::right($total, $memWidth - 8), $ink->fg('title') . Symbols::BOLD);
                $cy++;
                $state = $mainFg;
            }
            [$title, $bytes, $series, $gradient] = self::item($name, $mem, $zswapRow, $f);
            $humanized = Humanizer::new($base10)->format($bytes, false);
            $percent = $p->history()->last($series) ?? 0;
            $row = 1 + $cy;
            if ($memSize > 2) {
                if ($divider) {
                    self::divider($box, $f, $row, $memWidth);
                    $state = $mainFg;
                }
                $box->put(2, $row, mb_substr($title, 0, $bigMem ? 10 : 5) . ':', $state);
                $at = $memWidth - 1 - strlen($humanized);
                if ($divider) {
                    Trans::put($box, $at, $row, $humanized, $state);
                } else {
                    $offset = max(0, 9 - strlen($humanized));
                    $box->put($at - $offset, $row, str_repeat(' ', $offset) . $humanized, $state);
                }
                $gx = $gh >= 2 ? 1 : 2;
                $end = self::graphics($box, $f, $p, $gx, $row + 1, $meterW, $gh, $series, $gradient, $percent, $family);
                $pctAt = $gh >= 2 ? [2, $row + 1] : [$end, $row + 1];
                $box->put($pctAt[0], $pctAt[1], Just::right($percent . '%', 4));
                $state = '';
                $cy += $gh === 0 ? 2 : $gh + 1;
            } else {
                $label = Just::left($title, $memSize > 1 ? 5 : 1) . ($gh >= 2 ? '' : ' ');
                $x = 2 + $box->put(2, $row, $label, $state);
                $end = self::graphics($box, $f, $p, $x, $row, $meterW, $gh, $series, $gradient, $percent, $family);
                $box->put($end, $row + max(0, $gh - 1), Just::right($humanized, $memSize > 1 ? 9 : 7), $ink->fg('title'));
                $state = $ink->fg('title');
                $cy += $gh === 0 ? 1 : $gh;
            }
        }
        if ($divider && $cy < $height - 2) {
            self::divider($box, $f, 1 + $cy, $memWidth);
        }
    }

    /**
     * btop #1747: one metric as a single `mem_width-2 x height-4` graph
     * under its Total/Swap header and value row.
     */
    private static function focused(Region $box, PanelFrame $f, MemPanel $p, MemorySnapshot $mem, int $memWidth, string $selected): void
    {
        $config = $f->config;
        $ink = $f->ink;
        $base10 = $config->bool('base_10_sizes');
        $zswapUsed = $config->bool('show_zswap') && $mem->hasZswap();
        if ($selected === 'swap_used') {
            $box->put(2, 1, Lang::t('mem.swap'), $ink->fg('title') . Symbols::BOLD);
            $box->put(2 + Width::string(Lang::t('mem.swap')), 1, Just::right(Humanizer::new($base10)->format($mem->swapTotal, false), $memWidth - 8), $ink->fg('title') . Symbols::BOLD);
        } else {
            self::total($box, $f, $mem, $memWidth, $base10);
        }
        [$title, $bytes, $series, $gradient] = self::item($selected, $mem, $zswapUsed, $f);
        $humanized = Humanizer::new($base10)->format($bytes, false);
        $box->put(2, 2, mb_substr($title, 0, $memWidth > 21 ? 10 : 5) . ':', $ink->fg('main_fg'));
        $box->put($memWidth - 1 - strlen($humanized), 2, $humanized, $ink->fg('main_fg'));
        $gh = max(1, $box->height() - 4);
        $graph = DualSampleGraph::new(max(1, $memWidth - 2), $gh, $config->graphSymbolFor('mem'))->withData(...$p->history()->series($series));
        foreach (TintedGraph::lines($graph, $ink, $gradient) as $r => $line) {
            $box->ansi(2, 3 + $r, $line);
        }
        // After the graph's Fx::reset: the base style.
        $box->put(2, 3, Just::right(($p->history()->last($series) ?? 0) . '%', 4));
    }

    /** `Total:` + humanized MemTotal right-aligned to the mem column. */
    private static function total(Region $box, PanelFrame $f, MemorySnapshot $mem, int $memWidth, bool $base10): void
    {
        $sgr = $f->ink->fg('title') . Symbols::BOLD;
        $label = Lang::t('mem.total');
        $box->put(2, 1, $label, $sgr);
        $box->put(2 + Width::string($label), 1, Just::right(Humanizer::new($base10)->format($mem->total, false), $memWidth - 9), $sgr);
    }

    /**
     * Title, bytes, history series and gradient for one mem class.
     * swap_used shows on-disk swap while the #1739 zswap row is shown.
     *
     * @return array{0: string, 1: int, 2: string, 3: string}
     */
    private static function item(string $name, MemorySnapshot $mem, bool $zswap, PanelFrame $f): array
    {
        return match ($name) {
            'swap_used' => $zswap
                ? [Lang::t('mem.title.used'), $mem->swapUsedOnDisk(), 'swap_used_disk', 'used']
                : [Lang::t('mem.title.used'), $mem->swapUsed, 'swap_used', 'used'],
            'swap_free' => [Lang::t('mem.title.free'), $mem->swapFree, 'swap_free', 'free'],
            'zswap' => [Lang::t('mem.title.zswap'), max(0, $mem->zswap), 'zswap', TintedGraph::gradientName($f->ink, ['zswap', 'cached', 'used'])],
            'available' => [Lang::t('mem.title.available'), $mem->available, 'available', 'available'],
            'cached' => [Lang::t('mem.title.cached'), $mem->cached, 'cached', 'cached'],
            'free' => [Lang::t('mem.title.free'), $mem->free, 'free', 'free'],
            default => [Lang::t('mem.title.used'), $mem->used, 'used', 'used'],
        };
    }

    /**
     * The class's meter (mem_graphs off) or graph at ($x, $y); returns the
     * column after it on its last row.
     */
    private static function graphics(Region $box, PanelFrame $f, MemPanel $p, int $x, int $y, int $width, int $gh, string $series, string $gradient, int $percent, string $family): int
    {
        if ($width < 1) {
            return $x;
        }
        if ($gh === 0) {
            return $x + $box->ansi($x, $y, PositionMeter::render($f->ink, $width, $percent, $gradient));
        }
        $graph = DualSampleGraph::new($width, max(1, $gh), $family)->withData(...$p->history()->series($series));
        foreach (TintedGraph::lines($graph, $f->ink, $gradient) as $r => $line) {
            $box->ansi($x, $y + $r, $line);
        }

        return $x + $width;
    }

    /** `├────────┤` across the mem column (the right junction sits on the divider or border). */
    private static function divider(Region $box, PanelFrame $f, int $row, int $memWidth): void
    {
        $ink = $f->ink;
        $box->put(0, $row, self::DIV_LEFT, $ink->fg('mem_box'));
        $box->put(1, $row, str_repeat(Symbols::H_LINE, max(0, $memWidth - 1)), $ink->fg('div_line'));
        $box->put($memWidth, $row, self::DIV_RIGHT, $ink->fg($f->layout->showDisks ? 'div_line' : 'mem_box'));
    }

    /** `┐io┌` left of the top-right corner while the disks half is shown (bold while io_mode). */
    private static function ioButton(Region $box, PanelFrame $f): void
    {
        $ink = $f->ink;
        $x = $box->width() - 6;
        [$open, $close] = $f->border->embedJunctions(false);
        $line = $ink->fg('mem_box');
        $x += $box->put($x, 0, $open, $line);
        $bold = $f->config->bool('io_mode') ? Symbols::BOLD : '';
        $x += $box->ansi($x, 0, $bold . FrameBuilder::hotkey(Lang::t('mem.io'), 'i', $ink));
        $box->put($x, 0, $close, $line);
    }
}
