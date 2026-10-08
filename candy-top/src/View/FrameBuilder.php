<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Lang;

/**
 * The frame layout engine and its box chrome.
 *
 * {@see layout()} ports btop's `Draw::calcSizes` (src/btop_draw.cpp) as built
 * with GPU_SUPPORT — the default Linux build — with zero GPU panels, so the
 * mem height subtracts the cpu box's REAL height rather than its 32 %
 * share (the non-GPU build's formula differs by a row on some sizes; the
 * GPU build is what Linux users run). Box ratios / minimums:
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
    ];

    private function __construct()
    {
    }

    /**
     * Minimum terminal size for `$boxes` — btop Term::get_min_size.
     *
     * @param list<string> $boxes
     * @return array{0: int, 1: int} [width, height]
     */
    public static function minSize(array $boxes): array
    {
        $cpu = in_array('cpu', $boxes, true);
        $mem = in_array('mem', $boxes, true);
        $net = in_array('net', $boxes, true);
        $proc = in_array('proc', $boxes, true);

        $width = ($mem || $net) ? self::MINIMUMS['mem'][0] : 0;
        $width += $proc ? self::MINIMUMS['proc'][0] : 0;
        if ($cpu && $width < self::MINIMUMS['cpu'][0]) {
            $width = self::MINIMUMS['cpu'][0];
        }
        $height = $cpu ? self::MINIMUMS['cpu'][1] : 0;
        $height += $proc
            ? self::MINIMUMS['proc'][1]
            : ($mem ? self::MINIMUMS['mem'][1] : 0) + ($net ? self::MINIMUMS['net'][1] : 0);

        return [$width, $height];
    }

    /** Whether a `$cols` x `$rows` terminal fits `$boxes`. */
    public static function fits(int $cols, int $rows, array $boxes): bool
    {
        [$w, $h] = self::minSize($boxes);

        return $cols >= $w && $rows >= $h;
    }

    /**
     * btop calcSizes for a `$cols` x `$rows` terminal.
     *
     * @param int  $coreCount logical cpus (sizes the cpu cores sub-box)
     * @param bool $showTemp  check_temp && sensors found (widens core columns)
     */
    public static function layout(int $cols, int $rows, Config $config, int $coreCount, bool $showTemp = false): Layout
    {
        $shown = $config->shownBoxes();
        $cpuBottom = $config->bool('cpu_bottom');
        $memBelowNet = $config->bool('mem_below_net');
        $procLeft = $config->bool('proc_left');
        $showDisks = $config->bool('show_disks');
        $hasCpu = in_array('cpu', $shown, true);
        $hasMem = in_array('mem', $shown, true);
        $hasNet = in_array('net', $shown, true);
        $hasProc = in_array('proc', $shown, true);
        $coreCount = max(1, $coreCount);
        $temp = $showTemp ? 1 : 0;

        $boxes = [];
        $cpuH = 0;
        $cpuCores = null;
        $bColumns = 0;
        $bColumnSize = 0;
        if ($hasCpu) {
            $w = (int) round($cols * self::RATIOS['cpu'][0] / 100);
            $onlyCpu = $shown === ['cpu'];
            $cpuH = max(8, (int) ceil($rows * ($onlyCpu ? 100 : self::RATIOS['cpu'][1]) / 100));
            $y = $cpuBottom ? $rows - $cpuH + 1 : 1;
            $boxes['cpu'] = Rect::new(0, $y - 1, $w, $cpuH);

            // GPU build: at least two core columns.
            $bColumns = max(2, (int) ceil(($coreCount + 1) / max(1, $cpuH - 5)));
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
            $bHeight = min($cpuH - 2, (int) ceil($coreCount / max(1, $bColumns)) + 4);
            $bx = 1 + $w - $bWidth - 1;
            $by = $y + (int) ceil(($cpuH - 2) / 2) - (int) ceil($bHeight / 2) + 1;
            $cpuCores = Rect::new($bx - 1, $by - 1, $bWidth, $bHeight);
        }

        $memH = 0;
        $memW = 0;
        $memWidth = 0;
        $disksWidth = 0;
        $divider = null;
        if ($hasMem) {
            $memW = self::sideWidth($cols, $hasProc, $config->procBoxWidthPercent(), self::MINIMUMS['mem'][0]);
            // GPU build, zero gpus: Net::height_p * shown * 4 / (0 + 4).
            $netShare = intdiv(self::RATIOS['net'][1] * ($hasNet ? 1 : 0) * 4, 4);
            $memH = (int) floor($rows * (100 - $netShare) / 100) - $cpuH;
            $x = ($procLeft && $hasProc) ? $cols - $memW + 1 : 1;
            $y = ($memBelowNet && $hasNet)
                ? $rows - $memH + 1 - ($cpuBottom ? $cpuH : 0)
                : ($cpuBottom ? 1 : $cpuH + 1);
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
            $netW = self::sideWidth($cols, $hasProc, $config->procBoxWidthPercent(), self::MINIMUMS['net'][0]);
            $netH = $rows - $cpuH - $memH;
            $x = ($procLeft && $hasProc) ? $cols - $netW + 1 : 1;
            $y = ($memBelowNet && $hasMem)
                ? ($cpuBottom ? 1 : $cpuH + 1)
                : $rows - $netH + 1 - ($cpuBottom ? $cpuH : 0);
            $boxes['net'] = Rect::new($x - 1, $y - 1, $netW, $netH);
            $bWidth = $netW > 45 ? 27 : 19;
            $bHeight = $netH > 10 ? 9 : $netH - 2;
            $bx = $x + $netW - $bWidth - 1;
            $by = $y + intdiv($netH - 2, 2) - intdiv($bHeight, 2) + 1;
            $netStats = Rect::new($bx - 1, $by - 1, $bWidth, $bHeight);
        }

        $selectMax = 0;
        if ($hasProc) {
            $procW = $cols - ($hasMem ? $memW : ($hasNet ? $netW : 0));
            $procH = $rows - $cpuH;
            $x = $procLeft ? 1 : $cols - $procW + 1;
            $y = ($cpuBottom && $hasCpu) ? 1 : $cpuH + 1;
            $boxes['proc'] = Rect::new($x - 1, $y - 1, $procW, $procH);
            $selectMax = $procH - 3;
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
    public static function paintChrome(Surface $surface, Layout $layout, Ink $ink, Config $config, HostInfo $host, ?int $preset = null): void
    {
        $border = self::border($config);
        $tty = $config->ttyMode();
        $numbers = ['cpu' => 1, 'mem' => 2, 'net' => 3, 'proc' => 4];
        foreach ($layout->ordered() as $name => $rect) {
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
            $name = \SugarCraft\Core\Util\Width::truncate($custom !== '' ? $custom : $host->cpuName, max(0, $budget));
            BoxChrome::paint($surface, $layout->cpuCores, $ink->fg('div_line'), $ink, $border, fill: false, title: $name, tty: $tty);
            self::paintCpuButtons($surface, $layout, $ink, $config, $border, $preset);
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
        $clock = \SugarCraft\Core\Util\Width::truncate($clock, self::clockBudget($cpu->width, $layout->width, $battery));
        $len = \SugarCraft\Core\Util\Width::string($clock);
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

    /** btop's `- 2000ms +` / `menu` / `preset *` buttons on the cpu title row. */
    private static function paintCpuButtons(Surface $surface, Layout $layout, Ink $ink, Config $config, Border $border, ?int $preset): void
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
