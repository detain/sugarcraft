<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Config\GpuPanels;

/**
 * btop PR #1881's gpu box grid: the shown gpu boxes are placed side by
 * side, as many per row as the terminal width allows (or as
 * `gpu_box_columns` forces), every box sharing one width and one height,
 * with a single blank column between neighbours. The box width picks the
 * detail level ({@see GpuDetail}) and so the stats sub-box width and the
 * rows a box needs.
 *
 * Mirrors aristocratos/btop PR #1881 Gpu::fit_columns,
 * min_total_width, min_total_height and the gpu part of
 * Draw::calcSizes (box geometry with PR #1730's slot titles and
 * `← gpuN →` selector).
 */
final class GpuGrid
{
    /** btop Gpu::box_min_width — the narrowest box (the Minimal detail width). */
    public const BOX_MIN_WIDTH = 34;

    /** btop Gpu::min_height. */
    public const MIN_HEIGHT = 8;

    /** btop Gpu::height_p. */
    public const HEIGHT_PERCENT = 32;

    private function __construct()
    {
    }

    /**
     * btop fit_columns: boxes per row — gpu_box_columns (null = Auto) but
     * never more than fit at {@see BOX_MIN_WIDTH} + 1 per box, nor more
     * than there are boxes.
     */
    public static function columns(int $boxes, int $termWidth, ?int $forced): int
    {
        if ($boxes <= 0) {
            return 1;
        }
        $maxFit = max(1, intdiv($termWidth, self::BOX_MIN_WIDTH + 1));
        $want = $forced === null ? $maxFit : max(1, min(6, $forced));

        return max(1, min($boxes, min($want, $maxFit)));
    }

    /** btop min_total_width. */
    public static function minWidth(int $boxes, int $termWidth, ?int $forced): int
    {
        if ($boxes <= 0) {
            return 0;
        }
        $columns = self::columns($boxes, $termWidth, $forced);

        return $columns * self::BOX_MIN_WIDTH + ($columns - 1);
    }

    /**
     * btop min_total_height: rows of boxes times the tallest box any
     * detected accelerator needs at the box width this terminal gives
     * (btop walks every gpu_b_height_offsets entry, not only the shown
     * ones; with nothing detected yet the shown boxes' default offsets).
     *
     * @param list<int> $shown GPU indexes of the shown gpu boxes
     */
    public static function minHeight(array $shown, int $termWidth, ?int $forced, GpuRoster $roster): int
    {
        $boxes = \count($shown);
        if ($boxes <= 0) {
            return 0;
        }
        $columns = self::columns($boxes, $termWidth, $forced);
        $rows = intdiv($boxes + $columns - 1, $columns);
        $detail = GpuDetail::forWidth(max(self::BOX_MIN_WIDTH, intdiv($termWidth - ($columns - 1), $columns)));
        $needed = 0;
        foreach ($roster->count() > 0 ? $roster->offsets : array_map($roster->offset(...), $shown) as $offset) {
            $needed = max($needed, $detail->height($offset));
        }

        return $rows * $needed;
    }

    /**
     * The calcSizes pre-pass: rows of boxes and btop's provisional
     * `Gpu::total_height` (rows times the tallest `4 + offset` among the
     * shown boxes), which the cpu box height is computed from.
     *
     * @param list<int> $shown
     * @return array{0: int, 1: int, 2: int} [columns, rows, provisional total height]
     */
    public static function prepass(array $shown, int $termWidth, ?int $forced, GpuRoster $roster): array
    {
        $boxes = \count($shown);
        if ($boxes === 0) {
            return [1, 0, 0];
        }
        $columns = self::columns($boxes, $termWidth, $forced);
        $rows = intdiv($boxes + $columns - 1, $columns);
        $tallest = 0;
        foreach ($shown as $gpu) {
            $tallest = max($tallest, 4 + $roster->offset($gpu));
        }

        return [$columns, $rows, $rows * $tallest];
    }

    /**
     * Place the gpu boxes (btop calcSizes' gpu block with PR #1881's grid).
     *
     * @param list<string> $names   shown gpu box names, slot order
     * @param list<int>    $slots   the slot of each
     * @param int          $cpuH    the cpu box height (0 when hidden)
     * @param bool         $others  mem, net or proc shown
     * @return array{0: array<string, GpuBox>, 1: int} [boxes keyed by name, total height]
     */
    public static function place(
        array $names,
        array $slots,
        int $termWidth,
        int $termHeight,
        ?int $forced,
        GpuRoster $roster,
        int $cpuH,
        bool $cpuShown,
        bool $cpuBottom,
        bool $others,
    ): array {
        $shown = \count($names);
        if ($shown === 0) {
            return [[], 0];
        }
        $columns = self::columns($shown, $termWidth, $forced);
        $rows = intdiv($shown + $columns - 1, $columns);
        $boxW = max(self::BOX_MIN_WIDTH, intdiv($termWidth - ($columns - 1), $columns));
        $detail = GpuDetail::forWidth($boxW);
        $panelW = $detail->panelWidth($boxW);
        $targets = array_map(static fn (string $n): int => (int) GpuPanels::index($n), $names);

        $rowNeed = 0;
        foreach ($targets as $gpu) {
            $rowNeed = max($rowNeed, $detail->height($roster->offset($gpu)));
        }
        if ($cpuShown) {
            $boxH = $others ? $cpuH : self::MIN_HEIGHT;
        } elseif ($others) {
            $boxH = max(self::MIN_HEIGHT, (int) ceil($termHeight * self::HEIGHT_PERCENT / $rows / 100));
        } else {
            $boxH = max(self::MIN_HEIGHT, intdiv($termHeight, max(1, $rows)));
        }
        $boxH = min($boxH, max(self::MIN_HEIGHT, $rowNeed));
        $top = (!$cpuBottom && $cpuShown) ? $cpuH : 0;
        $multi = $roster->count() > 1;

        $out = [];
        foreach ($names as $i => $name) {
            $gpu = $targets[$i];
            $col = $i % $columns;
            $row = intdiv($i, $columns);
            $statsH = min($boxH - 2, max(2, $detail->height($roster->offset($gpu)) - 2));
            $x = $col * ($boxW + 1);
            $y = $top + $row * $boxH;
            $statsX = $x + $boxW - $panelW - 1;
            $statsY = $y + (int) ceil(($boxH - 2 - $statsH) / 2) + 1;

            $selector = null;
            $label = Width::string($roster->label($gpu));
            $selW = 6 + $label;
            if ($multi && $boxW > $selW + 10) {
                $at = $x + $boxW - $selW - 2;
                $selector = [$at + 1, $at + $label + 4];
            }
            $out[$name] = new GpuBox(
                $name,
                $gpu,
                $i,
                $slots[$i] ?? $i,
                Rect::new($x, $y, $boxW, $boxH),
                Rect::new($statsX, $statsY, $panelW, $statsH),
                $detail,
                $boxH - 2,
                $selector,
                $roster->label($gpu),
                $roster->isNpu($gpu),
                $roster->name($gpu),
            );
        }

        return [$out, $rows * $boxH];
    }
}
