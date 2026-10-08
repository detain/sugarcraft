<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Mem;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\Gfx\Trans;
use SugarCraft\Top\Panel\Net\Humanizer;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints {@see Disks}: a port of btop Mem::draw's disks half
 * (btop_draw.cpp:1394-1481) with its calcSizes inputs (disks_width,
 * disk_meter, btop_draw.cpp:2455-2484) and Mem::draw's io graph setup
 * (disks_io_h, io_graph_speeds, btop_draw.cpp:1281-1330).
 *
 * Coordinates: btop `Mv::to(y + 1 + cy, x + 1 + cx)` with `cx = mem_width`
 * is local cell (1 + cy, a + 1), where `a` = `$area->x` is the divider
 * column. Every disk opens with `├─name ... total┤` across the section,
 * then (meter mode) an optional ` IO% ` activity graph, the Used and Free
 * meters; or (io_mode) the activity graph plus mirrored read/write
 * graphs (`io_graph_combined`: one combined graph).
 *
 * Byte-count positions use `strlen()` as btop's `string::size()` does,
 * so a `▼▲` (3 bytes each) shifts the centred io readout like btop's.
 *
 * Deviations: io values are bytes per SECOND (btop graphs and prints
 * bytes per update interval; io_graph_speeds is documented in MiB/s, so
 * the graph ceiling now means what the option says). btop has no disk
 * scrolling; neither does this — rows past the box are cut, as in btop,
 * but at the bottom border's top edge (btop can overdraw the border with
 * the last disk's io_mode rows).
 */
final class DisksView
{
    private const DIV_LEFT = '├';

    private const DIV_RIGHT = '┤';

    private function __construct()
    {
    }

