<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Vms;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Proc\ProcUnits;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * One guest's card on the VM dashboard:
 *
 *     ╭┐web01┌────────────┐4 vCPU · 4.0G┌╮
 *     │CPU ■■■■■■■■■■■■■■     37% ⣀⣠⣤⣶⣿⣶│
 *     │RAM ■■■■■■■■■■■■■■■■  2.5G/4.0G   │
 *     │DSK ▲  12K ⣀⣀⣠⣤⣀  ▼ 1.2M ⣀⣠⣤⣶⣤ │
 *     │NET ▼ 3.1M ⣀⣠⣴⣶⣤  ▲ 1.0M ⣀⣀⣠⣤⣀ │
 *     ╰──────────────────┐psi cpu 23%┌╯
 *
 * CPU is a share of the guest's OWN vCPUs (meter clamps at 100, the text
 * does not); RAM is host-resident against the configured size; DSK is
 * read ▲ / write ▼ and NET the guest's download ▼ / upload ▲, each rate
 * with its own mini history scaled to its window's peak (with a floor, so
 * an idle guest's trickle stays flat). Colours are the other boxes'
 * gradients: cpu, used, free / used (btop's disk io) and download /
 * upload. A guest whose worst PSI `some avg10` reaches
 * {@see PSI_WARN} % gets a badge on its bottom border in the temp
 * gradient — starved for cpu, memory or io, which no utilisation figure
 * shows. A taller card (fleet fits with room to spare) turns the cpu
 * mini graph into a full-width cpu history, every third extra row makes
 * the disk and net graphs a row taller, and from four spare rows a PSI
 * line shows all three pressures.
 */
final class VmCard
{
    /** PSI `some avg10` (%) from which the card shows the pressure badge. */
    public const PSI_WARN = 10.0;

    /** Disk graphs never scale below 1 MiB/s, net graphs below 128 KiB/s. */
    public const DISK_FLOOR = 1024 * 1024;

    public const NET_FLOOR = 128 * 1024;

    private function __construct()
    {
    }

    /** The History series names of `$path`: cpu, disk read / write, net down / up. */
    public static function key(string $path, string $series): string
    {
        return $path . "\0" . $series;
    }

    public static function paint(Region $r, PanelFrame $f, Outline $o, Rect $card, VmGuest $g, History $h, bool $selected): void
    {
        $ink = $f->ink;
        $border = $f->border;
        $hi = $selected ? $ink->fg('hi_fg') : null;
        $o->box($r, $card, $border, $hi);
        $r->fill(Rect::new($card->x + 1, $card->y + 1, max(0, $card->width - 2), max(0, $card->height - 2)));
        $mega = $f->config->bool('base_10_sizes');

        // Title: the domain name left, vCPUs · RAM right.
        $spec = Lang::t('vms.spec', [
            'n' => $g->vcpus > 0 ? (string) $g->vcpus : '?',
            'mem' => $g->memBytes > 0 ? ProcUnits::human($g->memBytes, true, 0, false, $mega) : '?',
        ]);
        $specW = Width::string($spec);
        $room = $card->width - 6 - ($specW + 3 <= $card->width - 12 ? $specW + 3 : 0);
        $name = Width::truncate($g->name, max(1, $room));
        $nameSgr = $selected ? $ink->bg('selected_bg') . $ink->fg('selected_fg') : $ink->fg('title');
        $o->embed($r, $card, $card->x + 2, $card->y, Symbols::BOLD . $nameSgr . $name, $border, false, $hi);
        if ($specW + 3 <= $card->width - 12) {
            $o->embed($r, $card, $card->right() - 3 - $specW, $card->y, $ink->fg('graph_text') . $spec, $border, false, $hi);
        }

        $cx = $card->x + 1;
        $cw = $card->width - 2;
        if ($cw < 8 || $card->height < 3) {
            return;
        }
        $family = $f->config->graphSymbolFor('proc');
        $family = \in_array($family, DualSampleGraph::FAMILIES, true) ? $family : DualSampleGraph::FAMILY_BRAILLE;
        // Extra height (the fleet fits with room to spare): a full-width cpu
        // history first, then taller disk / net graphs, a row each per three.
        $extra = max(0, $card->height - VmsGrid::CARD_HEIGHT);
        // From four spare rows the card spells out all three pressures.
        $psiRow = $extra >= 4 && $cw >= 24;
        $extra -= $psiRow ? 1 : 0;
        $ioRows = 1 + intdiv($extra, 3);
        $tall = $extra - 2 * ($ioRows - 1);
        $label = $ink->fg('title') . Symbols::BOLD;
        $main = "\x1b[0m" . $ink->fg('main_fg');
        // The right column holds the cpu % + mini graph and the RAM figures.
        $graphW = $tall > 0 ? 0 : max(5, intdiv($cw, 4));
        $rightW = $graphW > 0 ? max(10, 5 + $graphW) : 10;
        $meterW = max(1, $cw - 4 - 1 - $rightW);
        $y = $card->y + 1;

        // CPU: meter + % of the guest's own vCPUs + history.
        $cpu = $h->last(self::key($g->path, 'cpu'));
        $pct = $g->cpu >= 0 ? (int) round($g->cpu) : $cpu;
        $col = $cx + $r->ansi($cx, $y, $label . Lang::t('vms.cpu') . Symbols::UNBOLD . ' ');
        $col += $r->ansi($col, $y, PositionMeter::render($ink, $meterW, max(0, min(100, $pct ?? 0)), 'cpu'));
        $r->ansi($col, $y, $main . ' ' . Just::right($pct === null ? Lang::t('vms.na') : $pct . '%', $graphW > 0 ? 4 : $rightW - 1));
        if ($graphW > 0) {
            self::graph($r, $f, $cx + $cw - $graphW, $y, $graphW, 1, $h->series(self::key($g->path, 'cpu')), 100, 'cpu', $family);
        } elseif ($tall > 0) {
            self::graph($r, $f, $cx, $y + 1, $cw, $tall, $h->series(self::key($g->path, 'cpu')), 100, 'cpu', $family);
        }
        $y += 1 + $tall;

        // RAM: host-resident against the configured size.
        if ($y < $card->bottom() - 1) {
            $col = $cx + $r->ansi($cx, $y, $label . Lang::t('vms.ram') . Symbols::UNBOLD . ' ');
            $col += $r->ansi($col, $y, PositionMeter::render($ink, $meterW, $g->memPercent(), 'used'));
            $used = $g->memUsed >= 0 ? ProcUnits::human($g->memUsed, true, 0, false, $mega) : Lang::t('vms.na');
            $text = $g->memBytes > 0 ? $used . '/' . ProcUnits::human($g->memBytes, true, 0, false, $mega) : $used;
            $r->ansi($col, $y, $main . ' ' . Just::right($text, $rightW - 1));
            $y++;
        }

        // DSK: read ▲ / write ▼ (btop's disk io arrows and gradients).
        if ($y < $card->bottom() - 1) {
            self::rates($r, $f, $cx, $y, $cw, $ioRows, Lang::t('vms.disk'), $g->path, ['dr', '▲', 'free', $g->diskRead], ['dw', '▼', 'used', $g->diskWrite], $h, self::DISK_FLOOR, $family, true);
            $y += $ioRows;
        }
        // NET: the guest's download ▼ / upload ▲.
        if ($y < $card->bottom() - 1) {
            self::rates($r, $f, $cx, $y, $cw, $ioRows, Lang::t('vms.net'), $g->path, ['nd', '▼', 'download', $g->netRx], ['nu', '▲', 'upload', $g->netTx], $h, self::NET_FLOOR, $family, $g->netRx >= 0 || $h->has(self::key($g->path, 'nd')));
        }

        if ($psiRow) {
            self::pressures($r, $f, $cx, $card->bottom() - 2, $g);
        }

        // PSI: a badge on the bottom border when the guest is starved.
        $worst = $g->pressure();
        if ($worst !== null && $worst[1] >= self::PSI_WARN) {
            $text = Lang::t('vms.psi', ['res' => Lang::t('vms.psi.' . $worst[0]), 'pct' => (string) (int) round($worst[1])]);
            $len = Width::string($text);
            if ($len + 4 <= $card->width - 2) {
                $warn = $ink->gradient('temp', (int) max(0, min(100, round($worst[1] * 2))));
                $o->embed($r, $card, $card->right() - 3 - $len, $card->bottom() - 1, Symbols::BOLD . $warn . $text, $border, true, $hi);
            }
        }
    }

    /**
     * The PSI line of a tall card: `PSI cpu 1% mem 0% io 34%`, each `some
     * avg10` in the temp gradient (full at 50 %), n/a when unreadable.
     */
    private static function pressures(Region $r, PanelFrame $f, int $x, int $y, VmGuest $g): void
    {
        $ink = $f->ink;
        $col = $x + $r->ansi($x, $y, $ink->fg('title') . Symbols::BOLD . Lang::t('vms.psi.label') . Symbols::UNBOLD . ' ');
        foreach (['cpu' => $g->psiCpu, 'mem' => $g->psiMem, 'io' => $g->psiIo] as $res => $value) {
            $text = $value < 0 ? Lang::t('vms.na') : (int) round($value) . '%';
            $sgr = $value < 0 ? $ink->fg('inactive_fg') : $ink->gradient('temp', (int) max(0, min(100, round($value * 2))));
            $col += $r->ansi($col, $y, $ink->fg('graph_text') . Lang::t('vms.psi.' . $res) . ' ' . $sgr . Just::right($text, 4) . '  ');
        }
    }

    /**
     * A two-rate row: `LBL ▲ rate graph  ▼ rate graph`.
     *
     * @param array{0: string, 1: string, 2: string, 3: float} $a series, arrow, gradient, current B/s
     * @param array{0: string, 1: string, 2: string, 3: float} $b
     */
    private static function rates(Region $r, PanelFrame $f, int $x, int $y, int $w, int $rows, string $label, string $path, array $a, array $b, History $h, int $floor, string $family, bool $known): void
    {
        $ink = $f->ink;
        $col = $x + $r->ansi($x, $y, $ink->fg('title') . Symbols::BOLD . $label . Symbols::UNBOLD . ' ');
        if (!$known) {
            $r->put($col, $y, Lang::t('vms.na'), $ink->fg('inactive_fg'));

            return;
        }
        $half = intdiv($w - 4, 2);
        $mega = $f->config->bool('base_10_sizes');
        foreach ([$a, $b] as $i => [$series, $arrow, $gradient, $now]) {
            $at = $col + $i * $half;
            $data = $h->series(self::key($path, $series));
            $value = $now >= 0 ? $now : ($h->last(self::key($path, $series)) ?? 0);
            $text = $ink->gradient($gradient, 60) . $arrow . "\x1b[0m" . $ink->fg('main_fg') . Just::right(ProcUnits::human($value, true, 0, false, $mega), 5);
            $used = $r->ansi($at, $y, $text);
            $gw = $half - $used - 2;
            if ($gw >= 2) {
                self::graph($r, $f, $at + $used + 1, $y, $gw, $rows, $data, max($floor, ...array_slice([0, ...$data], -($gw * 2 + 1))), $gradient, $family);
            }
        }
    }

    /**
     * A history graph in a theme gradient, on btop's graph_bg underlay when
     * one row tall; nothing before the first sample.
     *
     * @param list<int> $data
     */
    private static function graph(Region $r, PanelFrame $f, int $x, int $y, int $w, int $height, array $data, int $max, string $gradient, string $family): void
    {
        if ($w < 1 || $height < 1 || $data === []) {
            return;
        }
        $tail = array_map(static fn (int $v): int => max(0, min($max, $v)), \array_slice($data, -($w * 2 + 1)));
        $graph = DualSampleGraph::new($w, $height, $family, false, false, $max === 100 ? 0 : $max)->withData(...$tail);
        foreach (TintedGraph::lines($graph, $f->ink, $gradient, $height === 1) as $i => $line) {
            $r->ansi($x, $y + $i, $line);
        }
    }
}
