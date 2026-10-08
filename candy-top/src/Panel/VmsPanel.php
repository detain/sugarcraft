<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\VmFleet;
use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Vms\VmCard;
use SugarCraft\Top\Panel\Vms\VmsGrid;
use SugarCraft\Top\Panel\Vms\VmsSort;
use SugarCraft\Top\Panel\Vms\VmsView;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeVms;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;
use SugarCraft\Top\View\VmsMode;

/**
 * The VM dashboard (box `vms`; candy-top's own — btop has no VM view):
 * every libvirt/KVM guest on the host as a card with cpu, RAM, disk and
 * network meters and history, plus PSI pressure.
 *
 * Layout: a takeover box ({@see VmsMode}) — `v`, the cpu title's `vms`
 * button, or `vms` in shown_boxes / a preset puts it below the cpu box
 * and hides mem, net, proc and ctr until it is toggled off again.
 *
 * Data: {@see VmFleet} (files only, no subprocess), sampled ONLY while the
 * box is shown — the App never collects a hidden box, and collect() also
 * says no. A sample after a gap longer than {@see VmFleet::withMaxWindow()}
 * (the box was hidden, the host suspended) restarts every history instead
 * of drawing the gap's average as one point. `--fake` uses
 * {@see FakeVms}.
 *
 * Input, while shown: arrows move the selection through the grid,
 * PgUp/PgDn a page, Home/End the ends; `s` / `S` cycle the card order
 * (the persisted `vms_sorting`); Enter (or a click on the selected card)
 * leaves the dashboard with the proc box filtered to that guest — it
 * writes shown_boxes without `vms` (proc and ctr shown) and
 * ctr_selected = the guest's scope, exactly what picking it in the ctr
 * box does. A click selects a card, the wheel moves the selection by a
 * row (scrolls when none), the halves of the `sort ‹ cpu ›` button step
 * the order.
 */
final class VmsPanel implements Panel, ClickCapture
{
    /** Keys the dashboard answers (btop names). */
    private const KEYS = ['up', 'down', 'left', 'right', 'page_up', 'page_down', 'home', 'end', 'enter', 's', 'S'];

    private function __construct(
        private readonly Source $source,
        private readonly ?VmFleetSnapshot $snapshot,
        private readonly History $history,
        private readonly string $selected,
        private readonly int $start,
    ) {
    }

    public static function new(Source $source): self
    {
        return new self($source, null, History::new(), '', 0);
    }

    /** The roster entry: the live fleet (an empty one on FreeBSD), or the fake one. */
    public static function standard(bool $fake = false, ?Platform $platform = null): self
    {
        if ($fake) {
            return self::new(FakeVms::new());
        }
        $platform ??= Platform::detect();

        return self::new(CollectorSource::of($platform->isFreeBsd() ? VmFleet::disabled() : VmFleet::new()));
    }

    public function box(): string
    {
        return VmsMode::BOX;
    }

    public function snapshot(): ?VmFleetSnapshot
    {
        return $this->snapshot;
    }

    public function history(): History
    {
        return $this->history;
    }

    /** The selected guest's scope path, '' for none. */
    public function selected(): string
    {
        return $this->selected;
    }

    /** The first visible grid row. */
    public function start(): int
    {
        return $this->start;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $config = $context->config;
        if (!VmsMode::active($config->shownBoxes())) {
            return null; // hidden: no reads at all
        }
        $source = $this->source;
        if ($source instanceof CollectorSource && ($fleet = $source->collector()) instanceof VmFleet) {
            $source = CollectorSource::of($fleet->withMaxWindow(self::maxWindowUs($config)));
        }

        return static function () use ($source): Msg {
            [$snapshot, $next] = $source->sample();

            return new SampledMsg(VmsMode::BOX, $snapshot, $next);
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        if (!$context->visible()) {
            return false;
        }
        $name = KeyName::key($key);

        return \in_array($name, ['s', 'S'], true) || (\in_array($name, self::KEYS, true) && ($this->snapshot?->count() ?? 0) > 0);
    }

    public function capturesClick(MouseMsg $msg, PanelContext $context): bool
    {
        return $context->visible() && $this->clicked($msg, $context) !== null;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg) {
            return new PanelResult($msg->box === VmsMode::BOX && $msg->snapshot instanceof VmFleetSnapshot ? $this->sampled($msg, $context) : $this);
        }
        if (!$context->visible()) {
            return new PanelResult($this);
        }
        if ($msg instanceof KeyMsg) {
            $name = KeyName::key($msg);

            return \in_array($name, self::KEYS, true) ? $this->key($name, $context) : new PanelResult($this);
        }
        if ($msg instanceof MouseMsg) {
            return $this->mouse($msg, $context);
        }

        return new PanelResult($this->settled($context));
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        VmsView::paint($region, $frame, $this->snapshot, $this->ordered($frame->config), $this->history, $this->selected, $this->start);
    }

