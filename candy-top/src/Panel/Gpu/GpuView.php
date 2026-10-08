<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Cpu\CpuView;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\GpuPanel;
use SugarCraft\Top\Panel\Net\Humanizer;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\GpuBox;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints one gpu box — a port of btop Gpu::draw (src/btop_draw.cpp
 * 1050-1217) with btop PR #1881's detail levels, onto a {@see Region}
 * whose (0, 0) is the gpu box's top-left corner.
 *
 * Coordinates: btop's `Mv::to(y + r, x + c)` is local (c, r); the stats
 * sub-box offsets `Mv::to(b_y + r, b_x + c)` are (s.x + c, s.y + r) with
 * `s` the sub-box translated into the box. The outline, the `gpu⁵` title,
 * the `← gpuN →` selector and the stats sub-box with the model name are
 * App chrome ({@see \SugarCraft\Top\View\FrameBuilder::paintChrome()}).
 *
 * Sections follow the device's {@see GpuFunctions} (btop
 * supported_functions) gated by the box's detail level. Not drawn: the
 * PCIe TX/RX line (no collector measures PCIe throughput). An NPU box
 * reads `NPU` / `ram` where a GPU box reads `GPU` / `vram` (btop #985).
 * Every graph dimension is clamped to >= 1 (btop #1614/#1858). Drawing
 * is clipped above the box's bottom border (btop overdraws it when the
 * grid made the box shorter than its sections).
 */
final class GpuView
{
    /** btop gpu_info::temp_max default. */
    public const TEMP_MAX = 110;

    private const DIV_LEFT = '├';

    private const DIV_RIGHT = '┤';

    private const DIV_UP = '┬';

    private const DIV_DOWN = '┴';

    private function __construct()
    {
    }

    public static function paint(Region $box, PanelFrame $f, GpuPanel $p): void
    {
        $gpu = $f->layout->gpuBox($f->name);
        if ($gpu === null || !$p->sampled() || $box->width() < 3 || $box->height() < 3) {
            return;
        }
        $device = $p->device($gpu->index);
        if ($device === null) {
            return;
        }
        // A box the grid made shorter than its sections (btop caps the
        // height at the cpu box's) is clipped above its bottom border;
        // btop overdraws the border there.
        $box = $box->sub(Rect::new(0, 0, $box->width(), $box->height() - 1));
        $config = $f->config;
        $ink = $f->ink;
        $history = $p->history();
        $i = $gpu->index;
        $sf = GpuFunctions::of($device)->forDetail($gpu->detail);
        $showTemps = $sf->temp && $config->bool('check_temp');
        $family = $config->graphSymbolFor('gpu');
        $single = !$config->bool('gpu_mirror_graph');
        $width = $gpu->rect->width;
        $bw = $gpu->stats->width;
        $sx = $gpu->stats->x - $gpu->rect->x;
        $sy = $gpu->stats->y - $gpu->rect->y;
        $kind = $gpu->npu ? 'npu' : 'gpu';
        $mainFg = $ink->fg('main_fg');

        // Graphs (btop_draw.cpp:1083-1095, PR #1881 width).
        $upH = $single ? $gpu->graphHeight : intdiv($gpu->graphHeight + 1, 2);
        $loH = $single ? 0 : $gpu->graphHeight - $upH;
        $graphW = max(1, $width - $bw - 2);
        if ($sf->utilization) {
            $util = $history->series(GpuPanel::key($i, 'util'));
            // btop Graph{..., "cpu", ..., invert, no_zero = true}.
            self::graph($box, $f, 1, 1, $graphW, $upH, $family, false, $util, 'cpu', true);
            if (!$single && $loH >= 1) {
                self::graph($box, $f, 1, 1 + $upH, $graphW, $loH, $family, $config->bool('cpu_invert_lower'), $util, 'cpu', true);
            }
        }

        $rows = 1;
        if ($sf->utilization) {
            $value = $history->last(GpuPanel::key($i, 'util')) ?? 0;
            $row = $sy + $rows;
            $x = $sx + 1;
            $x += $box->put($x, $row, Lang::t('gpu.meter.' . $kind) . ' ', $mainFg . Symbols::BOLD);
            $x += $box->ansi($x, $row, PositionMeter::render($ink, max(1, $bw - ($showTemps ? 25 : 12)), $value, 'cpu'), $mainFg . Symbols::BOLD);
            $x += $box->put($x, $row, Just::right((string) $value, 5), $ink->gradient('cpu', self::pct($value)));
            $x += $box->put($x, $row, '%', $mainFg);
            $temp = $history->last(GpuPanel::key($i, 'temp'));
            if ($showTemps && $temp !== null) {
                $x += $box->put($x, $row, ' ');
                $x += self::mini($box, $f, $x, $row, $history->series(GpuPanel::key($i, 'temp')), 6, 'temp', $family, self::TEMP_MAX, -23);
                [$tv, $unit] = CpuView::celsiusTo($temp, $config->tempScale());
                $x += $box->put($x, $row, Just::right((string) $tv, 4), $ink->gradient('temp', self::pct(intdiv($temp * 100, self::TEMP_MAX))));
                $x += $box->put($x, $row, $unit, $mainFg);
            }
            $box->put($x, $row, Symbols::V_LINE, $ink->fg('div_line'));
            $rows++;
        }

        if ($sf->clock && $device->clockGraphics >= 0) {
            self::clockLabel($box, $f, $sx + $bw - 12, $sy, (int) round($device->clockGraphics));
        }

        if ($sf->power && $device->watts >= 0) {
            $pstate = $sf->pstate ? GpuFunctions::pstate($device) : null;
            $pwr = $history->last(GpuPanel::key($i, 'pwr')) ?? 0;
            $row = $sy + $rows;
            $x = $sx + 1;
            $x += $box->put($x, $row, Lang::t('gpu.pwr') . ' ', $mainFg . Symbols::BOLD);
            $x += $box->ansi($x, $row, PositionMeter::render($ink, max(1, $bw - ($pstate !== null ? 25 : 12)), $pwr, 'cached'), $mainFg . Symbols::BOLD);
            $precision = $device->watts < 10 ? 2 : ($device->watts < 100 ? 1 : 0);
            $x += $box->put($x, $row, sprintf('%5.' . $precision . 'f', $device->watts), $ink->gradient('cached', self::pct($pwr)));
            $x += $box->put($x, $row, 'W', $mainFg);
            if ($pstate !== null) {
                $x += $box->put($x, $row, Lang::t('gpu.pstate') . ($pstate > 9 ? '' : ' ') . 'P', $mainFg);
                $box->put($x, $row, (string) $pstate, $ink->gradient('cached', self::pct($pstate)));
            }
            $rows++;
        }

        if ($sf->encoder && $sf->decoder) {
            $row = $sy + $rows;
            $x = $sx + 1;
            $meterW = max(1, intdiv($bw, 2) - 10);
            foreach (['enc' => $device->encoderUtilization, 'dec' => $device->decoderUtilization] as $label => $value) {
                $v = (int) round($value);
                if ($label === 'dec') {
                    $x += $box->put($x, $row, Symbols::V_LINE, $ink->fg('div_line'));
                }
                $x += $box->put($x, $row, Lang::t('gpu.' . $label) . ' ', $mainFg . Symbols::BOLD);
                $x += $box->ansi($x, $row, PositionMeter::render($ink, $meterW, $v, 'cpu'), $mainFg . Symbols::BOLD);
                $x += $box->put($x, $row, Just::right((string) $v, 4), $ink->gradient('cpu', self::pct($v)));
                $x += $box->put($x, $row, '%', $mainFg);
            }
            $rows++;
        }

        if ($sf->memTotal || $sf->memUsed) {
            self::memory($box, $f, $p, $gpu, $device, $sf, $sx, $sy + $rows, $bw, $family);
        }
    }

    /**
     * The memory section (btop_draw.cpp:1159-1198): with total and used,
     * the `├─┐vram┌──┬─Used:── 12.3 GiB─┤` header, the used graph on the
     * right half with `Total:` and the % over it, the controller
     * utilization block on the left and the memory clock on the header;
     * with only one of them, a `VRAM total:` / `VRAM usage:` line.
     */
    private static function memory(Region $box, PanelFrame $f, GpuPanel $p, GpuBox $gpu, GpuDevice $d, GpuFunctions $sf, int $sx, int $row, int $bw, string $family): void
    {
        $ink = $f->ink;
        $history = $p->history();
        $i = $gpu->index;
        $human = Humanizer::new($f->config->bool('base_10_sizes'));
        $mainFg = $ink->fg('main_fg');
        $div = $ink->fg('div_line');
        $title = $ink->fg('title');
        $label = Lang::t('gpu.mem.' . ($gpu->npu ? 'npu' : 'gpu'));
        $half = intdiv($bw, 2);
        [$open, $close] = $f->border->embedJunctions(false);

        if (!($sf->memTotal && $sf->memUsed)) {
            $upper = mb_strtoupper($label);
            $x = $sx + 1;
            $span = intdiv($bw, 1 + (int) $sf->memClock) - 14;
            $text = $sf->memTotal
                ? Lang::t('gpu.mem_total', ['mem' => $upper]) . Just::right($human->format($d->memTotal), $span)
                : Lang::t('gpu.mem_usage', ['mem' => $upper]) . Just::right($human->format(max(0, $d->memUsed)), $span);
            $x += $box->put($x, $row, $text, $mainFg);
            if ($sf->memClock && $d->clockMem >= 0) {
                $box->put($x, $row, Lang::t('gpu.mem_clock', ['mem' => $upper]) . Just::right((int) round($d->clockMem) . Lang::t('gpu.mhz'), $half - 13), $mainFg);
            }

            return;
        }

        $used = $human->format(max(0, $d->memUsed));
        $offset = 1 + 2 + 2 * (int) $sf->memUtilization;
        $mid = $sx + $half;
        $labelW = Width::string($label);
        // Header: ├─┐vram┌───┬─Used:──── 12.3 GiB─┤
        $x = $sx;
        $x += $box->put($x, $row, self::DIV_LEFT . Symbols::H_LINE . $open, $div);
        $x += $box->put($x, $row, $label, $title . Symbols::BOLD);
        $x += $box->put($x, $row, $close . str_repeat(Symbols::H_LINE, max(0, $half - 4 - $labelW)), $div);
        $box->put($mid, $row + $offset, self::DIV_DOWN, $div);
        for ($r = 1; $r < $offset; $r++) {
            $box->put($mid, $row + $r, Symbols::V_LINE, $div);
        }
        $x = $mid;
        $x += $box->put($x, $row, self::DIV_UP . Symbols::H_LINE, $div);
        $x += $box->put($x, $row, Lang::t('gpu.used'), $title);
        $x += $box->put($x, $row, str_repeat(Symbols::H_LINE, max(0, $half + $bw % 2 - 9 - Width::string($used))), $div);
        $x += $box->put($x, $row, $used, $title);
        $box->put($x, $row, Symbols::H_LINE . self::DIV_RIGHT, $div);

        // Used graph on the right half, Total: and the % over its first row.
        $graphX = $sx + $bw - $half + 1;
        $graphH = 2 + 2 * (int) $sf->memUtilization;
        self::graph($box, $f, $graphX, $row + 1, max(1, $half - 2), $graphH, $family, false, $history->series(GpuPanel::key($i, 'vram')), 'used');
        $x = $sx + 2;
        $x += $box->put($x, $row + 1, Lang::t('gpu.total') . Just::right($human->format($d->memTotal), $half - 9), $mainFg . Symbols::BOLD);
        $box->put($x + 3, $row + 1, Just::right((string) ($history->last(GpuPanel::key($i, 'vram')) ?? 0), 3) . '%', $mainFg);

        if ($sf->memUtilization) {
            $x = $sx;
            $x += $box->put($x, $row + 2, self::DIV_LEFT . Symbols::H_LINE, $div);
            $x += $box->put($x, $row + 2, Lang::t('gpu.utilization'), $title);
            $box->put($x, $row + 2, str_repeat(Symbols::H_LINE, max(0, $half - 14)) . self::DIV_RIGHT, $div);
            // btop offsets the series by 4 so 0-5 % still shows a dot.
            self::graph($box, $f, $sx + 1, $row + 3, max(1, $half - 1), 2, $family, false, $history->series(GpuPanel::key($i, 'memutil')), 'free', false, 100, 4);
            $box->put($sx + 1, $row + 3, Just::right((string) ($history->last(GpuPanel::key($i, 'memutil')) ?? 0), 3) . '%');
        }

        if ($sf->memClock && $d->clockMem >= 0) {
            self::clockLabel($box, $f, $sx + $half - 11, $row, (int) round($d->clockMem));
        }
    }

    /** `──┐1234 MHz┌` embedded in a border run (btop_draw.cpp:1123-1127). */
    private static function clockLabel(Region $box, PanelFrame $f, int $x, int $row, int $mhz): void
    {
        $ink = $f->ink;
        $clock = (string) $mhz;
        [$open, $close] = $f->border->embedJunctions(false);
        $x += $box->put($x, $row, str_repeat(Symbols::H_LINE, max(0, 5 - strlen($clock))) . $open, $ink->fg('div_line'));
        $x += $box->put($x, $row, $clock . Lang::t('gpu.mhz'), $ink->fg('title') . Symbols::BOLD);
        $box->put($x, $row, $close, $ink->fg('div_line'));
    }

    /** @param list<int> $series */
    private static function graph(Region $box, PanelFrame $f, int $x, int $y, int $w, int $h, string $family, bool $invert, array $series, string $gradient, bool $noZero = false, int $max = 0, int $offset = 0): void
    {
        if ($h < 1) {
            return;
        }
        $graph = DualSampleGraph::new(max(1, $w), max(1, $h), $family, $invert, $noZero, $max, $offset)->withData(...$series);
        foreach (TintedGraph::lines($graph, $f->ink, $gradient) as $r => $line) {
            $box->ansi($x, $y + $r, $line);
        }
    }

    /**
     * btop's `graph_bg * w + Mv::l(w) + graph(...)`: a one-row history
     * graph over an inactive_fg underlay. Returns columns used.
     *
     * @param list<int> $series
     */
    private static function mini(Region $box, PanelFrame $f, int $x, int $y, array $series, int $w, string $gradient, string $family, int $max, int $offset): int
    {
        $graph = DualSampleGraph::new($w, 1, $family, false, false, $max, $offset)->withData(...$series);
        $box->ansi($x, $y, TintedGraph::lines($graph, $f->ink, $gradient, true)[0]);

        return $w;
    }

    private static function pct(int $value): int
    {
        return max(0, min(100, $value));
    }
}