    public static function paint(Region $box, PanelFrame $f, Rect $area, Disks $d): void
    {
        $height = $box->height();
        $dw = $area->width - 2;
        if ($dw < 1 || $height < 3) {
            return;
        }
        $config = $f->config;
        $rows = $d->rows($config);
        if ($rows === []) {
            return;
        }
        // btop's loop checks `cy > height - 3` only BEFORE a disk, so the
        // io_mode activity row and tall io graphs of the last disk can land
        // on (or past) the bottom border. Same math, but clipped above it.
        $box = $box->sub(Rect::new(0, 0, $box->width(), $height - 1));
        $a = $area->x;
        $big = $dw >= 25;
        $human = Humanizer::new($config->bool('base_10_sizes'));
        $cy = 0;

        if ($config->bool('io_mode')) {
            $ios = $d->ioCount();
            $combined = $config->bool('io_graph_combined');
            $ioH = max((int) floor(($height - 2 - $ios * 2) / max(1, $ios)), $combined ? 1 : 2);
            $half = (int) ceil($ioH / 2);
            $speeds = self::speeds($config);
            foreach ($rows as $row) {
                if ($cy > $height - 3) {
                    break;
                }
                if (!$row->io) {
                    continue;
                }
                $total = $human->format($row->total, !$big);
                $state = self::titleRow($box, $f, $a, 1 + $cy, $dw, $row, $total);
                if ($big) {
                    $pct = (string) $row->usedPercent;
                    $at = $a + (int) round($dw / 2) - (int) round(strlen($pct) / 2);
                    self::centred($box, $f, $at, 1 + $cy, $pct . '%');
                    $state = $f->ink->fg('main_fg');
                }
                self::activity($box, $f, $d, $row, $a, 2 + $cy, $dw, $big, $state);
                $cy++;
                if (++$cy > $height - 3) {
                    break;
                }
                $speed = ($speeds[$row->key] ?? 100) << 20;
                $read = $d->history()->series(Disks::key('read', $row->key));
                $write = $d->history()->series(Disks::key('write', $row->key));
                $lastRead = $read === [] ? 0 : $read[count($read) - 1];
                $lastWrite = $write === [] ? 0 : $write[count($write) - 1];
                $family = $config->graphSymbolFor('mem');
                if ($combined) {
                    $comb = self::combine($read, $write);
                    $graph = DualSampleGraph::new(max(1, $dw), max(1, $ioH), $family, false, true, $speed)->withData(...$comb);
                    foreach (TintedGraph::lines($graph, $f->ink, 'available') as $r => $line) {
                        $box->ansi($a + 1, 1 + $cy + $r, $line);
                    }
                    $value = $lastRead + $lastWrite;
                    $arrows = ($lastWrite > 0 ? '▼' : '') . ($lastRead > 0 ? '▲' : '');
                    $x = $a + 1 + $box->put($a + 1, 1 + $cy, $arrows, $f->ink->fg('main_fg'));
                    if ($value > 0) {
                        $box->put($x + 1, 1 + $cy, $human->format($value, true), $f->ink->fg('main_fg'));
                    } else {
                        $box->put($x, 1 + $cy, Lang::t('disks.rw'), $f->ink->fg('main_fg'));
                    }
                } else {
                    $up = DualSampleGraph::new(max(1, $dw), max(1, $half), $family, false, true, $speed)->withData(...$read);
                    $down = DualSampleGraph::new(max(1, $dw), max(1, $ioH - $half), $family, true, true, $speed)->withData(...$write);
                    foreach (TintedGraph::lines($up, $f->ink, 'free') as $r => $line) {
                        $box->ansi($a + 1, 1 + $cy + $r, $line);
                    }
                    foreach (TintedGraph::lines($down, $f->ink, 'used') as $r => $line) {
                        $box->ansi($a + 1, 1 + $cy + $half + $r, $line);
                    }
                    $box->put($a + 1, 1 + $cy, $lastRead > 0 ? '▲' . $human->format($lastRead, true) : Lang::t('disks.r'));
                    $box->put($a + 1, $cy + $ioH, $lastWrite > 0 ? '▼' . $human->format($lastWrite, true) : Lang::t('disks.w'));
                }
                $cy += $ioH;
            }
        } else {
            $ioStat = $config->bool('show_io_stat');
            $count = count($rows);
            $ios = $d->ioCount();
            $meter = max(-14, $box->width() - $a - 23);
            if ($dw < 25) {
                $meter += 14;
            }
            $freeMeters = $count * 3 <= $height - 1;
            foreach ($rows as $row) {
                if ($cy > $height - 3) {
                    break;
                }
                $read = $d->history()->last(Disks::key('read', $row->key)) ?? 0;
                $write = $d->history()->last(Disks::key('write', $row->key)) ?? 0;
                $comb = $row->io ? $read + $write : 0;
                $humanIo = $comb > 0
                    ? ($write > 0 && $big ? '▼' : '') . ($read > 0 && $big ? '▲' : '') . $human->format($comb, true)
                    : '';
                $total = $human->format($row->total, !$big);
                self::titleRow($box, $f, $a, 1 + $cy, $dw, $row, $total);
                if ($big && $humanIo !== '') {
                    $at = $a + (int) round($dw / 2) - (int) round(strlen($humanIo) / 2);
                    self::centred($box, $f, $at, 1 + $cy, $humanIo);
                }
                if (++$cy > $height - 3) {
                    break;
                }
                if ($ioStat && $row->io) {
                    self::activity($box, $f, $d, $row, $a, 1 + $cy, $dw, $big, $f->ink->fg('main_fg'));
                    if (!$big) {
                        $box->put($a + 1, 1 + $cy, $humanIo, $f->ink->fg('main_fg'));
                    }
                    if (++$cy > $height - 3) {
                        break;
                    }
                }
                self::meterRow($box, $f, $a, 1 + $cy, $big, Lang::t('disks.used'), Lang::t('disks.used_short'), $row->usedPercent, 'used', $meter, $human->format($row->used, !$big));
                if (++$cy > $height - 3) {
                    break;
                }
                if ($freeMeters && $count * 3 + ($ioStat ? $ios : 0) <= $height - 1) {
                    self::meterRow($box, $f, $a, 1 + $cy, $big, Lang::t('disks.free'), Lang::t('disks.free_short'), $row->freePercent, 'free', $meter, $human->format($row->free, !$big));
                    $cy++;
                    if ($count * 4 + ($ioStat ? $ios : 0) <= $height - 1) {
                        $cy++;
                    }
                }
            }
        }
        if ($cy < $height - 2) {
            self::divider($box, $f, $a, 1 + $cy, $dw);
        }
    }

