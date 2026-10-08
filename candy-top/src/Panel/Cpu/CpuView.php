<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Cpu;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\CpuPanel;
use SugarCraft\Top\Panel\Net\Humanizer;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\Gfx\Trans;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\ClockFormat;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints a {@see CpuPanel} — a line-by-line port of btop Cpu::draw
 * (src/btop_draw.cpp:567-1024) onto a {@see Region} whose (0, 0) is the
 * cpu box's top-left corner.
 *
 * Coordinates: btop writes `Mv::to(y + r, x + c)` with the box at 1-based
 * (x, y); the same offsets (r, c) are local cells here. The cores
 * sub-box offsets `Mv::to(b_y + r, b_x + c)` become (c.y + r, c.x + c) of
 * the local cores rectangle. The box outline, the cores sub-box outline
 * with the cpu name, and the title buttons are App chrome
 * ({@see \SugarCraft\Top\View\FrameBuilder::paintChrome()}).
 *
 * Every graph width/height is clamped to >= 1 before DualSampleGraph sees
 * it (btop #1614/#1858); a section with no room is skipped.
 */
final class CpuView
{
    private const DIV_LEFT = '├';

    private const DIV_RIGHT = '┤';

    /** btop gpu_info::temp_max default. */
    private const GPU_TEMP_MAX = 110;

    private function __construct()
    {
    }

    public static function paint(Region $box, PanelFrame $f, CpuPanel $p): void
    {
        $width = $box->width();
        $height = $box->height();
        if (!$p->sampled() || $width < 3 || $height < 3) {
            return;
        }
        $config = $f->config;
        $ink = $f->ink;
        $layout = $f->layout;
        $cores = $layout->cpuCores !== null ? $f->local($layout->cpuCores) : Rect::new($width, 1, 0, 0);
        $bw = $cores->width;

        // btop `check_temp and got_sensors` (HostInfo::hasSensors ports get_sensors).
        $showTemps = $config->bool('check_temp') && $f->host->hasSensors;
        $coreSensors = $p->temp()?->cores ?? [];
        $hideCores = $showTemps && ($coreSensors === [] || !$config->bool('show_coretemp'));
        $bcs = $layout->coreColumnSize;
        $bcols = max(1, $layout->coreColumns);
        $extra = $hideCores ? max(6, 6 * $bcs) : (($bcols === 1 && !$showTemps) ? 8 : 0);
        $gpus = self::listedGpus($p->gpus(), $config);
        $gpuMode = $config->string('show_gpu_info');
        $showGpu = $gpus !== [] && ($gpuMode === 'On' || $gpuMode === 'Auto');

        $available = $p->graphFields();
        $up = $config->string('cpu_graph_upper');
        if ($up === 'Auto' || !in_array($up, $available, true)) {
            $up = 'total';
        }
        $lo = $config->string('cpu_graph_lower');
        if ($lo === 'Auto' || !in_array($lo, $available, true)) {
            $lo = $showGpu ? 'gpu-totals' : $up;
        }
        $single = $config->bool('cpu_single_graph');
        $invertLower = $config->bool('cpu_invert_lower');
        $mid = !$single && $up !== $lo;
        $upH = $single ? $height - 2 : (int) ceil(($height - 2) / 2) - ($mid && $height % 2 !== 0 ? 1 : 0);
        $loH = $height - 2 - $upH - ($mid ? 1 : 0);
        // btop `x + width - b_width - 3` with the cpu box at x = 1.
        $graphW = $width - $bw - 2;
        $family = $config->graphSymbolFor('cpu');

        if ($mid && $upH + 1 < $height - 1) {
            self::midLine($box, $f, 1 + $upH, $width - $bw, $up, $lo);
        }
        self::graphs($box, $f, $p, $up, 1, $upH, $graphW, false, $family, $gpus);
        if (!$single) {
            self::graphs($box, $f, $p, $lo, 1 + $upH + ($mid ? 1 : 0), $loH, $graphW, $invertLower, $family, $gpus);
        }

        if ($config->bool('show_uptime') && $p->uptime() >= 0) {
            $upstr = ClockFormat::dhms((int) $p->uptime());
            if (strlen($upstr) > 8) {
                $upstr = substr($upstr, 0, -3);
            }
            $row = ($single || !$invertLower) ? 1 : $height - 2;
            $label = Lang::t('cpu.up');
            $box->put(2, $row, $label, $ink->fg('graph_text'));
            // btop `Mv::r(1)` + trans(upstr): the gap and the space in
            // `67d 19:16` keep the graph glyph underneath.
            Trans::put($box, 3 + Width::string($label), $row, $upstr, $ink->fg('graph_text'));
        }

        if ($bw < 3 || $cores->height < 3) {
            $p->battery()?->paint($box, $f);

            return;
        }
        self::freqLabel($box, $f, $p, $cores);
        $tempMax = self::cpuTempMax($p);
        self::meterRow($box, $f, $p, $cores, $showTemps, $tempMax, $family);
        $nGpus = $showGpu ? count($gpus) : 0;
        $cy = self::coreGrid($box, $f, $p, $cores, $showTemps && !$hideCores, $extra, $nGpus, $tempMax, $family, $cc);
        if ($cy < $cores->height - 1 && $cc <= $bcols) {
            $cy = $cores->height - 2 - $nGpus;
            self::loadAvg($box, $f, $p, $cores, $cy);
        }
        if ($showGpu) {
            self::gpuRows($box, $f, $p, $cores, $cy, $showTemps, $family, $gpus);
        }

        $p->battery()?->paint($box, $f);
    }

    /** `├───── total ▲▼ user ─┤` between the upper and lower graph. */
    private static function midLine(Region $box, PanelFrame $f, int $row, int $span, string $up, string $lo): void
    {
        $ink = $f->ink;
        $box->put(0, $row, self::DIV_LEFT, $ink->fg('cpu_box'));
        $box->put(1, $row, str_repeat(Symbols::H_LINE, max(0, $span - 2)) . self::DIV_RIGHT, $ink->fg('div_line'));
        $x = intdiv($span, 2) - intdiv(strlen($up) + strlen($lo), 2) - 4;
        $fg = $ink->fg('main_fg');
        $box->put($x, $row, $up, $fg);
        $x += Width::string($up) + 1;
        $box->put($x, $row, '▲▼', $fg);
        $box->put($x + 3, $row, $lo, $fg);
    }

    /**
     * The GPUs this box lists, keyed by GPU index — btop PR #1730
     * gpu_hidden: with show_gpu_info Auto a GPU that has a gpu box of its
     * own is left out (On lists every GPU).
     *
     * @param list<GpuDevice> $gpus
     * @return array<int, GpuDevice>
     */
    public static function listedGpus(array $gpus, Config $config): array
    {
        if ($config->string('show_gpu_info') !== 'Auto') {
            return $gpus;
        }
        $boxed = array_flip(GpuPanels::targets($config->shownBoxes()));

        return array_filter($gpus, static fn (int $i): bool => !isset($boxed[$i]), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * One graph area: a cpu_percent field, a shared GPU series, or one
     * graph per listed GPU side by side (`gpu-*-totals`) — the #1614
     * width math over btop PR #1730's draw count.
     *
     * @param array<int, GpuDevice> $gpus listed GPUs keyed by index
     */
    private static function graphs(Region $box, PanelFrame $f, CpuPanel $p, string $field, int $y, int $h, int $graphW, bool $invert, string $family, array $gpus): void
    {
        if ($h < 1) {
            return;
        }
        $history = $p->history();
        if (in_array($field, CpuPanel::GPU_FIELDS, true)) {
            $widths = self::gpuGraphWidths($graphW, count($gpus));
            $indexes = array_keys($gpus);
            foreach ($widths as $k => [$x, $w]) {
                $i = $indexes[$k];
                if ($gpus[$i]->utilization >= 0 || $history->has("gpu:{$i}:{$field}")) {
                    self::graph($box, $f, $x, $y, $w, $h, $family, $invert, $history->series("gpu:{$i}:{$field}"));
                }
                if (count($gpus) > 1) {
                    $gw = $widths[0][1];
                    $box->put($x + 1, $y + $h - 1 - intdiv($h, 2), ($gw > 5 ? Lang::t('cpu.gpu') : '') . $i); // after the graph's Fx::reset
                }
                if ($k + 1 < count($gpus)) {
                    for ($r = 0; $r < $h; $r++) {
                        $box->put($x + $w, $y + $r, Symbols::V_LINE, $f->ink->fg('div_line'));
                    }
                }
            }

            return;
        }
        self::graph($box, $f, 1, $y, max(1, $graphW), $h, $family, $invert, $history->series($field));
    }

    /**
     * btop #1614 (d3389d7): `graph_default_width` shared fairly by `$count`
     * GPUs with one separator column between them; every width clamped to
     * >= 1 and the remainder going to the last graph.
     *
     * @return list<array{0: int, 1: int}> [x, width] per GPU, x local (graphs start at column 1)
     */
    public static function gpuGraphWidths(int $graphW, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $drawable = $graphW - max(0, $count - 1);
        $gw = max(1, intdiv($drawable, $count));
        $out = [];
        $x = 1;
        for ($i = 0; $i < $count; $i++) {
            $w = $i + 1 < $count ? $gw : max(1, $gw + $drawable % $count);
            $out[] = [$x, $w];
            $x += $w + 1;
        }

        return $out;
    }

    /** @param list<int> $series */
    private static function graph(Region $box, PanelFrame $f, int $x, int $y, int $w, int $h, string $family, bool $invert, array $series): void
    {
        $graph = DualSampleGraph::new(max(1, $w), max(1, $h), $family, $invert, true)->withData(...$series);
        foreach (TintedGraph::lines($graph, $f->ink, 'cpu') as $r => $line) {
            $box->ansi($x, $y + $r, $line);
        }
    }

    /** `─────┐3.4 GHz┌` on the cores sub-box top edge (btop_draw.cpp:874-877). */
    private static function freqLabel(Region $box, PanelFrame $f, CpuPanel $p, Rect $cores): void
    {
        $label = $p->freq()?->label ?? '';
        if (!$f->config->bool('show_cpu_freq') || $label === '') {
            return;
        }
        $ink = $f->ink;
        $range = $f->config->string('freq_mode') === 'range';
        $x = $cores->x + $cores->width - ($range ? 20 : 10);
        $dash = max(0, ($range ? 17 : 7) - strlen($label));
        [$open, $close] = $f->border->embedJunctions(false);
        $x += $box->put($x, $cores->y, str_repeat(Symbols::H_LINE, $dash) . $open, $ink->fg('div_line'));
        $x += $box->put($x, $cores->y, $label, $ink->fg('title') . Symbols::BOLD);
        $box->put($x, $cores->y, $close, $ink->fg('div_line'));
    }

    /** `CPU ■■■■■■■■   42% ⣀⣀⣠⣴⣶  56°C│` (btop_draw.cpp:879-899). */
    private static function meterRow(Region $box, PanelFrame $f, CpuPanel $p, Rect $c, bool $showTemps, int $tempMax, string $family): void
    {
        $ink = $f->ink;
        $layout = $f->layout;
        $bcs = $layout->coreColumnSize;
        $bcols = max(1, $layout->coreColumns);
        $row = $c->y + 1;
        $x = $c->x + 1;
        $total = $p->history()->last('total') ?? 0;
        $x += $box->put($x, $row, Lang::t('cpu.label') . ' ', $ink->fg('main_fg') . Symbols::BOLD);
        $meterW = $c->width - ($showTemps ? 23 - ($bcs <= 1 && $bcols === 1 ? 6 : 0) : 11);
        $x += $box->ansi($x, $row, PositionMeter::render($ink, $meterW, $total, 'cpu'), $ink->fg('main_fg') . Symbols::BOLD);
        $x += $box->put($x, $row, Just::right((string) $total, 4), $ink->gradient('cpu', max(0, min(100, $total))));
        $x += $box->put($x, $row, '%', $ink->fg('main_fg'));
        $temp = $p->history()->last('temp:cpu');
        if ($showTemps && $temp !== null) {
            $color = $ink->gradient('temp', self::pct($temp, $tempMax));
            if ($bcs > 1 || $bcols > 1) {
                $x += $box->put($x, $row, ' ');
                $x += self::mini($box, $f, $x, $row, $p->history()->series('temp:cpu'), 5, 'temp', $family, $tempMax, -23);
            }
            [$value, $unit] = self::celsiusTo($temp, $f->config->tempScale());
            $x += $box->put($x, $row, Just::right((string) $value, 4), $color);
            $x += $box->put($x, $row, $unit, $ink->fg('main_fg'));
        } elseif ($showTemps) {
            // No reading yet (or a sensorless cpu sensor): keep the slot so
            // the separator still lands on the cores-box border.
            $x += ($bcs > 1 || $bcols > 1 ? 6 : 0) + 6;
        }
        $box->put($x, $row, Symbols::V_LINE, $ink->fg('div_line'));
    }

    /**
     * The per-core grid (btop_draw.cpp:917-980), with btop #1785's
     * per-core frequency appended after the percentage.
     *
     * @param-out int $cc the column index the walk stopped in
     * @return int the row index (cy) the walk stopped at
     */
    private static function coreGrid(Region $box, PanelFrame $f, CpuPanel $p, Rect $c, bool $coreTemps, int $extra, int $nGpus, int $tempMax, string $family, ?int &$cc = 0): int
    {
        $ink = $f->ink;
        $config = $f->config;
        $history = $p->history();
        $bcs = $f->layout->coreColumnSize;
        $bcols = max(1, $f->layout->coreColumns);
        $count = $p->coreCount() > 0 ? $p->coreCount() : $f->host->coreCount;
        $maxRow = $c->height - 3 - $nGpus;
        $perColumn = (int) ceil($count / $bcols);
        $coreW = ($bcs === 0 ? 2 : 3) + ($count >= 100 ? 1 : 0);
        $graphFull = ($bcs > 0 || $extra > 0) ? 5 * $bcs + $extra : 0;
        [$freqMode, $freqFoot] = self::freqFootprint($config->showCoreFreq(), $graphFull);
        $usageW = $graphFull - $freqFoot;
        $sensors = count($p->temp()?->cores ?? []);
        $coreMap = self::coreMap($config->string('cpu_core_map'));
        $freq = $p->freq();
        $tempScale = $config->tempScale();

        $cx = 0;
        $cy = 1;
        $cc = 0;
        for ($n = 0; $n < $count; $n++) {
            $enabled = !$p->inactive($n);
            $fg = $ink->fg($enabled ? 'main_fg' : 'inactive_fg');
            $row = $c->y + $cy + 1;
            $x = $c->x + $cx + 1;
            if ($count < 100) {
                $x += $box->put($x, $row, 'C', $fg . Symbols::BOLD);
            }
            $x += $box->put($x, $row, Just::left((string) $n, $coreW), $fg);
            if ($usageW >= 1) {
                $x += self::mini($box, $f, $x, $row, $history->series('core:' . $n), $usageW, 'cpu', $family);
            }
            $value = $history->last('core:' . $n) ?? 0;
            $x += $box->put($x, $row, Just::right((string) $value, $bcs < 2 ? 3 : 4), $enabled ? $ink->gradient('cpu', max(0, min(100, $value))) : $ink->fg('inactive_fg'));
            $x += $box->put($x, $row, '%', $fg);

            if ($freqMode !== 'off') {
                $mhz = $history->last('freq:' . $n);
                if ($freqMode === 'graph') {
                    $min = (int) ($freq?->perCoreMin[$n] ?? -1);
                    $max = (int) ($freq?->perCoreMax[$n] ?? -1);
                    $x += $box->put($x, $row, ' ');
                    $x += $max > $min && $min >= 0
                        ? self::mini($box, $f, $x, $row, $history->series('freq:' . $n), 5, 'cpu', $family, $max - $min, -$min)
                        : $box->put($x, $row, str_repeat(' ', 5));
                }
                $x += $box->put($x, $row, ' ' . Just::right(self::shortFreq($mhz), 5), $fg);
            }

            if ($coreTemps && $sensors > 0) {
                $k = self::sensorFor($n, $sensors, $coreMap);
                $temp = $history->last('temp:' . $k);
                if ($temp !== null) {
                    if ($bcs > 1) {
                        $x += $box->put($x, $row, ' ');
                        $x += self::mini($box, $f, $x, $row, $history->series('temp:' . $k), 5, 'temp', $family, $tempMax, -23);
                    }
                    [$tv, $unit] = self::celsiusTo($temp, $tempScale);
                    $x += $box->put($x, $row, Just::right((string) $tv, 4), $enabled ? $ink->gradient('temp', self::pct($temp, $tempMax)) : $ink->fg('inactive_fg'));
                    $x += $box->put($x, $row, $unit, $fg);
                }
            }
            $box->put($x, $row, Symbols::V_LINE, $ink->fg('div_line'));

            $cy++;
            if (($cy > $perColumn || $cy === $maxRow) && $n !== $count - 1) {
                if (++$cc >= $bcols) {
                    break;
                }
                $cy = 1;
                $cx = intdiv($c->width, $bcols) * $cc;
            }
        }

        return $cy;
    }

    /** `      Load avg: 0.52 0.58 0.59` right-aligned on the row above the GPU rows. */
    private static function loadAvg(Region $box, PanelFrame $f, CpuPanel $p, Rect $c, int $cy): void
    {
        $ink = $f->ink;
        $values = '';
        foreach ($p->load() as $load) {
            $values .= ' ' . ($load < 0 ? Lang::t('value.unavailable') : sprintf('%.2f', $load));
        }
        $pre = Lang::t('cpu.load_avg_label');
        $len = Width::string($pre) + strlen($values);
        $x = $c->x + 1;
        $row = $c->y + $cy;
        $x += $box->put($x, $row, str_repeat(' ', max($c->width - $len - 2, 0)));
        $x += $box->put($x, $row, $pre, $ink->fg('main_fg') . Symbols::BOLD);
        $box->put($x, $row, $values, $ink->fg('main_fg'));
    }

    /**
     * btop's "Gpu brief info" rows (btop_draw.cpp:983-1021).
     *
     * btop's stream state is tracked through the row: "GPU" turns bold on
     * and only a meter's or graph's Fx::reset turns it off, so with one
     * core column (no meter, no mini graphs) the whole row stays bold and
     * the % is the plain bold main_fg btop prints there. A column shows
     * while the device has EVER measured that value (the panel holds the
     * last good reading, #1008), so one failed query never drops it.
     */
    /**
     * @param array<int, GpuDevice> $gpus listed GPUs keyed by index
     */
    private static function gpuRows(Region $box, PanelFrame $f, CpuPanel $p, Rect $c, int $cy, bool $showTemps, string $family, array $gpus): void
    {
        $ink = $f->ink;
        $history = $p->history();
        $bcols = max(1, $f->layout->coreColumns);
        // btop prints the GPU number while more than one GPU exists at all.
        $count = count($p->gpus());
        $graphW = $c->width < 42 ? 4 : 5;
        $human = Humanizer::new($f->config->bool('base_10_sizes'));
        $mainFg = $ink->fg('main_fg');
        foreach ($gpus as $i => $g) {
            $row = $c->y + (++$cy);
            $x = $c->x + 1;
            $bold = Symbols::BOLD;
            $cur = $mainFg;
            $write = static function (string $text, ?string $fg = null) use ($box, &$x, $row, &$bold, &$cur): void {
                $cur = $fg ?? $cur;
                $x += $box->put($x, $row, $text, $cur . $bold);
            };
            $reset = static function () use (&$bold, &$cur): void {
                $bold = '';
                $cur = '';
            };
            $write(Lang::t('cpu.gpu'), $mainFg);
            if ($count > 1) {
                $write(Just::right((string) $i, 1 + ($count > 9 ? 1 : 0)));
            }
            $hasUtil = $g->utilization >= 0;
            $hasTemp = $showTemps && $g->temp >= 0;
            $hasMem = $g->memUsed >= 0 && $g->memTotal > 0;
            $left = $c->width - 10 - ($count > 9 ? 2 : ($count > 1 ? 1 : 0))
                - ($hasTemp ? 11 : 0) - ($hasMem && $bcols > 1 ? 5 : 0)
                - ($g->memUsed >= 0 ? 5 : 0) - ($g->memTotal > 0 ? 6 : 0) - ($g->watts >= 0 ? 6 : 0);
            if ($hasUtil) {
                $util = $history->last("gpu:{$i}:gpu-totals") ?? 0;
                $write(' ');
                if ($bcols > 1) {
                    $x += $box->ansi($x, $row, PositionMeter::render($ink, $left, $util, 'cpu'));
                    $reset();
                    $write(Just::right((string) $util, 3), $ink->gradient('cpu', max(0, min(100, $util))));
                } else {
                    $write(Just::right((string) $util, 3));
                }
                $write('%' . ($bcols === 1 ? ' ' : ''), $mainFg);
            }
            if ($hasMem && $bcols > 1) {
                $write(' ');
                $x += self::mini($box, $f, $x, $row, $history->series("gpu:{$i}:gpu-vram-totals"), $graphW, 'used', $family);
                $reset();
            }
            if ($g->memUsed >= 0) {
                $write(Just::right($human->format($g->memUsed, true), 5), $mainFg);
            }
            if ($g->memTotal > 0) {
                $write('/', $ink->fg('inactive_fg'));
                $write(Just::left($human->format($g->memTotal, true), 4), $mainFg);
            }
            if ($hasTemp) {
                $temp = $history->last("gpu:{$i}:temp") ?? 0;
                $write(' ');
                if ($bcols > 1) {
                    $x += self::mini($box, $f, $x, $row, $history->series("gpu:{$i}:temp"), $graphW, 'temp', $family, self::GPU_TEMP_MAX, -23);
                    $reset();
                }
                [$tv, $unit] = self::celsiusTo($temp, $f->config->tempScale());
                $write(Just::right((string) $tv, 3), $ink->gradient('temp', self::pct($temp, self::GPU_TEMP_MAX)));
                $write($unit, $mainFg);
            }
            if ($g->watts >= 0) {
                $pwr = $history->last("gpu:{$i}:gpu-pwr-totals") ?? 0;
                $precision = $g->watts < 10 ? 2 : ($g->watts < 100 ? 1 : 0);
                $write(' ');
                $write(sprintf('%4.' . $precision . 'f', $g->watts), $ink->gradient('cached', max(0, min(100, $pwr))));
                $write('W', $mainFg);
            }
            if ($cy > $c->height - 1) {
                break;
            }
        }
    }

    /**
     * btop's `graph_bg * w + Mv::l(w) + graph(...)`: a one-row history
     * graph over an inactive_fg underlay. Returns columns used.
     *
     * @param list<int> $series
     */
    private static function mini(Region $box, PanelFrame $f, int $x, int $y, array $series, int $w, string $gradient, string $family, int $max = 0, int $offset = 0): int
    {
        if ($w < 1) {
            return 0;
        }
        $graph = DualSampleGraph::new($w, 1, $family, false, false, $max, $offset)->withData(...$series);
        $box->ansi($x, $y, TintedGraph::lines($graph, $f->ink, $gradient, true)[0]);

        return $w;
    }

    /**
     * btop #1785: how much of the core usage graph the per-core frequency
     * takes — `value` 6 columns (` 3.4G`), `graph` 12 (a space, the 5-cell history,
     * then the value). A footprint wider than the usage graph would push the
     * row past its column, so the mode degrades graph -> value -> off.
     *
     * @return array{0: string, 1: int} [effective mode, footprint]
     */
    public static function freqFootprint(string $mode, int $graphFull): array
    {
        if ($mode === 'graph' && $graphFull >= 12) {
            return ['graph', 12];
        }
        if ($mode !== 'off' && $graphFull >= 6) {
            return ['value', 6];
        }

        return ['off', 0];
    }

    /** {@see Freq::label()} compacted to 5 cells: `3.4 GHz` -> `3.4G`, `800 MHz` -> `800M`. */
    public static function shortFreq(?int $mhz): string
    {
        return $mhz === null ? '' : (string) preg_replace('/ ([MGT])Hz$/', '$1', Freq::label((float) $mhz));
    }

    /** btop's per-core temp lookup: cpu_core_map override, else core n -> sensor n mod sensors. */
    public static function sensorFor(int $core, int $sensors, array $map): int
    {
        $mapped = $map[$core] ?? null;

        return $mapped !== null && $mapped < $sensors ? $mapped : $core % max(1, $sensors);
    }

    /** @return array<int, int> */
    private static function coreMap(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (preg_match('/^(\d+):(\d+)$/', $pair, $m) === 1) {
                $out[(int) $m[1]] = (int) $m[2];
            }
        }

        return $out;
    }

    /** btop `safe_cpu_temp_max`: the cpu sensor's crit, 90 when unknown. */
    private static function cpuTempMax(CpuPanel $p): int
    {
        $crit = $p->temp()?->cpuCrit() ?? -1.0;

        return $crit <= 0 ? 90 : (int) $crit;
    }

    private static function pct(int $temp, int $max): int
    {
        return max(0, min(100, intdiv($temp * 100, max(1, $max))));
    }

    /**
     * btop Tools::celsius_to (btop_tools.cpp:638-648).
     *
     * @return array{0: int, 1: string}
     */
    public static function celsiusTo(int $celsius, string $scale): array
    {
        return match ($scale) {
            'fahrenheit' => [(int) round($celsius * 1.8 + 32), '°F'],
            'kelvin' => [(int) round($celsius + 273.15), 'K '],
            'rankine' => [(int) round($celsius * 1.8 + 491.67), '°R'],
            default => [$celsius, '°C'],
        };
    }
}
