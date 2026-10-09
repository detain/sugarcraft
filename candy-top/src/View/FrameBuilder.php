<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Collect\ContainerEngine;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Lang;

/**
 * The frame layout engine and its box chrome.
 *
 * {@see layout()} ports btop's `Draw::calcSizes` (src/btop_draw.cpp) as built
 * with GPU_SUPPORT — the default Linux build — so the mem height subtracts
 * the cpu box's REAL height rather than its 32 % share (the non-GPU
 * build's formula differs by a row on some sizes; the GPU build is what
 * Linux users run), plus btop PR #1730/#1881's gpu box grid
 * ({@see GpuGrid}) and the cpu box's GPU rows (gpus_extra_height) from
 * the detected accelerators ({@see GpuRoster}). Box ratios / minimums:
 * cpu 100w/32h (min 60x8), mem 45/40 (36x10), net 45/28 (36x6),
 * proc 55/68 (44x16) — the proc share being btop PR #1476's
 * `proc_box_width_percent` ({@see sideWidth()}).
 *
 * {@see paintChrome()} ports what calcSizes pre-renders into `Cpu::box`,
 * `Mem::box`, `Net::box` and `Proc::box` (createBox outlines, the cpu
 * cores sub-box, the mem|disks seam + `disks` button, the net stats
 * sub-box) plus the cpu title buttons `menu` / `preset` / `- update +`
 * from Cpu::draw — app state, not cpu data, so the frame owns them.
 *
 * {@see paintChrome()} also draws each gpu box's outline, slot-numbered
 * title, `← gpuN →` selector and stats sub-box (calcSizes' gpu block).
 *
 * Mirrors aristocratos/btop Draw::calcSizes, Draw::createBox,
 * Term::get_min_size.
 */
final class FrameBuilder
{
    public const RATIOS = [
        'cpu' => [100, 32],
        'mem' => [45, 40],
        'net' => [45, 28],
        'proc' => [55, 68],
    ];

    public const MINIMUMS = [
        'cpu' => [60, 8],
        'mem' => [36, 10],
        'net' => [36, 6],
        'proc' => [44, 16],
        // btop PR #1873 Ctr::min_width / min_height.
        'ctr' => [44, 6],
        // The VM dashboard (candy-top's own): one card row in a framed box.
        'vms' => [36, 8],
        // The BMC box (candy-top's own): its tiny level (power, a few temperatures, faults).
        'ipmi' => [40, 6],
    ];

    /**
     * The ipmi band's share of the terminal height, and its own floor and
     * ceiling: tall enough for the four-column strip at 120×40 (11 rows),
     * never more than 16 — the band reports, the boxes below work.
     */
    public const IPMI_BAND = [28, 6, 16];

    /**
     * The box family the ipmi band is outlined in: `net_box` and its flow.
     * The band sits between the cpu box (cpu family) and mem / proc / the
     * VM dashboard (mem, proc, mem), so net is the one family no neighbour
     * uses; its sky → lavender → pink sweep in pastel reads as cool air.
     */
    public const IPMI_FAMILY = 'net';

    /** btop PR #1873: the cpu title's `x ctr` button needs a cpu box this wide. */
    public const CTR_BUTTON_MIN_WIDTH = 76;

    private function __construct()
    {
    }

    /**
     * Minimum terminal size for `$boxes` — btop Term::get_min_size, with
     * btop PR #1881's gpu grid: the gpu boxes need
     * {@see GpuGrid::minWidth()} columns and {@see GpuGrid::minHeight()}
     * rows, both depending on the terminal width (how many boxes fit per
     * row). Gpu boxes beyond the detected accelerators are not counted
     * (btop PR #1730), unless nothing has been detected yet.
     *
     * With the VM dashboard shown the cpu box's GPU-info rows
     * (show_gpu_info, `$gpuInfo`) count too: the dashboard is sized from
     * what the cpu box leaves, and needs at least one card row.
     *
     * @param list<string> $boxes
     * @param ?int         $gpuColumns gpu_box_columns (null = Auto)
     * @return array{0: int, 1: int} [width, height]
     */
    public static function minSize(array $boxes, int $termWidth = 0, ?GpuRoster $roster = null, ?int $gpuColumns = null, string $gpuInfo = 'Auto'): array
    {
        // The VM dashboard eclipses mem/net/proc/ctr: only what is laid out counts.
        $boxes = VmsMode::effective($boxes);
        $vms = VmsMode::active($boxes);
        $cpu = in_array('cpu', $boxes, true);
        $mem = in_array('mem', $boxes, true);
        $net = in_array('net', $boxes, true);
        $proc = in_array('proc', $boxes, true);
        $ctr = in_array('ctr', $boxes, true);
        $ipmi = in_array('ipmi', $boxes, true);
        $roster ??= GpuRoster::none();
        $gpus = array_values(array_filter(
            GpuPanels::targets($boxes),
            static fn (int $i): bool => $roster->count() === 0 || $i < $roster->count(),
        ));

        $width = ($mem || $net) ? self::MINIMUMS['mem'][0] : 0;
        $width += $proc ? self::MINIMUMS['proc'][0] : ($ctr ? self::MINIMUMS['ctr'][0] : 0);
        if ($cpu && $width < self::MINIMUMS['cpu'][0]) {
            $width = self::MINIMUMS['cpu'][0];
        }
        $width = max($width, GpuGrid::minWidth(count($gpus), $termWidth, $gpuColumns));
        $width = max($width, $vms ? self::MINIMUMS['vms'][0] : 0, $ipmi ? self::MINIMUMS['ipmi'][0] : 0);
        $height = $cpu ? self::MINIMUMS['cpu'][1] : 0;
        $height += $vms ? self::MINIMUMS['vms'][1] : 0;
        $height += $ipmi ? self::MINIMUMS['ipmi'][1] : 0;
        if ($vms && $cpu) {
            $boxed = count(array_unique(array_filter(GpuPanels::targets($boxes), static fn (int $i): bool => $i < $roster->gpuCount())));
            $height += match ($gpuInfo) {
                'On' => $roster->gpuCount(),
                'Auto' => max(0, $roster->gpuCount() - $boxed),
                default => 0,
            };
        }
        // btop PR #1873: the ctr box shares the proc column.
        $height += $proc
            ? self::MINIMUMS['proc'][1] + ($ctr ? self::MINIMUMS['ctr'][1] : 0)
            : max($ctr ? self::MINIMUMS['ctr'][1] : 0, ($mem ? self::MINIMUMS['mem'][1] : 0) + ($net ? self::MINIMUMS['net'][1] : 0));
        $height += GpuGrid::minHeight($gpus, $termWidth, $gpuColumns, $roster);

        return [$width, $height];
    }

