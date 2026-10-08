<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Top\Collect\ContainerCollector;
use SugarCraft\Top\Collect\ContainerSnapshot;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Collect\TunableProcList;
use SugarCraft\Top\Collect\Vm;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\Schema;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Ctr\CtrSample;
use SugarCraft\Top\Panel\Ctr\CtrView;
use SugarCraft\Top\Panel\Proc\ProcGpuSample;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeContainers;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/**
 * btop PR #1873's containers box (`Ctr::draw`, `Ctr::select`,
 * `Ctr::select_row`, `Ctr::collect`).
 *
 * Data: btop collects the containers inside Proc::collect, from the same
 * process scan. Here the proc box owns that scan, so this panel TAPS it
 * ({@see SampleTap}): every proc sample is handed here too, and update()
 * answers with a Cmd that groups those processes and reads the cgroup v2
 * figures ({@see ContainerCollector}). Only while the proc box is hidden
 * does collect() run its own process scan, as btop runs Proc::collect for
 * a lone ctr box; opening this box beside proc makes the App sample proc
 * at once (btop's Runner::run("all") after a toggle). A sample covering
 * less than half of update_ms is skipped ({@see ContainerCollector::due()}).
 * Hidden, or with a disabled collector (FreeBSD), the box costs nothing:
 * no collect, no tap (#1858).
 *
 * Selection: the picked container's cgroup path is the runtime option
 * ctr_selected ({@see Schema::CTR_SELECTED}), written through
 * {@see PanelResult::$set} — the proc box reads it to show only that
 * container's processes and resets its own selection when it changes.
 * `[` / `]` step through "none, first .. last" with wrap (btop
 * Ctr::select), a row click toggles that row (Ctr::select_row), the
 * `[ select ]` title halves are the same two keys; a container that
 * vanishes drops the selection.
 *
 * VMs: libvirt/KVM guests are listed, sorted, selected and filtered
 * exactly like containers (engine "kvm"), unless ctr_show_vms is off —
 * re-applied to the collector from the current config on every sample.
 * With it off, a guest picked from the VM dashboard (Enter) still filters
 * the proc box: that pick survives the unlisted-container clearing and is
 * dropped by the proc tap once the guest has no process left.
 *
 * Mini-graphs: one 5x1 graph per drawn row, pushed once per fresh sample
 * with btop's value (`cpu < 5` but >= 0.1 reads 5, so a trickle shows)
 * and erased with its container.
 */
final class CtrPanel implements Panel, ClickCapture, SampleTap
{
    /** candy-top's option: list libvirt/KVM guests beside the containers (btop never does). */
    public const SHOW_VMS = 'ctr_show_vms';

    /** btop trims the cpu deque to Term::width; this before the first layout. */
    private const DEFAULT_HISTORY = 512;

    /**
     * @param array<string, DualSampleGraph> $graphs cgroup path => 5x1 mini-graph
     */
    private function __construct(
        private readonly Source $procs,
        private readonly ContainerCollector $collector,
        private readonly ?ContainerSnapshot $snapshot = null,
        private readonly array $graphs = [],
        private readonly string $family = '',
        private readonly int $start = 0,
    ) {
    }

    /**
     * @param Source             $procs     the panel's own process source (used while the proc box is hidden)
     * @param ContainerCollector $collector live {@see \SugarCraft\Top\Collect\Containers} or {@see FakeContainers}
     */
    public static function new(Source $procs, ContainerCollector $collector): self
    {
        // A disabled collector (FreeBSD) never samples: show the empty box at once.
        return new self($procs, $collector, $collector->enabled() ? null : new ContainerSnapshot([]));
    }

    /** The roster entry: the platform's ProcList + containers, or the fake fleet. */
    public static function standard(int $cores, bool $fake = false, ?Platform $platform = null): self
    {
        if ($fake) {
            return self::new(FakeProcList::demo($cores)->withContainers(), FakeContainers::new());
        }
        $platform ??= Platform::detect();

        return self::new($platform->procList(), $platform->containers());
    }

