<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\DistanceFade;
use SugarCraft\Dash\Plot\ProcRow\ProcGraphTracker;
use SugarCraft\Dash\Plot\ProcRow\ProcRowComposer;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paints the proc box: title-row buttons, column labels, the process rows,
 * the scrollbar, the select/info buttons and the `n/N` location — btop's
 * Proc::draw (src/btop_draw.cpp:1707-2240) line by line, with btop's
 * absolute `Mv::to(row, x + n)` offsets as Region-local columns (the box's
 * left border is column 0, so btop's `x + n` is local `n`).
 *
 * Rows are composed from the sugar-dash L6 primitives — {@see DistanceFade}
 * for the proc_gradient fade and the proc_colors metric blend,
 * {@see ProcGraphTracker} graphs for the 5×1 cpu sparklines on their
 * graph_bg underlay, {@see ProcRowComposer}'s btop label formatters —
 * rather than {@see ProcRowComposer::row()} itself, because the proc box
 * also draws tree rows and #1823's IO/R IO/W columns, which that
 * normal-view composite has no slot for; painting cells straight into the
 * Region also keeps btop's fixed-column jumps exact.
 *
 * {@see buttons()} is the per-frame mouse map (btop `Input::mouse_mappings`
 * entries the proc box registers), computed from the same geometry the
 * painter uses so a click lands on what is drawn.
 *
 * Deviations: the pause / follow / terminate / kill / signals / nice
 * buttons are not drawn — their keys belong to later phases (P-F's
 * overlay seam owns the signal popups) and a button must never advertise
 * a key that does nothing; the paused/following banner is likewise absent.
 */
final class ProcView
{
    private const BOLD = Symbols::BOLD;

    private function __construct()
    {
    }

    /**
     * btop's field sizes for a proc box `$width` cells wide (borders
     * included), with #1823's io_size (14 when width >= 90).
     *
     * Deviation from the #1823 diff: it takes `io_size + 1` (15) cells from
     * cmd/tree but draws only 14 (`rjust(6) + ' ' + rjust(6) + ' '`, the
     * user column already carrying its own trailing space), which leaves
     * a dead cell and pulls Cpu% one column left of where it sits without
     * the IO columns. Taking exactly the 14 drawn cells keeps Cpu% at the
     * same right-edge position in both layouts.
     *
     * @return array{user: int, thread: int, prog: int, cmd: int, tree: int, io: int}
     */
    public static function sizes(int $width, bool $graphs): array
    {
        $user = $width < 75 ? 5 : 10;
        $thread = $width < 75 ? -1 : 4;
        $io = $width < 90 ? -1 : 14;
        $ioUsed = $io > 0 ? $io : 0;
        $prog = $width > 70 ? 16 : ($width > 55 ? 8 : $width - $user - $thread - $ioUsed - 33);
        $cmd = $width > 55 ? $width - $prog - $user - $thread - $ioUsed - 33 : -1;
        $tree = $width - $user - $thread - $ioUsed - 23;
        if (!$graphs) {
            $cmd += 5;
            $tree += 5;
        }

        return ['user' => $user, 'thread' => $thread, 'prog' => $prog, 'cmd' => $cmd, 'tree' => $tree, 'io' => $io];
    }

    /**
     * Whether a box this size can show the detailed view above a usable
     * list (8 detail rows + borders, header and three rows; the label grid
     * needs 30 columns). Smaller boxes paint the list alone.
     */
    public static function detailFits(int $width, int $height): bool
    {
        return $height >= 14 && $width >= 30;
    }

    /** Rows of the detailed view above the list (btop: y + 8 / height - 8). */
    public static function detailRows(bool $shown): int
    {
        return $shown ? 8 : 0;
    }

    /**
     * The sort label shown between the arrows; a Lang key per btop sort.
     */
    public static function sortLabel(string $sorting): string
    {
        return Lang::t('proc.sort.' . str_replace(' ', '_', $sorting));
    }

    /**
     * The proc box's clickable spots this frame, local to the box:
     * key => [x, y, width] (btop Input::mouse_mappings). Keys are btop's
     * own: f, delete, O, c, r, e, left, right, info_enter, enter.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function buttons(int $width, int $height, Config $config, bool $filtering, int $selected, ?DetailState $detail, ?int $selectedPid): array
    {
        if ($detail !== null && !self::detailFits($width, $height)) {
            $detail = null;
        }
        $dy = self::detailRows($detail !== null);
        $map = [];
        $filterText = $config->string('proc_filter');
        $showOmit = $width > 72 + self::sortLen($config);
        if (!$filtering) {
            $shown = Width::truncate($filterText, max(6, $width - ($showOmit ? 78 : 66)));
            $fLen = $shown === '' ? Width::string(Lang::t('proc.filter')) : Width::string($shown) + 2;
            $map['f'] = [10, $dy, $fLen];
            if ($shown !== '') {
                $map['delete'] = [11 + $fLen, $dy, 3];
            }
        }
        $sortLen = self::sortLen($config);
        $sortPos = $width - $sortLen - 8;
        if ($showOmit) {
            $map['O'] = [$sortPos - 41, $dy, Width::string(Lang::t('proc.omit_ctr'))];
        }
        if ($width > 55 + $sortLen) {
            $map['c'] = [$sortPos - 24, $dy, Width::string(Lang::t('proc.per_core'))];
        }
        if ($width > 45 + $sortLen) {
            $map['r'] = [$sortPos - 14, $dy, Width::string(Lang::t('proc.reverse'))];
        }
        if ($width > 35 + $sortLen) {
            $map['e'] = [$sortPos - 5, $dy, Width::string(Lang::t('proc.tree'))];
        }
        $map['left'] = [$sortPos + 1, $dy, 2];
        $map['right'] = [$sortPos + $sortLen + 3, $dy, 2];
        if ($selected > 0) {
            $map['info_enter'] = [14, $height - 1, Width::string(Lang::t('proc.info')) + 2];
        }
        if ($detail !== null && !($selectedPid !== $detail->pid && $selected > 0)) {
            $map['enter'] = [$width - 9, 0, Width::string(Lang::t('proc.hide')) + 2];
        }

        return $map;
    }

    /**
     * @param list<ProcEntry> $rows the visible rows (filtered, sorted, tree-shaped)
     */
    public static function paint(
        Region $region,
        PanelFrame $frame,
        array $rows,
        ProcSelection $sel,
        ?FilterEdit $edit,
        ProcGraphTracker $graphs,
        ?DetailState $detail,
        int $memTotal,
        int $cores,
    ): void {
        $W = $region->width();
        $H = $region->height();
        if ($W < 4 || $H < 4) {
            return;
        }
        $config = $frame->config;
        $ink = $frame->ink;
        if ($detail !== null && !self::detailFits($W, $H)) {
            $detail = null; // no room: the list alone (ProcPanel's selection math agrees via detailFits)
        }
        $dy = self::detailRows($detail !== null);
        $listH = $H - $dy;
        $selectMax = max(0, $listH - 3);
        $numpids = count($rows);
        $sel = $sel->clamp($numpids, $selectMax);
        $graphsOn = $config->bool('proc_cpu_graphs');
        $sz = self::sizes($W, $graphsOn);
        $tree = $config->bool('proc_tree');

        if ($detail !== null) {
            self::paintDetail($region, $frame, $detail, $sel, $rows, $memTotal, $cores);
        }
        self::paintTitleRow($region, $frame, $dy, $edit);
        self::paintBottomRow($region, $frame, $sel, $numpids, $selectMax);
        self::paintHeader($region, $ink, $dy, $sz, $tree, $graphsOn, $config->bool('proc_mem_bytes'));

        $ctx = [
            'gradient' => $config->bool('proc_gradient') && !$config->bool('lowcolor') && $ink->palette()->hasGradient('proc'),
            'colors' => $config->bool('proc_colors'),
            'memBytes' => $config->bool('proc_mem_bytes'),
            'mega' => $config->bool('base_10_sizes'),
            'basename' => $config->bool('proc_command_basename'),
            'graphs' => $graphsOn,
            'family' => self::family($config),
            'memTotal' => $memTotal,
        ];
        $visible = array_slice($rows, $sel->start, max(0, $selectMax));
        foreach ($visible as $lc => $entry) {
            self::paintRow($region, $ink, $dy + 2 + $lc, $W, $sz, $tree, $entry, $lc, $sel->selected, max(1, $selectMax), $graphs, $ctx);
        }

        if ($numpids > $selectMax && $selectMax > 0) {
            $thumb = ProcSelection::thumb($sel->start, $numpids, $selectMax, $listH);
            $s = $ink->fg('main_fg') . self::BOLD;
            $region->put($W - 2, $dy + 1, '↑', $s);
            $region->put($W - 2, $dy + $listH - 2, '↓', $s);
            for ($y = $dy + 2; $y < $dy + $listH - 2; $y++) {
                $region->put($W - 2, $y, $y === $dy + 2 + $thumb ? '█' : ' ', $s);
            }
        }
    }

    /** The graph family for proc mini-graphs (graph_symbol_proc, tty → tty). */
    public static function family(Config $config): string
    {
        $family = $config->graphSymbolFor('proc');

        return in_array($family, DualSampleGraph::FAMILIES, true) ? $family : DualSampleGraph::FAMILY_BRAILLE;
    }

    private static function sortLen(Config $config): int
    {
        return Width::string(self::sortLabel($config->procSorting()));
    }

    /** Filter, Omit ctr, per-core, reverse, tree and the sort selector (btop_draw.cpp:1899-1957, #1873). */
    private static function paintTitleRow(Region $r, PanelFrame $f, int $y, ?FilterEdit $edit): void
    {
        $ink = $f->ink;
        $config = $f->config;
        $W = $r->width();
        $line = $ink->fg('proc_box');
        $hi = $ink->fg('hi_fg');
        $title = $ink->fg('title');
        $sortLen = self::sortLen($config);
        $sortPos = $W - $sortLen - 8;
        $showOmit = $W > 72 + $sortLen;

        $filterSize = max(6, $W - ($showOmit ? 78 : 66));
        $filtering = $edit !== null;
        $text = $filtering ? $edit->view($filterSize) : Width::truncate($config->string('proc_filter'), $filterSize);
        $inner = ($text !== '' ? self::BOLD : '')
            . ($text !== '' ? $hi . 'f' . $title . ' ' . $text : FrameBuilder::hotkey(Lang::t('proc.filter'), 'f', $ink))
            . (!$filtering && $text !== '' ? $hi . ' ' . Lang::t('proc.del') : '')
            . ($filtering ? $hi . ' ↵' : '');
        self::embed($r, 9, $y, $inner, $line, $f->border, false);

        if ($showOmit) {
            self::embed($r, $sortPos - 42, $y, ($config->bool('proc_filter_containers') ? self::BOLD : '') . FrameBuilder::hotkey(Lang::t('proc.omit_ctr'), 'O', $ink), $line, $f->border, false);
        }
        if ($W > 55 + $sortLen) {
            self::embed($r, $sortPos - 25, $y, ($config->bool('proc_per_core') ? self::BOLD : '') . FrameBuilder::hotkey(Lang::t('proc.per_core'), 'c', $ink), $line, $f->border, false);
        }
        if ($W > 45 + $sortLen) {
            self::embed($r, $sortPos - 15, $y, ($config->bool('proc_reversed') ? self::BOLD : '') . FrameBuilder::hotkey(Lang::t('proc.reverse'), 'r', $ink), $line, $f->border, false);
        }
        if ($W > 35 + $sortLen) {
            self::embed($r, $sortPos - 6, $y, ($config->bool('proc_tree') ? self::BOLD : '') . self::lastHotkey(Lang::t('proc.tree'), 'e', $ink), $line, $f->border, false);
        }
        self::embed(
            $r,
            $sortPos,
            $y,
            self::BOLD . $hi . '← ' . $title . self::sortLabel($config->procSorting()) . ' ' . $hi . '→',
            $line,
            $f->border,
            false,
        );
    }

    /** select / info buttons and the location counter (btop_draw.cpp:1959-1985, 2190-2194). */
    private static function paintBottomRow(Region $r, PanelFrame $f, ProcSelection $sel, int $numpids, int $selectMax): void
    {
        $ink = $f->ink;
        $W = $r->width();
        $y = $r->height() - 1;
        $line = $ink->fg('proc_box');
        $inactive = $ink->fg('inactive_fg');
        $hiFg = $ink->fg('hi_fg');
        $title = $ink->fg('title');
        $selected = $sel->selected;
        $last = $numpids === 0 || ($sel->selected > 0 && $sel->start + $sel->selected >= $numpids);
        $up = ($selected !== 0 ? $hiFg : $inactive) . '↑';
        $down = ($last ? $inactive : $hiFg) . '↓';
        $tColor = $selected === 0 ? $inactive : $title;
        $hiColor = $selected === 0 ? $inactive : $hiFg;

        $x = 1;
        $x += self::embed($r, $x, $y, self::BOLD . $up . $title . ' ' . Lang::t('proc.select') . ' ' . $down, $line, $f->border, true);
        self::embed($r, $x, $y, self::BOLD . $tColor . Lang::t('proc.info') . ' ' . $hiColor . '↵', $line, $f->border, true);

        $location = ($sel->start + $sel->selected) . '/' . $numpids;
        $len = strlen($location);
        $at = $W - 3 - max(9, $len);
        $r->put($at, $y, str_repeat(Symbols::H_LINE, max(0, 9 - $len)), $line);
        self::embed($r, $at + max(0, 9 - $len), $y, self::BOLD . $title . $location, $line, $f->border, true);
    }

    /**
     * @param array{user: int, thread: int, prog: int, cmd: int, tree: int, io: int} $sz
     */
    private static function paintHeader(Region $r, Ink $ink, int $dy, array $sz, bool $tree, bool $graphs, bool $memBytes): void
    {
        $s = $ink->fg('title') . self::BOLD;
        $y = $dy + 1;
        $col = 1;
        if (!$tree) {
            $col += $r->put($col, $y, self::rjust(Lang::t('proc.col.pid'), 8) . ' ', $s);
            $col += $r->put($col, $y, self::ljust(Lang::t('proc.col.program'), $sz['prog']) . ' ', $s);
            $col += $r->put($col, $y, ($sz['cmd'] > 0 ? self::ljust(Lang::t('proc.col.command'), $sz['cmd']) : '') . ' ', $s);
        } else {
            $col += $r->put($col, $y, self::ljust(Lang::t('proc.col.tree'), $sz['tree']) . ' ', $s);
        }
        if ($sz['thread'] > 0) {
            $col -= 4;
            $col += $r->put($col, $y, Lang::t('proc.col.threads') . ' ', $s);
        }
        $col += $r->put($col, $y, self::ljust(Lang::t('proc.col.user'), $sz['user']) . ' ', $s);
        if ($sz['io'] > 0) {
            $col += $r->put($col, $y, self::rjust(Lang::t('proc.col.io_read'), 6) . ' ' . self::rjust(Lang::t('proc.col.io_write'), 6) . ' ', $s);
        }
        $col += $r->put($col, $y, self::rjust($memBytes ? Lang::t('proc.col.mem') : Lang::t('proc.col.mem_percent'), 5) . ' ', $s);
        $r->put($col, $y, self::rjust(Lang::t('proc.col.cpu'), $graphs ? 10 : 5), $s);
    }

    /**
     * One process row (btop_draw.cpp:2063-2172).
     *
     * @param array{user: int, thread: int, prog: int, cmd: int, tree: int, io: int} $sz
     * @param array<string, mixed> $ctx
     */
    private static function paintRow(
        Region $r,
        Ink $ink,
        int $y,
        int $W,
        array $sz,
        bool $tree,
        ProcEntry $e,
        int $lc,
        int $selected,
        int $selectMax,
        ProcGraphTracker $graphs,
        array $ctx,
    ): void {
        $p = $e->process;
        $memTotal = (int) $ctx['memTotal'];
        $memPct = $memTotal > 0 ? $e->mem * 100 / $memTotal : 0.0;
        $isSelected = $lc + 1 === $selected;
        $main = $ink->fg('main_fg');
        $inactive = $ink->fg('inactive_fg');

        if ($isSelected) {
            $hl = $ink->bg('selected_bg') . $ink->fg('selected_fg') . self::BOLD;
            $r->fill(Rect::new(1, $y, $W - 2, 1), $hl);
            $g = $c = $m = $t = $end = $hl;
            $underlay = $glyph = $cpuS = $hl;
        } else {
            $calc = DistanceFade::distance($selected, $lc);
            $g = $ctx['gradient'] ? $ink->gradient('proc', DistanceFade::fadeIndex($calc, $selectMax)) : $main;
            $underlay = $inactive;
            if ($ctx['colors']) {
                [$vc, $vm, $vt] = DistanceFade::metricValues($e->cpu, $memPct, $e->threads);
                [$c, $m, $t] = array_map(
                    static fn (int $v): string => self::metric($ink, $v, $calc, $selectMax, (bool) $ctx['gradient']),
                    [$vc, $vm, $vt],
                );
                $end = $main;
                $glyph = $cpuS = $c;
            } else {
                // btop's c_color = Fx::b over the row color; the inactive_fg
                // written ahead of the underlay is never reset, so graph
                // glyphs and cpu% come out bold inactive_fg.
                $c = $m = $t = $g . self::BOLD;
                $end = $g;
                $glyph = $cpuS = $inactive . self::BOLD;
            }
        }

        $cmd = self::cleanText($ctx['basename'] ? $p->cmdBasename() : $p->cmd);
        $tag = self::tag($e);
        $display = $tag !== '' ? $tag . ' ' . $cmd : $cmd;
        $name = self::cleanText($p->container?->isVm() ? $p->container->name : $p->name);

        if (!$tree) {
            $r->put(1, $y, self::rjust((string) $p->pid, 8) . ' ', $g);
            $r->put(10, $y, self::ljust($name, $sz['prog']), $c);
            $r->put(10 + $sz['prog'], $y, ' ', $end);
            if ($sz['cmd'] > 0) {
                $r->put(11 + $sz['prog'], $y, self::ljust($display, $sz['cmd']), $g);
                $r->put(11 + $sz['prog'] + $sz['cmd'], $y, ' ', $g);
                $col = 12 + $sz['prog'] + $sz['cmd'];
            } else {
                $col = 11 + $sz['prog'];
            }
        } else {
            $left = $sz['tree'];
            $prefixPid = $e->prefix . $p->pid;
            $x = 1;
            $x += $r->put($x, $y, Width::truncate($prefixPid, max(0, $left)) . ' ', $g);
            $left -= Width::string($prefixPid);
            if ($left > 0) {
                $x += $r->put($x, $y, Width::truncate($name, max(0, $left - 1)), $c);
                $x += $r->put($x, $y, ' ', $end);
                $left -= Width::string($name) + 1;
            }
            if ($left > 7) {
                $short = $left > 40 ? rtrim($display) : self::shortCmd($p->cmd, $tag);
                if ($short !== '' && $short !== $name) {
                    $x += $r->put($x, $y, '(' . Width::truncate($short, max(0, $left - 3)) . ') ', $g);
                    $left -= Width::string($short) + 3;
                }
            }
            $col = 2 + $sz['tree'];
        }

        if ($sz['thread'] > 0) {
            $col += $r->put($col, $y, self::rjust(ProcRowComposer::threadsLabel($e->threads), $sz['thread']), $t);
            $col += $r->put($col, $y, ' ', $end);
        }
        $col += $r->put($col, $y, self::ljust(ProcRowComposer::userLabel(self::cleanText($p->user), $sz['user']), $sz['user']) . ' ', $g);
        if ($sz['io'] > 0) {
            $col += $r->put($col, $y, self::rjust(self::ioLabel($e->ioRead, (bool) $ctx['mega']), 6) . ' ' . self::rjust(self::ioLabel($e->ioWrite, (bool) $ctx['mega']), 6) . ' ', $t);
        }
        $memLabel = $ctx['memBytes'] ? ProcUnits::human($e->mem, true, 0, false, (bool) $ctx['mega']) : ProcRowComposer::memLabel($memPct);
        $col += $r->put($col, $y, self::rjust($memLabel, 5), $m);
        $col += $r->put($col, $y, ' ', $end);
        if ($ctx['graphs']) {
            $cells = self::graphCells($graphs->graph($p->pid));
            $bg = DualSampleGraph::SYMBOLS[$graphs->family() . '_up'][6];
            foreach ($cells as $cell) {
                $col += $r->put($col, $y, $cell ?? $bg, $cell === null ? $underlay : $glyph);
            }
        }
        $col += $r->put($col, $y, ' ', $end);
        $col += $r->put($col, $y, self::rjust(ProcRowComposer::cpuLabel($e->cpu), 4), $cpuS);
        $r->put($col, $y, '  ', $end);
    }

    /** The detailed-view box (btop_draw.cpp:1830-1880, 2002-2036; #1546 cwd). */
    private static function paintDetail(Region $r, PanelFrame $f, DetailState $d, ProcSelection $sel, array $rows, int $memTotal, int $cores): void
    {
        $ink = $f->ink;
        $config = $f->config;
        $W = $r->width();
        $line = $ink->fg('proc_box');
        $title = $ink->fg('title');
        $hiFg = $ink->fg('hi_fg');
        $inactive = $ink->fg('inactive_fg');
        $main = $ink->fg('main_fg');
        $border = $f->border;
        $e = $d->entry;
        if ($e === null) {
            return;
        }
        $p = $e->process;
        $alive = $d->alive;
        $dgw = max(intdiv($W, 3), $W - 121);
        $dw = $W - $dgw - 1;
        $dx = $dgw + 1;

        // The list's own top border moves down 8 rows: ├─┐⁴proc┌───┤.
        $boxTitle = Lang::t('box.proc');
        $r->put(0, 8, $border->seam(false, true, true, true) . Symbols::H_LINE, $line);
        $at = 2 + self::embed($r, 2, 8, self::BOLD . $hiFg . Symbols::number(4, $config->ttyMode()) . $title . $boxTitle, $line, $border, false);
        $r->put($at, 8, str_repeat(Symbols::H_LINE, max(0, $W - 1 - $at)), $line);
        $r->put($W - 1, 8, $border->seam(true, false, true, true), $line);

        // Detail top border: repaint the run, then pid + name titles.
        $r->put(1, 0, str_repeat(Symbols::H_LINE, max(0, $W - 2)), $line);
        $pidStr = (string) $p->pid;
        $x = 2 + self::embed($r, 2, 0, self::BOLD . $title . $pidStr, $line, $border, false);
        self::embed($r, $x, 0, self::BOLD . $title . Width::truncate(self::cleanText($p->name), max(0, $dgw - strlen($pidStr) - 7)), $line, $border, false);
        $r->put($dgw, 0, $border->seam(true, true, false, true), $line);
        $r->put($dgw, 8, $border->seam(true, true, true, false), $line);
        for ($i = 1; $i < 8; $i++) {
            $r->put($dgw, $i, Symbols::V_LINE, $ink->fg('div_line'));
        }

        // hide ↵ (greyed while another row is selected).
        $selectedPid = $sel->index() !== null ? ($rows[$sel->index()] ?? null)?->pid() : null;
        $greyed = $selectedPid !== $d->pid && $sel->selected > 0;
        self::embed($r, $dx + $dw - 10, 0, self::BOLD . ($greyed ? $inactive : $title) . Lang::t('proc.hide') . ' ' . ($greyed ? '' : $hiFg) . '↵', $line, $border, false);

        // cpu graph (dgraph_width - 1 × 7) with the cpu% readout over its corner.
        $family = self::family($config);
        $gw = max(1, $dgw - 1);
        if ($d->cpu !== [] && ($alive || $config->bool('pause_proc_list'))) {
            $graph = DualSampleGraph::new($gw, 7, $family, false, true)->withGradient(self::ramp($ink, 'cpu'))->withData(...$d->cpu);
            foreach (explode("\n", $graph->render($ink->profile())) as $i => $gl) {
                $r->ansi(1, 1 + $i, $gl);
            }
        }
        $cpu = $config->bool('proc_per_core') ? $e->cpu : $e->cpu * max(1, $cores);
        if ($alive) {
            $prec = $cpu < 9.995 ? 2 : ($cpu < 99.95 ? 1 : 0);
            $r->put(1, 1, str_pad(number_format($cpu, $prec, '.', ''), 4, ' ', STR_PAD_LEFT) . '%', $title . self::BOLD);
        }
        foreach (['C', 'P', 'U'] as $i => $l) {
            $r->put(1, 3 + $i, $l, $title . self::BOLD);
        }

        // Labels and values.
        $fit = max(1, intdiv($dw - 2, 10));
        $iw = max(1, intdiv($dw - 2, min($fit, 8)));
        $labels = ['status', 'elapsed', 'io_read', 'io_write', 'parent', 'user', 'threads', 'nice'];
        $mega = $config->bool('base_10_sizes');
        $extra = $d->extra;
        $elapsed = $extra !== null && $extra->elapsed >= 0 ? \SugarCraft\Top\View\ClockFormat::dhms((int) $extra->elapsed) : '';
        if (strlen($elapsed) > 8) {
            $elapsed = substr($elapsed, 0, -3);
        }
        $values = [
            Lang::t('proc.status.' . $d->status()),
            $elapsed,
            $extra !== null && $extra->ioReadTotal >= 0 ? ProcUnits::human($extra->ioReadTotal, false, 0, false, $mega) : '-',
            $extra !== null && $extra->ioWriteTotal >= 0 ? ProcUnits::human($extra->ioWriteTotal, false, 0, false, $mega) : '-',
            self::cleanText($d->parent),
            self::cleanText($p->user),
            (string) $p->threads,
            (string) $p->nice,
        ];
        $lx = $dx + 1;
        $statusColor = !$alive ? $inactive : ($p->state === 'R' ? $ink->fg('proc_misc') : $main);
        for ($i = 0; $i < min($fit, 8) || $i < 2; $i++) {
            if ($i >= 8) {
                break;
            }
            $r->put($lx + $i * $iw, 1, self::cjust(Lang::t('proc.detail.' . $labels[$i]), $iw, true), $title . self::BOLD);
            $r->put($lx + $i * $iw, 2, self::cjust($values[$i], $iw, $i >= 4), $i === 0 ? $statusColor : $main);
        }

        // Memory: NN% [graph] 1.50 GiB
        $third = max(1, intdiv($dw, 3));
        $memPct = $memTotal > 0 && $d->mem !== [] ? $d->mem[array_key_last($d->mem)] * 100.0 / $memTotal : 0.0;
        $memStr = substr(sprintf('%.2f', $memPct), 0, 4);
        if (str_ends_with($memStr, '.')) {
            $memStr = substr($memStr, 0, -1);
        }
        $label = self::rjust(($fit > 4 ? Lang::t('proc.detail.memory') . ' ' : Lang::t('proc.detail.memory_short')) . self::rjust($memStr, 4) . '% ', max(0, $third - 2));
        $mx = $lx + $r->put($lx, 4, $label, $title . self::BOLD);
        $bg = DualSampleGraph::SYMBOLS[$family . '_up'][6];
        $cells = array_fill(0, $third, null);
        if ($d->mem !== [] && $d->firstMem > 0) {
            $mg = DualSampleGraph::new($third, 1, $family, false, false, $d->firstMem)->withData(...$d->mem);
            $cells = self::cells($mg, $third);
        }
        foreach ($cells as $cell) {
            $mx += $r->put($mx, 4, $cell ?? $bg, $cell === null ? $inactive : $ink->fg('proc_misc'));
        }
        $mx += $r->put($mx, 4, ' ', $main);
        $r->put($mx, 4, ProcUnits::human($e->mem, false, 0, false, $mega), $title . self::BOLD);

        // #1546: CWD then a 2-line CMD.
        $r->put($lx, 5, Lang::t('proc.detail.cwd'), $title . self::BOLD);
        $r->put($lx, 6, Lang::t('proc.detail.cmd'), $title . self::BOLD);
        $cw = max(1, $dw - 6);
        if ($extra === null || $extra->cwd === null || $extra->cwd === '') {
            $r->put($dx + 5, 5, Lang::t('proc.cwd_unavailable'), $inactive);
        } else {
            $r->put($dx + 5, 5, Width::truncate(self::cleanText($extra->cwd), $cw), $main);
        }
        $cmd = self::cleanText($p->cmd);
        $clusters = self::clusters($cmd);
        $n = count($clusters);
        $lines = min(2, (int) ceil($n / $cw));
        for ($i = 0; $i < $lines; $i++) {
            $part = implode('', array_slice($clusters, $cw * $i));
            $r->put($dx + 5, 6 + ($lines === 1 ? 0 : $i), self::cjust($part, $cw, true), $main);
        }
    }

    /** The #1873 / U1b tag: "[docker:3f2a1b9c0d1e]", "[kvm:web01]"; '' on the host. */
    public static function tag(ProcEntry $e): string
    {
        $c = $e->process->container;

        return $c === null ? '' : '[' . $c->engine . ':' . $c->name . ']';
    }

    private static function metric(Ink $ink, int $v, int $calc, int $selectMax, bool $gradient): string
    {
        if (!$gradient) {
            return $ink->gradient('process', max(0, min(100, $v)));
        }
        $pos = DistanceFade::metricPosition($v, $calc, $selectMax);

        return $pos < 100
            ? $ink->gradient('proc_color', max(0, $pos))
            : $ink->gradient('process', max(0, min(100, $pos - 100)));
    }

    /** IO/R, IO/W cell: "-" when unreadable (EACCES, never 0), "0B" idle, else "1.5M". */
    public static function ioLabel(float $rate, bool $mega = false): string
    {
        if ($rate < 0.0) {
            return '-';
        }

        return $rate > 0.0 ? ProcUnits::human((int) $rate, true, 0, false, $mega) : '0B';
    }

    /** btop short_cmd: the first token's basename (tree view, narrow rows). */
    private static function shortCmd(string $cmd, string $tag): string
    {
        $first = explode(' ', $cmd, 2)[0];
        $slash = strrpos($first, '/');
        $short = self::cleanText($slash === false ? $first : substr($first, $slash + 1));

        return $tag !== '' && $short !== '' ? $tag . ' ' . $short : $short;
    }

    /**
     * A one-row graph's 5 newest cells, null where it is transparent (the
     * graph_bg underlay shows) — ProcRowComposer's composite.
     *
     * @return list<?string>
     */
    private static function graphCells(?DualSampleGraph $graph): array
    {
        return $graph === null ? array_fill(0, 5, null) : self::cells($graph, 5);
    }

    /** @return list<?string> */
    private static function cells(DualSampleGraph $graph, int $n): array
    {
        $plain = $graph->withoutUnderlay()->withoutGradient()->render(ColorProfile::NoTty);
        $cells = array_map(static fn (string $g): ?string => $g === ' ' ? null : $g, mb_str_split($plain));
        $cells = array_slice($cells, -$n);

        return [...array_fill(0, $n - count($cells), null), ...$cells];
    }

    /** @return list<\SugarCraft\Core\Util\Color> */
    private static function ramp(Ink $ink, string $name): array
    {
        $out = [];
        for ($i = 0; $i <= 100; $i++) {
            $c = $ink->palette()->at($name, $i);
            if ($c === null) {
                return [\SugarCraft\Core\Util\Color::hex('#cccccc'), \SugarCraft\Core\Util\Color::hex('#cccccc')];
            }
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Junction + text + junction into a border row of the region, clipped
     * to the run between the corners (FrameBuilder's BoxChrome::embed for a
     * Region). Returns columns written.
     */
    private static function embed(Region $r, int $x, int $y, string $inner, string $line, Border $border, bool $bottom): int
    {
        $edge = $r->sub(Rect::new(1, $y, max(0, $r->width() - 2), 1));
        [$open, $close] = $border->embedJunctions($bottom);
        $col = $x - 1;
        $col += $edge->put($col, 0, $open, $line);
        $col += $edge->ansi($col, 0, $inner);
        $col += $edge->put($col, 0, $close, $line);

        return $col - ($x - 1);
    }

    /** btop's tree button lights the LAST `e` of "tree"; fall back to the front for a label without it. */
    private static function lastHotkey(string $label, string $key, Ink $ink): string
    {
        $at = mb_strripos($label, $key);
        if ($at === false) {
            return FrameBuilder::hotkey($label, $key, $ink);
        }
        $title = $ink->fg('title');

        return $title . mb_substr($label, 0, $at) . $ink->fg('hi_fg') . mb_substr($label, $at, mb_strlen($key)) . $title . mb_substr($label, $at + mb_strlen($key));
    }

    /** Control characters (C0, DEL, C1) become spaces so columns stay aligned. */
    public static function cleanText(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');

        return preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', ' ', $s) ?? '';
    }

    private static function ljust(string $s, int $w): string
    {
        return $w <= 0 ? '' : Width::padRight(Width::truncate($s, $w), $w);
    }

    private static function rjust(string $s, int $w): string
    {
        return Width::string($s) > $w ? self::ljust($s, $w) : Width::padLeft($s, $w);
    }

    /** btop cjust: centred, the odd cell on the left; `$limit` cuts an overlong value. */
    private static function cjust(string $s, int $w, bool $limit): string
    {
        $len = Width::string($s);
        if ($len > $w) {
            return $limit ? Width::truncate($s, $w) : $s;
        }

        return str_repeat(' ', (int) ceil(($w - $len) / 2)) . $s . str_repeat(' ', intdiv($w - $len, 2));
    }

    /** @return list<string> */
    private static function clusters(string $s): array
    {
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $g = Width::nextCluster($s, $i);
            if ($g === '') {
                break;
            }
            $out[] = $g;
            $i += strlen($g);
        }

        return $out;
    }
}