    /**
     * Whether a `$cols` x `$rows` terminal fits `$boxes`.
     *
     * @param list<string> $boxes
     */
    public static function fits(int $cols, int $rows, array $boxes, ?GpuRoster $roster = null, ?int $gpuColumns = null, string $gpuInfo = 'Auto'): bool
    {
        [$w, $h] = self::minSize($boxes, $cols, $roster, $gpuColumns, $gpuInfo);

        return $cols >= $w && $rows >= $h;
    }

    /**
     * btop calcSizes for a `$cols` x `$rows` terminal.
     *
     * With gpu boxes shown (btop PR #1730/#1881) the cpu box shrinks to
     * `32 / (gpu rows + 1) + 5` % of the height, the gpu grid sits under it
     * ({@see GpuGrid::place()}) and mem / net / proc start below the grid.
     * The cpu cores sub-box grows by btop's `gpus_extra_height` — one row
     * per GPU the cpu box lists (show_gpu_info On: every GPU; Auto: the
     * GPUs without a box of their own).
     *
     * @param int        $coreCount logical cpus (sizes the cpu cores sub-box)
     * @param bool       $showTemp  check_temp && sensors found (widens core columns)
     * @param ?GpuRoster $roster    the detected accelerators (null = none known)
     */
    public static function layout(int $cols, int $rows, Config $config, int $coreCount, bool $showTemp = false, ?GpuRoster $roster = null): Layout
    {
        $roster ??= GpuRoster::none();
        // The VM dashboard eclipses mem/net/proc/ctr ({@see VmsMode}).
        $shown = VmsMode::effective($config->shownBoxes());
        $hasVms = VmsMode::active($shown);
        $gpuNames = array_values(array_filter($shown, static fn (string $b): bool => GpuPanels::index($b) !== null));
        $gpuTargets = GpuPanels::targets($shown);
        $gpuForced = $config->gpuBoxColumns();
        [, $gpuRows, $gpuTotal] = GpuGrid::prepass($gpuTargets, $cols, $gpuForced, $roster);
        $cpuBottom = $config->bool('cpu_bottom');
        $memBelowNet = $config->bool('mem_below_net');
        $procLeft = $config->bool('proc_left');
        $showDisks = $config->bool('show_disks');
        $hasCpu = in_array('cpu', $shown, true);
        $hasMem = in_array('mem', $shown, true);
        $hasNet = in_array('net', $shown, true);
        $hasProc = in_array('proc', $shown, true);
        $hasCtr = in_array('ctr', $shown, true);
        // candy-top's BMC box: a full-width band under the cpu box and the gpu grid.
        $hasIpmi = in_array('ipmi', $shown, true);
        // btop PR #1873 calcSizes: the ctr box shares the proc column, so
        // every "is the proc box shown" test of the side boxes asks this.
        $procColumn = $hasProc || $hasCtr;
        $coreCount = max(1, $coreCount);
        $temp = $showTemp ? 1 : 0;

        $boxes = [];
        $cpuH = 0;
        $cpuCores = null;
        $bColumns = 0;
        $bColumnSize = 0;
        $others = $hasMem || $hasNet || $procColumn || $hasVms || $hasIpmi;
        if ($hasCpu) {
            $w = (int) round($cols * self::RATIOS['cpu'][0] / 100);
            $onlyCpu = $shown === ['cpu'];
            // btop gpus_extra_height: the cpu box's GPU rows.
            $gpuMode = $config->string('show_gpu_info');
            $boxedGpus = count(array_unique(array_filter($gpuTargets, static fn (int $i): bool => $i < $roster->gpuCount())));
            $extra = match ($gpuMode) {
                'On' => $roster->gpuCount(),
                'Auto' => max(0, $roster->gpuCount() - $boxedGpus),
                default => 0,
            };
            if ($gpuNames !== [] && !$others) {
                $cpuH = $rows - $gpuTotal - $extra;
            } else {
                // btop's integer `height_p / (Gpu::rows + 1)`.
                $percent = $onlyCpu ? 100 : intdiv(self::RATIOS['cpu'][1], $gpuRows + 1) + ($gpuRows !== 0 ? 5 : 0);
                $cpuH = max(8, (int) ceil($rows * $percent / 100));
                // The VM dashboard wants the room: the cpu box only as tall as its cores need.
                $cpuH = $hasVms ? min($cpuH, self::vmsCpuHeight($w, $coreCount, $temp)) : $cpuH;
            }
            if ($cpuH <= $rows - $extra) {
                $cpuH += $extra;
            }
            $cpuH = max(0, $cpuH);
            $y = $cpuBottom ? $rows - $cpuH + 1 : 1;
            $boxes['cpu'] = Rect::new(0, $y - 1, $w, $cpuH);

            // GPU build: at least two core columns.
            $bColumns = max(2, (int) ceil(($coreCount + 1) / max(1, $cpuH - $extra - 5)));
            $bWidth = 0;
            if ($bColumns * (21 + 12 * $temp) < $w - intdiv($w, 3)) {
                $bColumnSize = 2;
                $bWidth = max(29, (21 + 12 * $temp) * $bColumns - ($bColumns - 1));
            } elseif ($bColumns * (15 + 6 * $temp) < $w - intdiv($w, 3)) {
                $bColumnSize = 1;
                $bWidth = (15 + 6 * $temp) * $bColumns - ($bColumns - 1);
            } elseif ($bColumns * (8 + 6 * $temp) < $w - intdiv($w, 3)) {
                $bColumnSize = 0;
            } else {
                $bColumns = intdiv($w - intdiv($w, 3), 8 + 6 * $temp);
                $bColumnSize = 0;
            }
            if ($bColumnSize === 0) {
                $bWidth = (8 + 6 * $temp) * $bColumns + 1;
            }
            $bHeight = min($cpuH - 2, (int) ceil($coreCount / max(1, $bColumns)) + 4 + $extra);
            $bx = 1 + $w - $bWidth - 1;
            $by = $y + (int) ceil(($cpuH - 2) / 2) - (int) ceil($bHeight / 2) + 1;
            $cpuCores = Rect::new($bx - 1, $by - 1, $bWidth, $bHeight);
        }

        [$gpuBoxes, $gpuHeight] = GpuGrid::place(
            $gpuNames,
            GpuPanels::slots($config),
            $cols,
            $rows,
            $gpuForced,
            $roster,
            $cpuH,
            $hasCpu,
            $cpuBottom,
            $others,
        );
        foreach ($gpuBoxes as $name => $gpuBox) {
            $boxes[$name] = $gpuBox->rect;
        }
        $gpuWithCpu = $gpuBoxes !== [] && $hasCpu;

        // The ipmi band takes its rows below the cpu box and the gpu grid,
        // and everything further down treats it as part of that top stack
        // ($gpuHeight is the grid alone, $topHeight the grid + the band).
        $ipmiH = 0;
        if ($hasIpmi) {
            $left = max(0, $rows - $cpuH - $gpuHeight);
            $rest = self::restMinHeight($hasVms, $hasMem, $hasNet, $hasProc, $hasCtr);
            [$share, $floor, $ceiling] = self::IPMI_BAND;
            $band = self::clamp((int) round($rows * $share / 100), $floor, $ceiling);
            $ipmiH = $rest === 0 ? $left : min($left, min($band, max($floor, $left - $rest)));
            $boxes['ipmi'] = Rect::new(0, (($cpuBottom && $hasCpu) ? 0 : $cpuH) + $gpuHeight, $cols, $ipmiH);
        }
        $topHeight = $gpuHeight + $ipmiH;

        $memH = 0;
        $memW = 0;
        $memWidth = 0;
        $disksWidth = 0;
        $divider = null;
        if ($hasMem) {
            $memW = self::sideWidth($cols, $procColumn, $config->procBoxWidthPercent(), self::MINIMUMS['mem'][0]);
            // GPU build: Net::height_p * shown * 4 / ((gpu and cpu shown) + 4).
            $netShare = intdiv(self::RATIOS['net'][1] * ($hasNet ? 1 : 0) * 4, ($gpuWithCpu ? 1 : 0) + 4);
            $memH = (int) floor($rows * (100 - $netShare) / 100) - $cpuH - $gpuHeight;
            if ($ipmiH > 0) {
                // Under the ipmi band mem and net share what is left in btop's proportion.
                $avail = max(1, $rows - $cpuH - $gpuHeight);
                $left = $avail - $ipmiH;
                $memH = (int) round($memH * $left / $avail);
                if ($hasNet) {
                    $memH = min(max($memH, min(self::MINIMUMS['mem'][1], $left - self::MINIMUMS['net'][1])), $left - self::MINIMUMS['net'][1]);
                }
                $memH = max(0, min($memH, $left));
            }
            $x = ($procLeft && $procColumn) ? $cols - $memW + 1 : 1;
            $y = ($memBelowNet && $hasNet)
                ? $rows - $memH + 1 - ($cpuBottom ? $cpuH : 0)
                : ($cpuBottom ? 1 : $cpuH + 1) + $topHeight;
            $boxes['mem'] = Rect::new($x - 1, $y - 1, $memW, $memH);
            if ($showDisks) {
                $memWidth = (int) ceil(($memW - 3) / 2);
                $memWidth += $memWidth % 2;
                $disksWidth = $memW - $memWidth - 2;
                $divider = $x - 1 + $memWidth;
            } else {
                $memWidth = $memW - 1;
            }
        }

        $netW = 0;
        $netStats = null;
        if ($hasNet) {
            $netW = self::sideWidth($cols, $procColumn, $config->procBoxWidthPercent(), self::MINIMUMS['net'][0]);
            $netH = $rows - $cpuH - $topHeight - $memH;
            $x = ($procLeft && $procColumn) ? $cols - $netW + 1 : 1;
            $y = ($memBelowNet && $hasMem)
                ? ($cpuBottom ? 1 : $cpuH + 1) + $topHeight
                : $rows - $netH + 1 - ($cpuBottom ? $cpuH : 0);
            $boxes['net'] = Rect::new($x - 1, $y - 1, $netW, $netH);
            $bWidth = $netW > 45 ? 27 : 19;
            $bHeight = $netH > 10 ? 9 : $netH - 2;
            $bx = $x + $netW - $bWidth - 1;
            $by = $y + intdiv($netH - 2, 2) - intdiv($bHeight, 2) + 1;
            $netStats = Rect::new($bx - 1, $by - 1, $bWidth, $bHeight);
        }

        $selectMax = 0;
        if ($procColumn) {
            $procW = $cols - ($hasMem ? $memW : ($hasNet ? $netW : 0));
            $procH = $rows - $cpuH - $topHeight;
            $x = $procLeft ? 1 : $cols - $procW + 1;
            $y = (($cpuBottom && $hasCpu) ? 1 : $cpuH + 1) + $topHeight;
            // btop PR #1873: the ctr box takes the top of the column — a
            // third of it beside a proc box (at least 6 rows, leaving proc
            // its 16), the whole column alone.
            if ($hasCtr) {
                $minH = self::MINIMUMS['ctr'][1];
                $ctrH = $hasProc ? self::clamp(intdiv($procH, 3), $minH, max($minH, $procH - self::MINIMUMS['proc'][1])) : $procH;
                $boxes['ctr'] = Rect::new($x - 1, $y - 1, $procW, $ctrH);
                $y += $ctrH;
                $procH -= $ctrH;
            }
            if ($hasProc) {
                $boxes['proc'] = Rect::new($x - 1, $y - 1, $procW, $procH);
                $selectMax = $procH - 3;
            }
        }

        // The VM dashboard: everything below the cpu box and the gpu grid.
        if ($hasVms) {
            $vmsY = (($cpuBottom && $hasCpu) ? 0 : $cpuH) + $topHeight;
            $boxes['vms'] = Rect::new(0, $vmsY, $cols, max(0, $rows - $cpuH - $topHeight));
        }

        return new Layout(
            width: $cols,
            height: $rows,
            boxes: $boxes,
            cpuCores: $cpuCores,
            coreColumns: $bColumns,
            coreColumnSize: $bColumnSize,
            memWidth: $memWidth,
            disksWidth: $disksWidth,
            memDivider: $divider,
            netStats: $netStats,
            procSelectMax: $selectMax,
            cpuBottom: $cpuBottom,
            memBelowNet: $memBelowNet,
            procLeft: $procLeft,
            showDisks: $showDisks,
            gpuBoxes: $gpuBoxes,
            gpuColumns: GpuGrid::columns(count($gpuNames), $cols, $gpuForced),
            gpuHeight: $gpuHeight,
        );
    }