    /**
     * io_graph_speeds: `mountpoint:MiB/s` pairs (btop only keeps the
     * mounts it knows; unknown keys simply never match here).
     *
     * @return array<string, int>
     */
    public static function speeds(Config $config): array
    {
        $out = [];
        foreach (preg_split('/\s+/', $config->string('io_graph_speeds'), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $vals = explode(':', $entry);
            if (count($vals) === 2 && $vals[1] !== '' && ctype_digit($vals[1]) && strlen($vals[1]) < 10) {
                $out[$vals[0]] = (int) $vals[1];
            }
        }

        return $out;
    }

    /**
     * `├────┤` + bold title-coloured name at a+2 + trans(total) ending at
     * the last column before the border. Returns the colour the stream
     * is left in (btop: `title` after `Fx::ub`).
     */
    private static function titleRow(Region $box, PanelFrame $f, int $a, int $y, int $dw, DiskRow $row, string $total): string
    {
        self::divider($box, $f, $a, $y, $dw);
        $title = $f->ink->fg('title');
        $name = $dw - 8 < 0 ? $row->name : Width::truncate($row->name, $dw - 8);
        $box->put($a + 2, $y, $name, $title . Symbols::BOLD);
        Trans::put($box, $a + $dw - strlen($total), $y, $total, $title . Symbols::BOLD);

        return $title;
    }

    /** `─text─` (div_line dashes, main_fg text) centred on the title row. */
    private static function centred(Region $box, PanelFrame $f, int $x, int $y, string $text): void
    {
        $line = $f->ink->fg('div_line');
        $x += $box->put($x, $y, Symbols::H_LINE, $line);
        $x += $box->put($x, $y, $text, $f->ink->fg('main_fg'));
        $box->put($x, $y, Symbols::H_LINE, $line);
    }

    /**
     * ` IO% ` (or ` IO` with the graph starting a cell earlier when narrow)
     * plus the 1-row activity graph on the graph_bg underlay.
     */
    private static function activity(Region $box, PanelFrame $f, Disks $d, DiskRow $row, int $a, int $y, int $dw, bool $big, string $sgr): void
    {
        $io = Lang::t('disks.io');
        $label = $big ? ' ' . $io . '% ' : ' ' . $io . '   ';
        $box->put($a + 1, $y, $label, $sgr);
        // btop's fixed columns: ` IO% ` leaves the cursor at a+6, ` IO   ` + Mv::l(2) at a+5 —
        // independent of the translated label's width.
        $x = $a + ($big ? 6 : 5);
        $graph = DualSampleGraph::new(max(1, $dw - 6), 1, $f->config->graphSymbolFor('mem'))
            ->withData(...$d->history()->series(Disks::key('activity', $row->key)));
        foreach (TintedGraph::lines($graph, $f->ink, 'available', true) as $line) {
            $box->ansi($x, $y, $line);
        }
    }

    /** ` Used: 41% ■■■■   201 GiB` (big) or `U ■■■ 201G`. */
    private static function meterRow(Region $box, PanelFrame $f, int $a, int $y, bool $big, string $label, string $short, int $percent, string $gradient, int $meter, string $human): void
    {
        $x = $a + 1;
        $x += $box->put($x, $y, ($big ? ' ' . $label . Just::right($percent . '%', 4) : $short) . ' ');
        $x += $box->ansi($x, $y, PositionMeter::render($f->ink, $meter, $percent, $gradient));
        $box->put($x, $y, Just::right($human, $big ? 9 : 5));
    }

    /** btop's disks divider: `├` + h_line across the section + `┤` on the right border. */
    private static function divider(Region $box, PanelFrame $f, int $a, int $y, int $dw): void
    {
        $line = $f->ink->fg('div_line');
        $box->put($a, $y, self::DIV_LEFT . str_repeat(Symbols::H_LINE, max(0, $dw)), $line);
        $box->put($a + $dw + 1, $y, self::DIV_RIGHT, $f->ink->fg('mem_box'));
    }

    /**
     * Element-wise read + write, aligned on the newest sample (btop
     * transforms the two equally long deques).
     *
     * @param list<int> $read
     * @param list<int> $write
     * @return list<int>
     */
    private static function combine(array $read, array $write): array
    {
        $n = min(count($read), count($write));
        $read = array_slice($read, -$n);
        $write = array_slice($write, -$n);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $read[$i] + $write[$i];
        }

        return $out;
    }
}