    public function box(): string
    {
        return 'ctr';
    }

    public function taps(): string
    {
        return 'proc';
    }

    public function opened(): self
    {
        return new self($this->procs, $this->collector->rebased(), $this->snapshot, $this->graphs, $this->family, $this->start);
    }

    public function snapshot(): ?ContainerSnapshot
    {
        return $this->snapshot;
    }

    public function start(): int
    {
        return $this->start;
    }

    public function graph(string $path): ?DualSampleGraph
    {
        return $this->graphs[$path] ?? null;
    }

    /** @return array<string, DualSampleGraph> */
    public function graphs(): array
    {
        return $this->graphs;
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $config = $context->config;
        $shown = $config->shownBoxes();
        // Hidden (#1858), never filled (FreeBSD), or fed by the proc box's
        // own scan through the tap — a second, cold ProcList here would
        // double the /proc cost and keep a second per-pid cache. Opening
        // this box beside proc makes the App sample proc at once instead.
        if (!\in_array('ctr', $shown, true) || !$this->collector->enabled() || \in_array('proc', $shown, true)) {
            return null;
        }
        $perCore = $config->bool('proc_per_core');
        $procs = self::tuned($this->procs, $config);
        $collector = $this->collector->withVms($config->bool(self::SHOW_VMS));
        $cap = self::historyCap($context);
        $window = self::minWindowUs($config);

        return static function () use ($procs, $collector, $perCore, $cap, $window): ?Msg {
            if (!$collector->due($window)) {
                return null;
            }
            [$snapshot, $next] = $procs->sample();
            $processes = $snapshot instanceof ProcSnapshot ? $snapshot : new ProcSnapshot([], 1);
            [$containers, $collectorNext] = $collector->collect($processes->processes, $processes->memTotal, $processes->coreCount, $perCore, $cap);

            return new SampledMsg('ctr', new CtrSample($containers, $collectorNext), $next);
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /** `[` / `]` arrive unclaimed (btop checks the container box last); claim nothing. */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function capturesClick(MouseMsg $msg, PanelContext $context): bool
    {
        return $context->visible() && $this->clicked($msg, $context) !== null;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg) {
            return $this->sampled($msg, $context);
        }
        if (!$context->visible()) {
            return new PanelResult($this);
        }
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt && \in_array($msg->rune, ['[', ']'], true)) {
            return $this->select($msg->rune === ']' ? 1 : -1, $context);
        }
        if ($msg instanceof MouseMsg && ($key = $this->clicked($msg, $context)) !== null) {
            if ($key === '[' || $key === ']') {
                return $this->select($key === ']' ? 1 : -1, $context);
            }

            return $this->selectRow((int) substr($key, 7), $context);
        }
        if ($msg instanceof WindowSizeMsg) {
            return new PanelResult($this->settled($context));
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        CtrView::paint($region, $frame, $this->snapshot, $this->graphs, $this->start);
    }

    /**
     * btop Ctr::select: position 0 is "no selection"; step through it
     * and the containers with wrap-around.
     */
    public static function stepped(?ContainerSnapshot $snapshot, string $selected, int $step): string
    {
        $containers = $snapshot?->containers ?? [];
        $size = \count($containers);
        $index = $selected === '' ? null : $snapshot?->indexOf($selected);
        $pos = $index === null ? 0 : $index + 1;
        $pos = ($pos + $step + $size + 1) % ($size + 1);

        return $pos === 0 ? '' : $containers[$pos - 1]->path;
    }

    /**
     * The mapped zone under a bare left click: `[`, `]` (the title's
     * `[ select ]` halves) or `ctr_row<N>` (a listed container) — btop's
     * mouse_mappings for this box.
     */
    public function clicked(MouseMsg $msg, PanelContext $context): ?string
    {
        $box = $context->box;
        if ($box === null || KeyName::of($msg) !== 'mouse_click') {
            return null;
        }
        $hit = KeyName::mapped($msg, CtrView::buttons($box, $this->snapshot, $context->config->string(Schema::CTR_SELECTED), $this->start));

        return $hit === '[' || $hit === ']' || str_starts_with($hit, 'ctr_row') ? $hit : null;
    }

    private function select(int $step, PanelContext $context): PanelResult
    {
        return $this->picked(self::stepped($this->snapshot, $context->config->string(Schema::CTR_SELECTED), $step), $context);
    }

    /** btop Ctr::select_row: toggle the container on list row `$row`. */
    private function selectRow(int $row, PanelContext $context): PanelResult
    {
        $containers = $this->snapshot?->containers ?? [];
        if ($row < 0 || $this->start + $row >= \count($containers)) {
            return new PanelResult($this);
        }
        $path = $containers[$this->start + $row]->path;
        $current = $context->config->string(Schema::CTR_SELECTED);

        return $this->picked($current === $path ? '' : $path, $context);
    }

    /** Write the selection and keep it in view under the post-write config. */
    private function picked(string $path, PanelContext $context): PanelResult
    {
        $after = new PanelContext($context->config->with(Schema::CTR_SELECTED, $path), $context->layout, $context->box);

        return new PanelResult($this->settled($after), null, [Schema::CTR_SELECTED => $path]);
    }

    private function sampled(SampledMsg $msg, PanelContext $context): PanelResult
    {
        if ($msg->box === 'proc') {
            // The tap: the proc box's scan, grouped by this box's collector.
            $snapshot = match (true) {
                $msg->snapshot instanceof ProcGpuSample => $msg->snapshot->proc,
                $msg->snapshot instanceof ProcSnapshot => $msg->snapshot,
                default => null,
            };
            if ($snapshot === null || !$context->visible() || !$this->collector->enabled()) {
                return new PanelResult($this);
            }
            $collector = $this->collector->withVms($context->config->bool(self::SHOW_VMS));
            $perCore = $context->config->bool('proc_per_core');
            $cap = self::historyCap($context);
            $procs = $this->procs;
            $window = self::minWindowUs($context->config);
            // A guest picked from the VM dashboard while this box lists no VMs
            // (ctr_show_vms off) stays picked until its processes are gone.
            $selected = $context->config->string(Schema::CTR_SELECTED);
            $set = self::hiddenVmPick($selected, $context->config) && !self::scanned($snapshot, $selected) ? [Schema::CTR_SELECTED => ''] : [];

            return new PanelResult($set === [] ? $this : $this->settled(new PanelContext($context->config->with(Schema::CTR_SELECTED, ''), $context->layout, $context->box)), static function () use ($collector, $snapshot, $perCore, $cap, $procs, $window): ?Msg {
                // A proc rescan off the data tick (sort change, Enter, a
                // toggle) is skipped: its few-ms window would make the
                // cgroup cpu% noise and add a history point.
                if (!$collector->due($window)) {
                    return null;
                }
                [$containers, $next] = $collector->collect($snapshot->processes, $snapshot->memTotal, $snapshot->coreCount, $perCore, $cap);

                return new SampledMsg('ctr', new CtrSample($containers, $next), $procs);
            }, $set);
        }
        if ($msg->box !== 'ctr' || !$msg->snapshot instanceof CtrSample) {
            return new PanelResult($this);
        }
        $snapshot = $msg->snapshot->snapshot;
        $config = $context->config;
        $selected = $config->string(Schema::CTR_SELECTED);
        // btop Ctr::collect: a selection whose container is gone is cleared —
        // except a VM picked from the VM dashboard while VMs are not listed
        // here: the proc tap clears that one once its processes are gone.
        $set = $selected !== '' && $snapshot->indexOf($selected) === null && !self::hiddenVmPick($selected, $config) ? [Schema::CTR_SELECTED => ''] : [];
        $next = new self($msg->next, $msg->snapshot->collector, $snapshot, $this->graphs, $this->family, $this->start);
        $after = $set === [] ? $context : new PanelContext($config->with(Schema::CTR_SELECTED, ''), $context->layout, $context->box);
        $next = $next->settled($after)->observed($after);

        return new PanelResult($next, null, $set);
    }

    /**
     * One mini-graph push per fresh sample for the rows the box draws
     * (btop feeds a row's graph from Ctr::draw's list loop, so a container
     * scrolled out of view gets none); graphs of vanished containers are
     * erased.
     */
    private function observed(PanelContext $context): self
    {
        $snapshot = $this->snapshot;
        if ($snapshot === null) {
            return $this;
        }
        $family = ProcView::family($context->config);
        $graphs = $family === $this->family ? $this->graphs : [];
        $live = [];
        foreach ($snapshot->containers as $c) {
            $live[$c->path] = true;
        }
        $graphs = array_intersect_key($graphs, $live);
        if ($context->box !== null) {
            $rows = CtrView::selectMax($context->box->height);
            foreach (\array_slice($snapshot->containers, $this->start, max(0, $rows)) as $c) {
                $graph = $graphs[$c->path] ?? DualSampleGraph::new(5, 1, $family);
                $value = $c->cpu >= 0.1 && $c->cpu < 5 ? 5 : (int) round($c->cpu);
                $graphs[$c->path] = $graph->push(max(0, $value));
            }
        }

        return new self($this->procs, $this->collector, $snapshot, $graphs, $family, $this->start);
    }

    /** Ctr::draw's scroll law against the current box and selection. */
    private function settled(PanelContext $context): self
    {
        if ($context->box === null || $this->snapshot === null) {
            return $this;
        }
        $start = CtrView::scroll(
            $this->start,
            $this->snapshot->indexOf($context->config->string(Schema::CTR_SELECTED)) ?? -1,
            $this->snapshot->count(),
            CtrView::selectMax($context->box->height),
        );

        return $start === $this->start ? $this : new self($this->procs, $this->collector, $this->snapshot, $this->graphs, $this->family, $start);
    }

    /**
     * `$selected` is a libvirt guest's scope while ctr_show_vms is off — a
     * pick the VM dashboard's Enter made that this box cannot list.
     */
    private static function hiddenVmPick(string $selected, Config $config): bool
    {
        return $selected !== '' && !$config->bool(self::SHOW_VMS) && (Vm::scope($selected)['path'] ?? null) === $selected;
    }

    /** Whether a process of the scan sits in cgroup `$path`. */
    private static function scanned(ProcSnapshot $snapshot, string $path): bool
    {
        foreach ($snapshot->processes as $p) {
            if ($p->container?->cgroupPath === $path) {
                return true;
            }
        }

        return false;
    }

    /** Half of update_ms, in microseconds: the shortest window a sample may cover. */
    private static function minWindowUs(Config $config): int
    {
        return intdiv($config->updateMs() * 1000, 2);
    }

    private static function historyCap(PanelContext $context): int
    {
        $width = $context->layout?->width ?? 0;

        return $width > 0 ? $width : self::DEFAULT_HISTORY;
    }

    /** The own process scan, retuned like the proc box's: per-core cpu and the kernel filter, no io. */
    private static function tuned(Source $source, Config $config): Source
    {
        $perCore = $config->bool('proc_per_core');
        $kernel = $config->bool('proc_filter_kernel');
        if ($source instanceof CollectorSource && ($c = $source->collector()) instanceof TunableProcList) {
            return CollectorSource::of($c->withIo(false)->withPerCore($perCore)->withFilterKernel($kernel)->withDetail(null));
        }
        if ($source instanceof FakeProcList) {
            return $source->withIo(false)->withPerCore($perCore)->withFilterKernel($kernel)->withDetail(null);
        }

        return $source;
    }
}