    /**
     * The mem / net column width — btop PR #1476 calcSizes: with the proc
     * box shown, `round(cols * (100 - proc_box_width_percent) / 100)`
     * clamped (std::clamp) between the box's own minimum and what leaves
     * the proc box its 44 columns; the whole width without proc. At the
     * default 55 % this is btop 1.4.7's `round(cols * 45 / 100)`.
     */
    public static function sideWidth(int $cols, bool $hasProc, int $procPercent, int $minWidth): int
    {
        if (!$hasProc) {
            return $cols;
        }

        return self::clamp((int) round($cols * (100 - $procPercent) / 100), $minWidth, $cols - self::MINIMUMS['proc'][0]);
    }

    /**
     * btop's `std::clamp(v, lo, hi)` as libstdc++ evaluates it — `v < lo ?
     * lo : (hi < v ? hi : v)` — including the inverted-bounds case (hi < lo)
     * the width keys can produce on a narrow terminal.
     */
    public static function clamp(int $v, int $lo, int $hi): int
    {
        return $v < $lo ? $lo : ($hi < $v ? $hi : $v);
    }

    /** The border family: rounded unless rounded_corners is off or TTY mode is on. */
    public static function border(Config $config): Border
    {
        return $config->roundedCorners() ? Border::rounded() : Border::normal();
    }

