<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Vms;

use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\Proc\ProcUnits;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\Symbols;

/**
 * Paint of the VM dashboard box (candy-top's own; btop has no VM view):
 *
 *  - top border: the `ᵛvms` title, the guest count, and the
 *    `sort ‹ cpu ›` button right-aligned (its halves cycle the order
 *    back / forward, as `S` / `s`);
 *  - the card grid ({@see VmsGrid}, {@see VmCard}) — or "No virtual
 *    machines found" once a sample found none;
 *  - bottom border: the allocation summary (vCPUs given out against the
 *    host's cpus, configured RAM against MemTotal — overcommit at a
 *    glance), a note when the libvirt XML was unreadable, and the
 *    `first-last/count` range of the visible cards.
 *
 * Lines are drawn in the flat `mem_box` colour ({@see Outline}); BorderFlow sweeps the flow over them.
 */
final class VmsView
{
    private function __construct()
    {
    }

    /**
     * @param list<VmGuest> $ordered the guests in card order
     */
    public static function paint(Region $r, PanelFrame $f, ?VmFleetSnapshot $snapshot, array $ordered, History $history, string $selected, int $start): void
    {
        $ink = $f->ink;
        $border = $f->border;
        $W = $r->width();
        $H = $r->height();
        if ($W < 2 || $H < 2) {
            return;
        }
        $box = Rect::new(0, 0, $W, $H);
        $o = Outline::new($ink->fg(FrameBuilder::VMS_FAMILY . '_box'));
        $o->box($r, $box, $border);
        $count = \count($ordered);

        // Top border: title, count, sort button.
        $x = 2 + $o->embed($r, $box, 2, 0, FrameBuilder::vmsTitle($ink, $f->tty()), $border);
        if ($snapshot !== null) {
            $o->embed($r, $box, $x, 0, $ink->fg('title') . Lang::t('vms.count', ['n' => (string) $count]), $border);
        }
        $sort = $f->config->string('vms_sorting');
        [$at] = self::sortButton($W, $sort);
        if ($at > $x + 12) {
            $o->embed($r, $box, $at, 0, Symbols::BOLD . $ink->fg('title') . Lang::t('vms.sort') . ' ' . $ink->fg('hi_fg') . '‹' . $ink->fg('title') . ' ' . $sort . ' ' . $ink->fg('hi_fg') . '›', $border);
        }

        if ($snapshot !== null && $count === 0) {
            $text = Lang::t('vms.none');
            $r->put(max(1, intdiv($W - Width::string($text), 2)), max(1, intdiv($H - 1, 2)), $text, $ink->fg('inactive_fg'));

            return;
        }

        $grid = VmsGrid::for($W, $H, $count);
        $start = $grid->scroll($start, self::indexOf($ordered, $selected), $count);
        $first = $start * $grid->columns;
        $shown = \array_slice($ordered, $first, $grid->perPage());
        foreach ($shown as $slot => $guest) {
            VmCard::paint($r, $f, $o, $grid->card($slot), $guest, $history, $guest->path === $selected);
        }

        // Bottom border: allocation summary (+ limited note) left, range right.
        // Nothing drawn (a box too short for a card) is no `1-0/N` range.
        $range = $shown === [] ? '' : Lang::t('vms.range', ['from' => (string) ($first + 1), 'to' => (string) ($first + \count($shown)), 'n' => (string) $count]);
        $rangeW = Width::string($range);
        if ($range !== '') {
            $o->embed($r, $box, $W - 3 - $rangeW, $H - 1, $ink->fg('title') . Symbols::BOLD . $range, $border, true);
        }
        if ($snapshot === null) {
            return;
        }
        $mega = $f->config->bool('base_10_sizes');
        $alloc = Lang::t('vms.alloc', [
            'vcpus' => (string) $snapshot->vcpus(),
            'cores' => (string) $f->host->coreCount,
            'mem' => ProcUnits::human($snapshot->memory(), true, 0, false, $mega),
            'host' => $snapshot->hostMem > 0 ? ProcUnits::human($snapshot->hostMem, true, 0, false, $mega) : '?',
        ]);
        $room = $W - 6 - ($range === '' ? 0 : $rangeW + 3);
        $x = 2;
        if (Width::string($alloc) + 2 <= $room) {
            $x += $o->embed($r, $box, $x, $H - 1, $ink->fg('graph_text') . $alloc, $border, true);
        }
        $note = Lang::t('vms.limited');
        if ($snapshot->limited && $x - 2 + Width::string($note) + 2 <= $room) {
            $o->embed($r, $box, $x, $H - 1, $ink->fg('inactive_fg') . $note, $border, true);
        }
    }

    /**
     * The mouse map (0-based absolute [x, y, w, h]): `vms_sort_prev` /
     * `vms_sort_next` on the halves of the sort button, `vms_card<N>` on
     * every visible card — the painter's own geometry.
     *
     * @param list<VmGuest> $ordered
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function buttons(Rect $box, array $ordered, string $selected, int $start, string $sort): array
    {
        [$at, $inner] = self::sortButton($box->width, $sort);
        $left = intdiv($inner, 2);
        $map = [
            'vms_sort_prev' => [$box->x + $at + 1, $box->y, $left, 1],
            'vms_sort_next' => [$box->x + $at + 1 + $left, $box->y, $inner - $left, 1],
        ];
        $count = \count($ordered);
        $grid = VmsGrid::for($box->width, $box->height, $count);
        $start = $grid->scroll($start, self::indexOf($ordered, $selected), $count);
        $shown = min($grid->perPage(), max(0, $count - $start * $grid->columns));
        for ($slot = 0; $slot < $shown; $slot++) {
            $c = $grid->card($slot);
            $map['vms_card' . $slot] = [$box->x + $c->x, $box->y + $c->y, $c->width, $c->height];
        }

        return $map;
    }

    /** @param list<VmGuest> $ordered */
    public static function indexOf(array $ordered, string $path): int
    {
        if ($path === '') {
            return -1;
        }
        foreach ($ordered as $i => $guest) {
            if ($guest->path === $path) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * The sort button's left junction column and the cells between its
     * junctions: `sort ‹ <order> ›`, right-aligned like the ctr box's
     * `[ select ]`.
     *
     * @return array{0: int, 1: int}
     */
    private static function sortButton(int $width, string $sort): array
    {
        $inner = Width::string(Lang::t('vms.sort')) + 5 + Width::string($sort);

        return [$width - 3 - $inner, $inner];
    }
}