    /**
     * The guests in card order (vms_sorting).
     *
     * @return list<VmGuest>
     */
    public function ordered(Config $config): array
    {
        return VmsSort::sorted($this->snapshot?->guests ?? [], $config->string('vms_sorting'));
    }

    /**
     * The mapped zone under a bare left click: `vms_sort_prev`,
     * `vms_sort_next` or `vms_card<N>`.
     */
    public function clicked(MouseMsg $msg, PanelContext $context): ?string
    {
        $box = $context->box;
        if ($box === null || KeyName::of($msg) !== 'mouse_click') {
            return null;
        }
        $hit = KeyName::mapped($msg, VmsView::buttons($box, $this->ordered($context->config), $this->selected, $this->start, $context->config->string('vms_sorting')));

        return str_starts_with($hit, 'vms_') ? $hit : null;
    }

    /**
     * What Enter on `$path` writes: shown_boxes without `vms` but with
     * proc and ctr (the gpu slots kept), then ctr_selected — the ctr box's
     * pick, which filters the proc box to the guest's processes.
     *
     * @return array<string, bool|int|string>
     */
    public static function openSet(Config $config, string $path): array
    {
        $boxes = VmsMode::leaving($config->shownBoxes(), ['proc', 'ctr']);
        try {
            $target = GpuPanels::withBoxes($config, $boxes, GpuPanels::slots($config));
        } catch (InvalidOptionValue) {
            return [];
        }

        return [
            'shown_boxes' => $target->string('shown_boxes'),
            GpuPanels::SLOTS_KEY => $target->string(GpuPanels::SLOTS_KEY),
            Schema::CTR_SELECTED => $path,
        ];
    }

    private function key(string $name, PanelContext $context): PanelResult
    {
        $config = $context->config;
        if ($name === 's' || $name === 'S') {
            $sort = VmsSort::cycled($config->string('vms_sorting'), $name === 's' ? 1 : -1);
            $after = new PanelContext($config->with('vms_sorting', $sort), $context->layout, $context->box);

            return new PanelResult($this->settled($after), null, ['vms_sorting' => $sort]);
        }
        $ordered = $this->ordered($config);
        $count = \count($ordered);
        if ($count === 0 || $context->box === null) {
            return new PanelResult($this);
        }
        if ($name === 'enter') {
            return $this->open($config);
        }
        $grid = VmsGrid::for($context->box->width, $context->box->height, $count);
        $index = VmsView::indexOf($ordered, $this->selected);
        if ($index < 0) {
            // Nothing selected yet: any move picks the first visible card.
            $index = min($count - 1, $grid->scroll($this->start, -1, $count) * $grid->columns);

            return new PanelResult($this->select($ordered[$index]->path, $context));
        }
        $cols = $grid->columns;
        $page = max(1, $grid->perPage());
        $last = $count - 1;
        $next = match ($name) {
            'left' => max(0, $index - 1),
            'right' => min($last, $index + 1),
            'up' => $index - $cols >= 0 ? $index - $cols : $index,
            'down' => $index + $cols <= $last ? $index + $cols : (intdiv($index, $cols) < intdiv($last, $cols) ? $last : $index),
            'page_up' => max(0, $index - $page),
            'page_down' => min($last, $index + $page),
            'home' => 0,
            'end' => $last,
            default => $index,
        };

        return new PanelResult($this->select($ordered[$next]->path, $context));
    }