    /**
     * Paint every shown box's static chrome (what btop caches in `X::box`)
     * plus the cpu title buttons.
     *
     * @param ?int $preset active preset index; null renders btop's `*`
     */
    public static function paintChrome(Surface $surface, Layout $layout, Ink $ink, Config $config, HostInfo $host, ?int $preset = null, int $clockWidth = self::DEFAULT_CLOCK_WIDTH): void
    {
        $border = self::border($config);
        $tty = $config->ttyMode();
        $numbers = ['cpu' => 1, 'mem' => 2, 'net' => 3, 'proc' => 4];
        foreach ($layout->ordered() as $name => $rect) {
            $gpu = $layout->gpuBox($name);
            if ($gpu !== null) {
                self::paintGpuChrome($surface, $gpu, $ink, $config, $border, $tty);

                continue;
            }
            if ($name === 'ctr') {
                self::paintCtrChrome($surface, $rect, $ink, $border, $tty);

                continue;
            }
            if ($name === VmsMode::BOX) {
                self::paintVmsChrome($surface, $rect, $ink, $border, $tty);

                continue;
            }
            if ($name === 'ipmi') {
                self::paintIpmiChrome($surface, $rect, $ink, $border, $tty);

                continue;
            }
            $title = Lang::t('box.' . $name);
            $bottomTitle = $name === 'cpu' && $layout->cpuBottom;
            BoxChrome::paint(
                $surface,
                $rect,
                $ink->fg($name . '_box'),
                $ink,
                $border,
                fill: true,
                title: $bottomTitle ? '' : $title,
                title2: $bottomTitle ? $title : '',
                num: $numbers[$name],
                tty: $tty,
            );
        }

        if ($layout->cpuCores !== null) {
            $hasHz = $config->bool('show_cpu_freq') && $host->hasCpuHz;
            $custom = $config->string('custom_cpu_name');
            $budget = $layout->cpuCores->width - ($hasHz ? ($config->string('freq_mode') === 'range' ? 24 : 14) : 5);
            $name = Width::truncate($custom !== '' ? $custom : $host->cpuName, max(0, $budget));
            BoxChrome::paint($surface, $layout->cpuCores, $ink->fg('div_line'), $ink, $border, fill: false, title: $name, tty: $tty);
            self::paintCpuButtons($surface, $layout, $ink, $config, $border, $preset, $host->containerEngine, $clockWidth);
            self::paintVmsButton($surface, $layout, $ink, $config, $border, $host, $clockWidth);
            self::paintIpmiButton($surface, $layout, $ink, $config, $border, $host, $clockWidth);
        }

        $mem = $layout->box('mem');
        if ($mem !== null) {
            $memSgr = $ink->fg('mem_box');
            $label = Lang::t('box.disks');
            $at = $layout->showDisks ? $layout->memDivider + 2 : $mem->x + $mem->width - 9;
            BoxChrome::embed(
                $surface,
                $at,
                $mem->y,
                ($layout->showDisks ? Symbols::BOLD : '') . self::hotkey($label, 'd', $ink),
                $memSgr,
                $border,
                bottom: false,
                clip: $mem,
            );
            if ($layout->memDivider !== null) {
                $surface->put($layout->memDivider, $mem->y, $border->seam(true, true, false, true), $memSgr, $mem);
                $surface->put($layout->memDivider, $mem->bottom() - 1, $border->seam(true, true, true, false), $memSgr, $mem);
                for ($y = $mem->y + 1; $y < $mem->bottom() - 1; $y++) {
                    $surface->put($layout->memDivider, $y, $border->left, $ink->fg('div_line'), $mem);
                }
            }
        }

        if ($layout->netStats !== null) {
            $swap = $config->bool('swap_upload_download');
            $down = Lang::t('net.download');
            $up = Lang::t('net.upload');
            BoxChrome::paint(
                $surface,
                $layout->netStats,
                $ink->fg('div_line'),
                $ink,
                $border,
                fill: false,
                title: $swap ? $up : $down,
                title2: $swap ? $down : $up,
                tty: $tty,
            );
        }
    }

