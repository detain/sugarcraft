<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ctr;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\Cgroup;
use SugarCraft\Top\Collect\ContainerInfo;
use SugarCraft\Top\Collect\ContainerSnapshot;
use SugarCraft\Top\Collect\Vm;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Proc\ProcUnits;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paint of btop PR #1873's containers box — `Ctr::draw` (btop_draw.cpp),
 * line for line. Region coordinates are btop's `Mv::to(y + r, x + c)`
 * offsets: (c, r) with (0, 0) the box's top-left border cell.
 *
 *  - title row: `[ select ]` right-aligned (the `ˣctr` title is frame
 *    chrome, {@see FrameBuilder});
 *  - row 1: the bold column labels; rows 2..: one container per row —
 *    name, engine (when the list is >= 52 wide), process count, memory,
 *    a 5x1 cpu mini-graph on the graph_bg underlay, cpu%;
 *  - with a container selected and the box >= 80 wide, a detail panel
 *    on the right (2/5 of the width, at least 30): name + engine (+ the
 *    vCPU count for a KVM guest) + cpu%,
 *    the cpu history graph, and a `used` memory meter against memory.max
 *    (a VM's configured memory, else MemTotal, when unlimited);
 *  - bottom border: `selected/count` (0 = none), and — beyond btop — the
 *    `proc filter: kvm:<guest>` label when the VM dashboard picked a guest
 *    this box does not list (ctr_show_vms off).
 *
 * Rows are written as btop's escape stream through {@see Region::ansi()},
 * so the colour law (selected bg/fg, proc_colors gradients, the bold
 * inactive_fg cpu text without proc_colors) folds exactly as a terminal
 * would; only the graph glyphs are placed cell by cell, because btop
 * draws them over the underlay with spaces left transparent.
 */
final class CtrView
{
    /** btop: the detail panel needs a box at least this wide. */
    public const DETAIL_MIN_WIDTH = 80;

    /** btop: the engine column shows when the list is at least this wide. */
    public const ENGINE_MIN_LIST = 52;

    public const ENGINE_SIZE = 9;

    private function __construct()
    {
    }

    /** btop `select_max = height - 3`. */
    public static function selectMax(int $height): int
    {
        return $height - 3;
    }

    /**
     * Ctr::draw's column geometry for a box `$width` wide.
     *
     * @return array{detail: bool, dWidth: int, dx: int, list: int, engine: int, name: int}
     */
    public static function geometry(int $width, bool $selected): array
    {
        $detail = $selected && $width >= self::DETAIL_MIN_WIDTH;
        $dWidth = $detail ? max(30, intdiv($width * 2, 5)) : 0;
        $list = $width - 2 - $dWidth;
        $engine = $list >= self::ENGINE_MIN_LIST ? self::ENGINE_SIZE : 0;

        return [
            'detail' => $detail,
            'dWidth' => $dWidth,
            'dx' => $width - $dWidth - 1,
            'list' => $list,
            'engine' => $engine,
            'name' => $list - 25 - ($engine > 0 ? $engine + 1 : 0),
        ];
    }

    /**
     * btop's "keep selected container in view": `start = clamp(start,
     * sel - select_max + 1, sel)` while one is selected, then `clamp(start,
     * 0, max(0, n - select_max))` (libstdc++ clamp semantics).
     */
    public static function scroll(int $start, int $selected, int $count, int $selectMax): int
    {
        if ($selected >= 0) {
            $start = FrameBuilder::clamp($start, $selected - $selectMax + 1, $selected);
        }

        return FrameBuilder::clamp($start, 0, max(0, $count - $selectMax));
    }

    /**
     * btop's mouse_mappings for this box (0-based absolute [x, y, w, h]):
     * `[` / `]` on the two halves of the `[ select ]` title, `ctr_row<N>`
     * on every listed row (not the detail panel).
     *
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function buttons(Rect $box, ?ContainerSnapshot $snapshot, string $selected, int $start): array
    {
        [$at, $inner] = self::selectButton($box->width);
        $left = intdiv($inner, 2);
        $map = [
            '[' => [$box->x + $at + 1, $box->y, $left, 1],
            ']' => [$box->x + $at + 1 + $left, $box->y, $inner - $left, 1],
        ];
        if ($snapshot === null) {
            return $map;
        }
        $index = $selected === '' ? null : $snapshot->indexOf($selected);
        $geo = self::geometry($box->width, $index !== null);
        $start = self::scroll($start, $index ?? -1, $snapshot->count(), self::selectMax($box->height));
        $rows = min(max(0, self::selectMax($box->height)), max(0, $snapshot->count() - $start));
        for ($lc = 0; $lc < $rows; $lc++) {
            $map['ctr_row' . $lc] = [$box->x + 1, $box->y + 2 + $lc, max(0, $geo['list']), 1];
        }

        return $map;
    }

    /**
     * @param array<string, DualSampleGraph> $graphs
     */
    public static function paint(Region $r, PanelFrame $f, ?ContainerSnapshot $snapshot, array $graphs, int $start): void
    {
        $ink = $f->ink;
        $config = $f->config;
        $W = $r->width();
        $H = $r->height();
        $line = $ink->fg('proc_box');
        $title = $ink->fg('title');
        $hi = $ink->fg('hi_fg');
        $main = $ink->fg('main_fg');
        $inactive = $ink->fg('inactive_fg');
        [$open, $close] = $f->border->embedJunctions(false);
        [$openDown, $closeDown] = $f->border->embedJunctions(true);

        $containers = $snapshot?->containers ?? [];
        $count = \count($containers);
        $selectedPath = $config->string(Schema::CTR_SELECTED);
        $selected = $selectedPath === '' ? -1 : ($snapshot?->indexOf($selectedPath) ?? -1);
        $selectMax = self::selectMax($H);
        $geo = self::geometry($W, $selected >= 0);
        $start = self::scroll($start, $selected, $count, $selectMax);
        $memTotal = $snapshot !== null && $snapshot->memTotal > 0 ? $snapshot->memTotal : 0;
        $mega = $config->bool('base_10_sizes');

        // Container selector.
        [$at, $inner] = self::selectButton($W);
        $r->ansi($at, 0, $line . $open . Symbols::BOLD . $hi . '[' . $title . ' ' . Lang::t('ctr.select') . ' ' . $hi . ']' . Symbols::UNBOLD . $line . $close);

        // Labels for fields in list.
        $r->ansi(1, 1, $title . Symbols::BOLD
            . Just::left(Lang::t('ctr.col.container'), $geo['name']) . ' '
            . ($geo['engine'] > 0 ? Just::left(Lang::t('ctr.col.engine'), $geo['engine']) . ' ' : '')
            . Just::right(Lang::t('ctr.col.procs'), 6) . ' '
            . Just::right(Lang::t('ctr.col.mem'), 5) . ' '
            . Just::right(Lang::t('ctr.col.cpu'), 10) . ' ' . Symbols::UNBOLD);

        $family = ProcView::family($config);

        // Divider, cpu graph and memory of the selected container.
        if ($geo['detail']) {
            $c = $containers[$selected];
            $dx = $geo['dx'];
            $dw = $geo['dWidth'];
            $r->put($dx, 0, $f->border->seam(true, true, false, true), $line);
            $r->put($dx, $H - 1, $f->border->seam(true, true, true, false), $line);
            for ($i = 1; $i < $H - 1; $i++) {
                $r->put($dx, $i, $f->border->left, $ink->fg('div_line'));
            }
            $limit = $c->memLimit > 0 ? $c->memLimit : $memTotal;
            $r->ansi($dx + 1, 1, $title . Symbols::BOLD . self::detailTitle($c, $dw, $config->bool('proc_per_core'), $snapshot?->coreCount ?? 1) . Symbols::UNBOLD);
            if ($c->history !== []) {
                $graph = DualSampleGraph::new(max(1, $dw - 1), max(1, $H - 4), $family, false, true)->withData(...$c->history);
                foreach (TintedGraph::lines($graph, $ink, 'cpu') as $i => $gl) {
                    if (2 + $i < $H - 2) {
                        $r->ansi($dx + 1, 2 + $i, $gl);
                    }
                }
            }
            $pct = max(0, min(100, intdiv($c->mem * 100, max(1, $limit))));
            $col = $dx + 1;
            $col += $r->ansi($col, $H - 2, $title . Symbols::BOLD . Lang::t('ctr.mem') . ' ' . Symbols::UNBOLD);
            $col += $r->ansi($col, $H - 2, PositionMeter::render($ink, max(1, $dw - 18), $pct, 'used'));
            $r->ansi($col, $H - 2, ' ' . $main . Just::right(ProcUnits::human($c->mem, true, 0, false, $mega) . '/' . ProcUnits::human($limit, true, 0, false, $mega), 12));
        }

        // Iteration over containers.
        $bg = DualSampleGraph::SYMBOLS[$family . '_up'][6];
        $colors = $config->bool('proc_colors');
        $lc = 0;
        foreach (\array_slice($containers, $start) as $n => $c) {
            if ($lc >= $selectMax) {
                break;
            }
            self::row($r, $f, $c, 2 + $lc, $start + $n === $selected, $colors, $memTotal, $geo, $graphs[$c->path] ?? null, $bg, $mega);
            $lc++;
        }
        if ($snapshot !== null && $count === 0) {
            $r->put(1, 2, Lang::t('ctr.none'), $inactive);
        }

        // Current selection and number of containers.
        $location = ($selected + 1) . '/' . $count;
        $len = Width::string($location);
        $r->ansi($W - 3 - max(7, $len), $H - 1, $line . str_repeat(Symbols::H_LINE, max(0, 7 - $len)) . $openDown
            . $title . Symbols::BOLD . $location . Symbols::UNBOLD . $line . $closeDown);

        // A guest picked from the VM dashboard while VMs are not listed here
        // (ctr_show_vms off) still filters the proc box: say so beside the count.
        $vm = $selected < 0 && $selectedPath !== '' ? Vm::scope($selectedPath) : null;
        if ($vm !== null && $vm['path'] === $selectedPath) {
            $label = Lang::t('ctr.vm_pick', ['engine' => Vm::ENGINE, 'name' => Cgroup::safe($vm['name'])]);
            $at = $W - 3 - max(7, $len) - Width::string($label) - 3;
            if ($at >= 2) {
                $r->ansi($at, $H - 1, $line . $openDown . $hi . Symbols::BOLD . $label . Symbols::UNBOLD . $line . $closeDown);
            }
        }
    }

    /** A VM's boot vCPUs after its engine in the detail title (beyond btop, which lists no VMs). */
    public static function vcpus(ContainerInfo $c): string
    {
        return $c->vm !== null && $c->vm->vcpus > 0 ? ' ' . Lang::t('ctr.vcpus', ['n' => $c->vm->vcpus]) : '';
    }

    /**
     * A VM's cpu as a share of its own vCPUs: 100 % = every vCPU busy.
     * `cpu` is a share of the host (or of one core with proc_per_core), so
     * it is turned into cores first. Emulator threads and I/O run on top
     * of the vCPUs, so this can pass 100. Null for a container or a VM
     * whose vCPU count is unknown.
     */
    public static function guestShare(ContainerInfo $c, bool $perCore, int $cores): ?float
    {
        if ($c->vm === null || $c->vm->vcpus <= 0) {
            return null;
        }

        return ($perCore ? $c->cpu : $c->cpu * max(1, $cores)) / $c->vm->vcpus;
    }

    /**
     * The detail panel's title row, `$dw - 1` cells: btop's `name engine`
     * left and `Cpu N%` in the last 10. A VM (beyond btop) reads
     * `name kvm` + `Cpu N% · M% of K vCPU` — host share first, so it
     * matches the list column, then the share of the guest's own
     * allocation; when the panel is too narrow for that, the vCPU count
     * moves left and only the host share stays.
     */
    public static function detailTitle(ContainerInfo $c, int $dw, bool $perCore, int $cores): string
    {
        $host = Lang::t('ctr.cpu') . ' ' . self::cpuText($c->cpu) . '%';
        $guest = self::guestShare($c, $perCore, $cores);
        if ($guest !== null && $c->vm !== null) {
            $right = Lang::t('ctr.cpu_guest', ['host' => $host, 'guest' => self::cpuText($guest), 'n' => $c->vm->vcpus]);
            $left = $dw - 1 - Width::string($right);
            if ($left >= 8) {
                return Just::left($c->name . ' ' . $c->engine, $left) . $right;
            }
        }

        return Just::left($c->name . ' ' . $c->engine . self::vcpus($c), $dw - 11) . Just::right($host, 10);
    }

    /** btop: `{:.1f}`, or `{:.0f}` from 99.95 up. */
    public static function cpuText(float $cpu): string
    {
        return number_format($cpu, $cpu < 99.95 ? 1 : 0, '.', '');
    }

    /**
     * One list row as btop streams it.
     *
     * @param array{detail: bool, dWidth: int, dx: int, list: int, engine: int, name: int} $geo
     */
    private static function row(Region $r, PanelFrame $f, ContainerInfo $c, int $y, bool $selected, bool $colors, int $memTotal, array $geo, ?DualSampleGraph $graph, string $bg, bool $mega): void
    {
        $ink = $f->ink;
        $main = $ink->fg('main_fg');
        if ($selected) {
            $prefix = $ink->bg('selected_bg') . $ink->fg('selected_fg') . Symbols::BOLD;
            $cColor = $mColor = Symbols::BOLD;
            $end = Symbols::UNBOLD;
        } elseif ($colors) {
            $prefix = $main;
            $cColor = $ink->gradient('process', max(0, min(100, (int) round($c->cpu))));
            $mColor = $ink->gradient('process', $memTotal > 0 ? max(0, min(100, intdiv($c->mem * 100, $memTotal))) : 0);
            $end = $main . Symbols::UNBOLD;
        } else {
            $prefix = $main;
            $cColor = $mColor = Symbols::BOLD;
            $end = Symbols::UNBOLD;
        }

        $head = "\x1b[0m" . $prefix
            . Just::left($c->name, $geo['name']) . ' '
            . ($geo['engine'] > 0 ? Just::left($c->engine, $geo['engine']) . ' ' : '')
            . Just::right((string) $c->procs, 6) . ' '
            . $mColor . Just::right(ProcUnits::human($c->mem, true, 0, false, $mega), 5) . $end . ' '
            . ($selected ? '' : $ink->fg('inactive_fg')) . str_repeat($bg, 5);
        $col = 1 + $r->ansi(1, $y, $head);

        // The graph over its underlay: glyphs in c_color, spaces leave the underlay.
        $state = self::escapes($head) . $cColor;
        $gx = $col - 5;
        foreach (ProcView::graphCells($graph) as $i => $cell) {
            if ($cell !== null) {
                $r->ansi($gx + $i, $y, $state . $cell);
            }
        }
        $r->ansi($col, $y, $state . $end . ' ' . $cColor . Just::right(self::cpuText($c->cpu), 4) . ' ' . $end);
    }

    /** Only the escape sequences of `$stream` (the colour state it leaves behind, text dropped). */
    private static function escapes(string $stream): string
    {
        preg_match_all('/\x1b\[[0-9;:]*m/', $stream, $m);

        return implode('', $m[0]);
    }

    /**
     * Where the `[ select ]` title starts (its left junction) and the cells
     * between the junctions — btop `Mv::to(y, x + width - 13)` with the
     * 8-cell " select " label; a translated label keeps the right edge.
     *
     * @return array{0: int, 1: int} [column, inner width]
     */
    private static function selectButton(int $width): array
    {
        $inner = Width::string(Lang::t('ctr.select')) + 4;

        return [$width - 3 - $inner, $inner];
    }
}