    private function mouse(MouseMsg $msg, PanelContext $context): PanelResult
    {
        $name = KeyName::of($msg);
        if ($name === 'mouse_scroll_up' || $name === 'mouse_scroll_down') {
            if (!$context->hit($msg->x, $msg->y)) {
                return new PanelResult($this);
            }
            if ($this->selected !== '') {
                return $this->key($name === 'mouse_scroll_up' ? 'up' : 'down', $context);
            }
            $count = \count($this->snapshot?->guests ?? []);
            $box = $context->box;
            $grid = VmsGrid::for($box->width, $box->height, $count);
            $start = $grid->scroll($this->start + ($name === 'mouse_scroll_up' ? -1 : 1), -1, $count);

            return new PanelResult($this->mutate(start: $start));
        }
        $hit = $this->clicked($msg, $context);
        if ($hit === 'vms_sort_prev' || $hit === 'vms_sort_next') {
            return $this->key($hit === 'vms_sort_next' ? 's' : 'S', $context);
        }
        if ($hit === null) {
            return new PanelResult($this);
        }
        $ordered = $this->ordered($context->config);
        $box = $context->box;
        $grid = VmsGrid::for($box->width, $box->height, \count($ordered));
        $start = $grid->scroll($this->start, VmsView::indexOf($ordered, $this->selected), \count($ordered));
        $guest = $ordered[$start * $grid->columns + (int) substr($hit, 8)] ?? null;
        if ($guest === null) {
            return new PanelResult($this);
        }
        // A click on the selected card opens it, as a click on the selected proc row does.
        return $guest->path === $this->selected ? $this->open($context->config) : new PanelResult($this->select($guest->path, $context));
    }

    private function open(Config $config): PanelResult
    {
        if ($this->selected === '' || $this->snapshot?->find($this->selected) === null) {
            return new PanelResult($this);
        }

        return new PanelResult($this, null, self::openSet($config, $this->selected));
    }

    private function select(string $path, PanelContext $context): self
    {
        return $this->mutate(selected: $path)->settled($context);
    }

    private function sampled(SampledMsg $msg, PanelContext $context): self
    {
        $snapshot = $msg->snapshot;
        \assert($snapshot instanceof VmFleetSnapshot);
        // A sample after a long gap (box hidden, host suspended) restarts the histories.
        $history = $snapshot->baseline ? History::new() : $this->history;
        $cap = max(64, ($context->layout?->width ?? 0));
        $keys = [];
        foreach ($snapshot->guests as $g) {
            foreach (['cpu' => $g->cpu, 'dr' => $g->diskRead, 'dw' => $g->diskWrite, 'nd' => $g->netRx, 'nu' => $g->netTx] as $series => $value) {
                $key = VmCard::key($g->path, $series);
                $keys[] = $key;
                $history = $history->push($key, $series === 'cpu' && $value >= 0 ? min(100.0, $value) : $value, $cap);
            }
        }
        $selected = $this->selected !== '' && $snapshot->find($this->selected) === null ? '' : $this->selected;

        return (new self($msg->next, $snapshot, $history->only($keys), $selected, $this->start))->settled($context);
    }

    /** The scroll law against the current box, order and selection. */
    private function settled(PanelContext $context): self
    {
        if ($context->box === null || $this->snapshot === null) {
            return $this;
        }
        $ordered = $this->ordered($context->config);
        $grid = VmsGrid::for($context->box->width, $context->box->height, \count($ordered));
        $start = $grid->scroll($this->start, VmsView::indexOf($ordered, $this->selected), \count($ordered));

        return $start === $this->start ? $this : $this->mutate(start: $start);
    }

    /** Three data ticks (at least 5 s): a longer window means the dashboard was not being watched. */
    private static function maxWindowUs(Config $config): int
    {
        return max(5_000_000, 3 * $config->updateMs() * 1000);
    }

    private function mutate(?string $selected = null, ?int $start = null): self
    {
        return new self($this->source, $this->snapshot, $this->history, $selected ?? $this->selected, $start ?? $this->start);
    }
}