    /**
     * A gpu box's static chrome — btop calcSizes' gpu block with PR
     * #1730's titles: the outline in cpu_box colour (btop's `// TODO
     * gpu_box`) titled `gpu` (`npu` for an NPU, #985) numbered with the
     * slot's toggle key (none for key 0, as btop's createBox), the
     * `← gpuN →` target selector right-aligned on the top border while
     * more than one accelerator exists and the box is wide enough, and the
     * stats sub-box titled with custom_gpu_name<N> or the model name cut
     * to `b_width - 5` cells.
     */
    private static function paintGpuChrome(Surface $surface, GpuBox $gpu, Ink $ink, Config $config, Border $border, bool $tty): void
    {
        $line = $ink->fg('cpu_box');
        BoxChrome::paint(
            $surface,
            $gpu->rect,
            $line,
            $ink,
            $border,
            fill: true,
            title: Lang::t($gpu->npu ? 'box.npu' : 'box.gpu'),
            num: $gpu->key(),
            tty: $tty,
        );
        if ($gpu->selector !== null) {
            BoxChrome::embed(
                $surface,
                $gpu->selector[0] - 1,
                $gpu->rect->y,
                Symbols::BOLD . $ink->fg('title') . Symbols::LEFT . ' ' . $gpu->label . ' ' . Symbols::RIGHT,
                $line,
                $border,
                clip: $gpu->rect,
            );
        }
        $key = 'custom_gpu_name' . $gpu->index;
        $custom = $config->has($key) ? $config->string($key) : '';
        $name = Width::truncate($custom !== '' ? $custom : $gpu->deviceName, max(0, $gpu->stats->width - 5));
        BoxChrome::paint($surface, $gpu->stats, $ink->fg('div_line'), $ink, $border, fill: false, title: $name, tty: $tty);
    }

    /**
     * btop PR #1873 calcSizes' ctr box: createBox in proc_box colour with no
     * built-in title, then its own `ˣctr` title (`x` in tty mode) — the
     * superscript names the toggle key, as the digits do for 1-4.
     */
    private static function paintCtrChrome(Surface $surface, Rect $rect, Ink $ink, Border $border, bool $tty): void
    {
        $line = $ink->fg('proc_box');
        BoxChrome::paint($surface, $rect, $line, $ink, $border, fill: true, tty: $tty);
        if ($rect->width < 2 || $rect->height < 2) {
            return;
        }
        BoxChrome::embed(
            $surface,
            $rect->x + 2,
            $rect->y,
            Symbols::BOLD . $ink->fg('hi_fg') . ($tty ? 'x' : 'ˣ') . $ink->fg('title') . Lang::t('box.ctr'),
            $line,
            $border,
            false,
            $rect,
        );
    }

    /**
     * The VM dashboard's static chrome (candy-top's own): the outline in
     * the {@see VmsMode} family colour and its `ᵛvms` title (`v` in tty
     * mode), the superscript naming the toggle key as for ctr. The panel
     * repaints the outline with the theme's flow and adds its readouts.
     */
    private static function paintVmsChrome(Surface $surface, Rect $rect, Ink $ink, Border $border, bool $tty): void
    {
        $line = $ink->fg(self::VMS_FAMILY . '_box');
        BoxChrome::paint($surface, $rect, $line, $ink, $border, fill: true, tty: $tty);
        if ($rect->width >= 2 && $rect->height >= 2) {
            BoxChrome::embed($surface, $rect->x + 2, $rect->y, self::vmsTitle($ink, $tty), $line, $border, false, $rect);
        }
    }

    /**
     * The fewest rows the boxes laid out under the ipmi band need (their
     * {@see MINIMUMS}): the VM dashboard alone, or the proc column, or the
     * mem + net stack — the band never squeezes them below that.
     */
    private static function restMinHeight(bool $vms, bool $mem, bool $net, bool $proc, bool $ctr): int
    {
        if ($vms) {
            return self::MINIMUMS['vms'][1];
        }
        $side = ($mem ? self::MINIMUMS['mem'][1] : 0) + ($net ? self::MINIMUMS['net'][1] : 0);
        $column = ($proc ? self::MINIMUMS['proc'][1] : 0) + ($ctr ? self::MINIMUMS['ctr'][1] : 0);

        return max($side, $column);
    }

    /**
     * The ipmi band's static chrome: the outline in the {@see IPMI_FAMILY}
     * colour and its `ᴵipmi` title (`I` in tty mode), the superscript
     * naming the toggle key as for ctr / vms.
     */
    private static function paintIpmiChrome(Surface $surface, Rect $rect, Ink $ink, Border $border, bool $tty): void
    {
        $line = $ink->fg(self::IPMI_FAMILY . '_box');
        BoxChrome::paint($surface, $rect, $line, $ink, $border, fill: true, tty: $tty);
        if ($rect->width >= 2 && $rect->height >= 2) {
            BoxChrome::embed(
                $surface,
                $rect->x + 2,
                $rect->y,
                Symbols::BOLD . $ink->fg('hi_fg') . ($tty ? 'I' : 'ᴵ') . $ink->fg('title') . Lang::t('box.ipmi'),
                $line,
                $border,
                false,
                $rect,
            );
        }
    }

    /**
     * The cpu title's `I` mouse zone (0-based [x, y, w, h]) or null — the
     * `IPMI` button after the `vms` button (or after `x ctr` / the engine
     * label when there is none), drawn on a host with a BMC device
     * ({@see HostInfo::$bmcHost}) or while the box is shown, and only
     * while it clears the clock's left junction.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function ipmiZone(Rect $cpu, int $y, string $engine, int $clockWidth, bool $vmsButton, bool $show): ?array
    {
        if (!$show) {
            return null;
        }
        $before = self::vmsZone($cpu, $y, $engine, $clockWidth, $vmsButton) ?? self::ctrZone($cpu, $y, $engine, $clockWidth);
        if ($before === null) {
            return null;
        }
        $at = $before[0] + $before[2] + 2;
        $label = Lang::t('button.ipmi');
        $width = Width::string($label) + (mb_strpos($label, 'I') === false ? 2 : 0);
        $limit = $clockWidth > 0 ? $cpu->x + intdiv($cpu->width, 2) - intdiv($clockWidth, 2) : $cpu->x + $cpu->width - 18;

        return $at + $width < $limit ? [$at, $y, $width, 1] : null;
    }

    /** The `IPMI` title button ({@see ipmiZone()}), its `I` lit. */
    private static function paintIpmiButton(Surface $surface, Layout $layout, Ink $ink, Config $config, Border $border, HostInfo $host, int $clockWidth): void
    {
        $cpu = $layout->box('cpu');
        if ($cpu === null) {
            return;
        }
        $y = $layout->cpuBottom ? $cpu->bottom() - 1 : $cpu->y;
        $shown = $config->shownBoxes();
        $zone = self::ipmiZone($cpu, $y, $host->containerEngine, $clockWidth, $host->vmHost || VmsMode::active($shown), $host->bmcHost || in_array('ipmi', $shown, true));
        if ($zone === null) {
            return;
        }
        $label = Lang::t('button.ipmi');
        $inner = mb_strpos($label, 'I') === false
            ? $ink->fg('hi_fg') . 'I' . $ink->fg('title') . ' ' . $label
            : $ink->fg('title') . mb_substr($label, 0, (int) mb_strpos($label, 'I')) . $ink->fg('hi_fg') . 'I' . $ink->fg('title') . mb_substr($label, (int) mb_strpos($label, 'I') + 1);
        BoxChrome::embed($surface, $zone[0] - 1, $y, Symbols::BOLD . $inner, $ink->fg('cpu_box'), $border, $layout->cpuBottom, $cpu);
    }

    /**
     * The cpu box height (before its GPU rows) beside the VM dashboard: the
     * lowest from btop's minimum 8 at which calcSizes still fits every
     * core in compact columns (`b_columns * (15 + 6 * temp) < w - w/3`)
     * AND the cores box's rows (`ceil(cores / b_columns) + 4`) inside the
     * box, so the dashboard gets the rows the cpu graph would mostly
     * leave empty without hiding a core.
     * PHP_INT_MAX (no cap) when no height does.
     */
    public static function vmsCpuHeight(int $width, int $coreCount, int $temp): int
    {
        for ($h = self::MINIMUMS['cpu'][1]; $h <= 200; $h++) {
            $columns = max(2, (int) ceil((max(1, $coreCount) + 1) / max(1, $h - 5)));
            if ($columns * (15 + 6 * $temp) < $width - intdiv($width, 3) && (int) ceil(max(1, $coreCount) / $columns) + 4 <= $h - 2) {
                return $h;
            }
        }

        return PHP_INT_MAX;
    }

    /** The box family the VM dashboard and its cards are outlined in (`mem_box` and its flow). */
    public const VMS_FAMILY = 'mem';

    /** The dashboard's embedded title: superscript toggle key + `vms`. */
    public static function vmsTitle(Ink $ink, bool $tty): string
    {
        return Symbols::BOLD . $ink->fg('hi_fg') . ($tty ? 'v' : 'ᵛ') . $ink->fg('title') . Lang::t('box.vms');
    }

    /**
     * The cpu title's `v` mouse zone (0-based [x, y, w, h]) or null — the
     * `vms` button right after the `x ctr` button (or the engine label),
     * drawn on a VM host ({@see HostInfo::$vmHost}) or while the
     * dashboard is shown, and only while it clears the clock's left
     * junction (from about 88 columns with the default clock).
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function vmsZone(Rect $cpu, int $y, string $engine, int $clockWidth, bool $show): ?array
    {
        $ctr = self::ctrZone($cpu, $y, $engine, $clockWidth);
        if (!$show || $ctr === null) {
            return null;
        }
        $at = $ctr[0] + $ctr[2] + 2;
        $width = Width::string(Lang::t('button.vms')) + (mb_stripos(Lang::t('button.vms'), 'v') === false ? 2 : 0);
        $limit = $clockWidth > 0 ? $cpu->x + intdiv($cpu->width, 2) - intdiv($clockWidth, 2) : $cpu->x + $cpu->width - 18;

        return $at + $width < $limit ? [$at, $y, $width, 1] : null;
    }

    /** The `vms` title button ({@see vmsZone()}): `┐vms┌` with its `v` lit, like `menu`'s `m`. */
    private static function paintVmsButton(Surface $surface, Layout $layout, Ink $ink, Config $config, Border $border, HostInfo $host, int $clockWidth): void
    {
        $cpu = $layout->box('cpu');
        if ($cpu === null) {
            return;
        }
        $y = $layout->cpuBottom ? $cpu->bottom() - 1 : $cpu->y;
        $shown = VmsMode::active($config->shownBoxes());
        $zone = self::vmsZone($cpu, $y, $host->containerEngine, $clockWidth, $host->vmHost || $shown);
        if ($zone === null) {
            return;
        }
        BoxChrome::embed(
            $surface,
            $zone[0] - 1,
            $y,
            Symbols::BOLD . self::hotkey(Lang::t('button.vms'), 'v', $ink),
            $ink->fg('cpu_box'),
            $border,
            $layout->cpuBottom,
            $cpu,
        );
    }

    /**
     * Width budget for the border clock — btop update_clock:
     * `max(10, width - 66 - (battery ? 22 : 0))`, the battery reserve only
     * applying on terminals at least 100 columns wide.
     */
    public static function clockBudget(int $cpuWidth, int $termWidth, bool $battery): int
    {
        return max(10, $cpuWidth - 66 - ($termWidth >= 100 && $battery ? 22 : 0));
    }

    /**
     * Embed `$clock` centred on the cpu box's top border (bottom when
     * cpu_bottom), clipped to the budget — btop Draw::update_clock.
     */
    public static function paintClock(Surface $surface, Layout $layout, Ink $ink, Config $config, string $clock, bool $battery = false): void
    {
        $cpu = $layout->box('cpu');
        if ($cpu === null || $clock === '') {
            return;
        }
        $clock = Width::truncate($clock, self::clockBudget($cpu->width, $layout->width, $battery));
        $len = Width::string($clock);
        $x = $cpu->x + intdiv($cpu->width, 2) - intdiv($len, 2);
        $y = $layout->cpuBottom ? $cpu->bottom() - 1 : $cpu->y;
        BoxChrome::embed(
            $surface,
            $x,
            $y,
            $ink->fg('title') . Symbols::BOLD . $clock,
            $ink->fg('cpu_box'),
            self::border($config),
            bottom: $layout->cpuBottom,
            clip: $cpu,
        );
    }

    /** The drawn width of btop's default `%X` clock (HH:MM:SS) — what callers without the clock assume. */
    public const DEFAULT_CLOCK_WIDTH = 8;

    /**
     * The width {@see paintClock()} will draw `$clock` at (cut to the same
     * budget), 0 for no clock — the input of {@see engineLabel()} and
     * {@see ctrZone()}, so the `x ctr` slot never runs under the clock.
     */
    public static function clockWidth(Layout $layout, string $clock, bool $battery = false): int
    {
        $cpu = $layout->box('cpu');
        if ($cpu === null || $clock === '') {
            return 0;
        }

        return Width::string(Width::truncate($clock, self::clockBudget($cpu->width, $layout->width, $battery)));
    }

    /**
     * The container engine as drawn on the cpu title (btop
     * `Cpu::container_engine`, title colour, no hotkey), or null when not
     * in a container or there is no room.
     *
     * Beyond btop, which prints the whole name at `x + 28` whatever the
     * width (a long one runs under the clock): the label takes the `x ctr`
     * button's own slot (`x + 26`) and is cut to the cells left before the
     * centred clock as actually drawn (`$clockWidth`, {@see clockWidth()});
     * fewer than 3 cells and it is not drawn.
     */
    public static function engineLabel(Rect $cpu, string $engine, int $clockWidth = self::DEFAULT_CLOCK_WIDTH): ?string
    {
        if ($engine === '') {
            return null;
        }
        $room = min(ContainerEngine::MAX_LENGTH, self::ctrRoom($cpu, $clockWidth));

        return $room < 3 ? null : Width::truncate($engine, $room);
    }

    /**
     * The cpu title's `x` mouse zone (0-based [x, y, w, h]) or null — the
     * single source, with {@see engineLabel()}, of what paintCpuButtons
     * draws there: btop `{button_y, x + 27, 1, 5}` over `x ctr` (from
     * {@see CTR_BUTTON_MIN_WIDTH}, and only while it clears the clock);
     * over the engine label when one is drawn instead (btop maps nothing
     * there — candy-top keeps the click toggling the box, as `x` does).
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    public static function ctrZone(Rect $cpu, int $y, string $engine, int $clockWidth = self::DEFAULT_CLOCK_WIDTH): ?array
    {
        $label = self::engineLabel($cpu, $engine, $clockWidth);
        if ($label !== null) {
            return [$cpu->x + 27, $y, Width::string($label), 1];
        }
        $button = Width::string(Lang::t('button.ctr')) + 2;
        if ($engine === '' && $cpu->width >= self::CTR_BUTTON_MIN_WIDTH && $button <= self::ctrRoom($cpu, $clockWidth)) {
            return [$cpu->x + 27, $y, $button, 1];
        }

        return null;
    }

    /**
     * Cells free for text from `x + 27` up to (not touching) the clock's
     * left junction at `x + w/2 - len/2` ({@see paintClock()}); with no
     * clock, up to the `- 2000ms +` button's usual place.
     */
    private static function ctrRoom(Rect $cpu, int $clockWidth): int
    {
        $limit = $clockWidth > 0 ? intdiv($cpu->width, 2) - intdiv($clockWidth, 2) : $cpu->width - 18;

        return $limit - 28;
    }

    /** btop's `- 2000ms +` / `menu` / `preset *` buttons on the cpu title row. */
    private static function paintCpuButtons(Surface $surface, Layout $layout, Ink $ink, Config $config, Border $border, ?int $preset, string $engine = '', int $clockWidth = self::DEFAULT_CLOCK_WIDTH): void
    {
        $cpu = $layout->box('cpu');
        if ($cpu === null) {
            return;
        }
        $y = $layout->cpuBottom ? $cpu->bottom() - 1 : $cpu->y;
        $line = $ink->fg('cpu_box');
        $bottom = $layout->cpuBottom;
        BoxChrome::embed($surface, $cpu->x + 10, $y, Symbols::BOLD . self::hotkey(Lang::t('button.menu'), 'm', $ink), $line, $border, $bottom, $cpu);
        BoxChrome::embed(
            $surface,
            $cpu->x + 16,
            $y,
            Symbols::BOLD . self::hotkey(Lang::t('button.preset'), 'p', $ink) . ' ' . ($preset === null ? '*' : (string) $preset),
            $line,
            $border,
            $bottom,
            $cpu,
        );
        // btop PR #1873: `x ctr` between the preset button and the clock —
        // or, running inside a container, the engine's name in its place.
        // ctrZone() decides both: what is drawn here and where `x` clicks land.
        $label = self::engineLabel($cpu, $engine, $clockWidth);
        if ($label !== null) {
            BoxChrome::embed($surface, $cpu->x + 26, $y, $ink->fg('title') . $label, $line, $border, $bottom, $cpu);
        } elseif (self::ctrZone($cpu, $y, $engine, $clockWidth) !== null) {
            BoxChrome::embed($surface, $cpu->x + 26, $y, Symbols::BOLD . self::hotkey(Lang::t('button.ctr'), 'x', $ink), $line, $border, $bottom, $cpu);
        }
        $update = $config->updateMs() . 'ms';
        $len = strlen($update);
        BoxChrome::embed(
            $surface,
            $cpu->x + $cpu->width - $len - 8,
            $y,
            Symbols::BOLD . $ink->fg('hi_fg') . '- ' . $ink->fg('title') . $update . $ink->fg('hi_fg') . ' +',
            $line,
            $border,
            $bottom,
            $cpu,
        );
    }

    /**
     * btop's button label: the hotkey in hi_fg, the rest in title. The
     * highlight marks the REAL key, not the label's first letter — a
     * translated "Menü" still lights its `M`, and a label without the key
     * letter gets the key rendered in front of it (`p Voreinst.`), so the
     * button never advertises a key that does nothing.
     */
    public static function hotkey(string $label, string $key, Ink $ink): string
    {
        $hi = $ink->fg('hi_fg');
        $title = $ink->fg('title');
        $at = mb_stripos($label, $key);
        if ($at === false) {
            return $hi . $key . $title . ' ' . $label;
        }

        return $title . mb_substr($label, 0, $at)
            . $hi . mb_substr($label, $at, mb_strlen($key))
            . $title . mb_substr($label, $at + mb_strlen($key));
    }
}
