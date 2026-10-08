<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Top\Collect\Gpu\Settled;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\Config\InvalidOptionValue;
use SugarCraft\Top\Input\KeyName;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Gpu\GpuDemand;
use SugarCraft\Top\Panel\Gpu\GpuFeed;
use SugarCraft\Top\Panel\Gpu\GpuFunctions;
use SugarCraft\Top\Panel\Gpu\GpuHold;
use SugarCraft\Top\Panel\Gpu\GpuSampling;
use SugarCraft\Top\Panel\Gpu\GpuView;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\GpuRoster;
use SugarCraft\Top\View\Region;

/**
 * btop's gpu boxes (btop_draw.cpp Gpu::draw) with btop PR #1730's slots and
 * PR #1881's grid: ONE panel, registered as `gpu`, draws every `gpuN` box
 * in shown_boxes ({@see \SugarCraft\Top\App::panelFor()}); which box is
 * being painted comes from {@see PanelFrame::$name}, its geometry from
 * {@see \SugarCraft\Top\View\Layout::$gpuBoxes}.
 *
 * Box indexes follow btop's `gpus[]`: GPUs first, then NPUs (btop #985 and
 * #1839 append the NPU to the GPU list and relabel its box `npu` / its
 * memory `ram`) — the minimal faithful NPU presentation: an NPU gets its
 * own box through the same drawing path, toggled and retargeted like any
 * GPU, while the cpu box's GPU rows, averages and totals never include it
 * ({@see GpuSnapshot::$devices} is GPUs only).
 *
 * Data: one snapshot per tick for all shown boxes, only while at least
 * one gpu box is shown (#1858), asked from the App's shared GPU feed
 * ({@see GpuFeed}, consumer `gpu`, demand from the CURRENT config). While
 * an nvidia-smi query is in flight the Cmd resolves when it lands
 * (loop-driven, never blocking); between queries at once. Every device is
 * held through N/A readings by {@see GpuHold} (#1008, bounded), so a box
 * whose GPU is gone for good reads n/a instead of frozen numbers.
 *
 * Input: the number keys are the App's (slot toggles); this panel claims
 * only bare left clicks on a box title's `←` / `→` (btop mouse_mappings
 * gpu_prev_N / gpu_next_N), which retarget that box to the previous /
 * next GPU no other box shows ({@see GpuPanels::switchTarget()}) through
 * {@see PanelResult::$set} — shown_boxes and the slots together, so the
 * slot (and its title key) stays while the target changes.
 *
 * The roster it reports ({@see gpuRoster()}) sizes the grid; before the
 * first sample it is the startup probe ({@see withRoster()}), the way btop
 * knows Gpu::count from init.
 */
final class GpuPanel implements Panel, ClickCapture, OptionChoices, GpuRosterSource
{
    /** btop trims gpu_percent deques to `width * 2`; this before any layout. */
    private const DEFAULT_HISTORY = 512;

    /** btop caps gpu temp deques at 18 (linux/btop_collect.cpp). */
    public const TEMP_HISTORY = 18;

    private function __construct(
        private readonly ?Source $source,
        private readonly GpuHold $gpus,
        private readonly GpuHold $npus,
        private readonly History $history,
        private readonly bool $sampled,
        private readonly GpuRoster $seed,
        private readonly GpuRoster $roster,
    ) {
    }

    /**
     * @param ?Source $source the shared {@see GpuFeed}, or any GpuSnapshot source
     *                        (Collect\Gpu\Accelerators via Platform::gpu(), FakeGpu) given a feed of its own
     */
    public static function new(?Source $source): self
    {
        return new self($source === null ? null : GpuFeed::of($source), GpuHold::new(), GpuHold::new(), History::new(), false, GpuRoster::none(), GpuRoster::none());
    }

    /**
     * The roster entry {@see Panels::standard()} uses: the platform's
     * accelerators, or {@see FakeGpu} (its roster known up front, so the
     * slot keys work before the first sample). `$probe` is a snapshot the
     * caller already took (bin/candy-top probes once at startup, as btop's
     * Gpu::init does) — it seeds the roster until the panel samples.
     * Every option (shown_gpus, ...) is read at collect time, so no
     * startup config is needed here. `$feed` is the App's shared GPU feed
     * (default: one of its own over that source).
     */
    public static function standard(bool $fake = false, ?Platform $platform = null, ?GpuSnapshot $probe = null, ?GpuFeed $feed = null): self
    {
        if ($fake) {
            // The fake roster is known up front; sampling a throwaway copy leaves the feed's sequence alone.
            [$probe] = FakeGpu::new()->sample();
            $source = $feed ?? FakeGpu::new();
        } else {
            $platform ??= Platform::detect();
            $source = $feed ?? $platform->gpu();
        }
        $panel = self::new($source);

        return $probe instanceof GpuSnapshot ? $panel->withRoster(self::rosterOf($probe->devices, $probe->npus)) : $panel;
    }

    /**
     * One synchronous sample of the host's accelerators for startup
     * (btop Shared::init → Gpu::init): bounded by the collectors' own
     * spawn timeouts. Only ever called outside the TEA loop.
     */
    public static function probe(Config $config, Platform $platform): GpuSnapshot
    {
        $source = GpuSampling::tune($platform->gpu(), $config);
        [$snapshot] = $source?->sample() ?? [new GpuSnapshot([])];

        return $snapshot instanceof GpuSnapshot ? $snapshot : new GpuSnapshot([]);
    }

    /** Seed the roster reported before the first sample. */
    public function withRoster(GpuRoster $roster): self
    {
        $current = $this->gpus->devices() === [] && $this->npus->devices() === [] ? $roster : $this->roster;

        return new self($this->source, $this->gpus, $this->npus, $this->history, $this->sampled, $roster, $current);
    }

    public function box(): string
    {
        return 'gpu';
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $config = $context->config;
        $feed = $this->source;
        if (GpuPanels::targets($config->shownBoxes()) === [] || !$feed instanceof GpuFeed) {
            return null; // #1858: no gpu box, no sample
        }
        $demand = GpuDemand::of($config);

        return static function () use ($feed, $demand): Msg {
            $snapshot = $feed->request('gpu', $demand);
            [$settled, $value] = Settled::peek($snapshot);
            if ($settled) {
                return new SampledMsg('gpu', $value, $feed);
            }

            return Cmd::promise(static fn () => $snapshot->then(static fn (GpuSnapshot $s): Msg => new SampledMsg('gpu', $s, $feed)))();
        };
    }

    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /** The slot keys 5-0 are App globals; claim nothing. */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function capturesClick(MouseMsg $msg, PanelContext $context): bool
    {
        return $this->clickedSelector($msg, $context) !== null;
    }

    /**
     * The `←` / `→` zone under a bare left click: [panel index,
     * direction], or null (btop mouse_mappings gpu_prev_N / gpu_next_N).
     *
     * @return array{0: int, 1: int}|null
     */
    public function clickedSelector(MouseMsg $msg, PanelContext $context): ?array
    {
        $map = [];
        foreach ($context->layout?->gpuBoxes ?? [] as $gpu) {
            if ($gpu->selector !== null) {
                $map['gpu_prev_' . $gpu->panel] = [$gpu->selector[0], $gpu->rect->y, 1, 1];
                $map['gpu_next_' . $gpu->panel] = [$gpu->selector[1], $gpu->rect->y, 1, 1];
            }
        }
        $hit = KeyName::mapped($msg, $map);
        if (preg_match('/^gpu_(prev|next)_(\d+)$/D', $hit, $m) !== 1 || KeyName::of($msg) !== 'mouse_click') {
            return null;
        }

        return [(int) $m[2], $m[1] === 'next' ? 1 : -1];
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if ($msg instanceof SampledMsg && $msg->box === 'gpu' && $msg->snapshot instanceof GpuSnapshot) {
            return new PanelResult($this->sampledFrom($msg->snapshot, $msg->next instanceof Source ? $msg->next : $this->source, $context));
        }
        if ($msg instanceof MouseMsg && ($hit = $this->clickedSelector($msg, $context)) !== null) {
            return new PanelResult($this, null, $this->retarget($hit[0], $hit[1], $context));
        }

        return new PanelResult($this);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        GpuView::paint($region, $frame, $this);
    }

    public function sampled(): bool
    {
        return $this->sampled;
    }

    public function history(): History
    {
        return $this->history;
    }

    /**
     * The device a `gpuN` box shows: GPU N, or — past the GPUs — NPU
     * N - (GPU count); null when that index has not been seen.
     */
    public function device(int $index): ?GpuDevice
    {
        $gpus = $this->gpus->devices();
        if ($index < \count($gpus)) {
            return $gpus[$index];
        }

        return $this->npus->devices()[$index - \count($gpus)] ?? null;
    }

    /** History key prefix for accelerator `$index`. */
    public static function key(int $index, string $series): string
    {
        return 'a' . $index . ':' . $series;
    }

    public function gpuRoster(): GpuRoster
    {
        // Computed once per sample (sampledFrom), not per App::roster() call.
        return $this->roster;
    }

    /** The options menu's gpu tab needs no list sources from this box. */
    public function optionChoices(): array
    {
        return [];
    }

    public function detectedGpus(): int
    {
        return $this->gpuRoster()->count();
    }

    /**
     * @param list<GpuDevice> $gpus
     * @param list<GpuDevice> $npus
     */
    public static function rosterOf(array $gpus, array $npus): GpuRoster
    {
        $all = [...$gpus, ...$npus];

        return new GpuRoster(
            array_map(static fn (GpuDevice $d): int => GpuFunctions::of($d)->offset(), $all),
            array_map(static fn (GpuDevice $d): string => $d->name, $all),
            \count($gpus),
        );
    }

    private function sampledFrom(GpuSnapshot $s, ?Source $next, PanelContext $context): self
    {
        $hold = GpuHold::limit($context->config->updateMs());
        [$gpus, $gpuExpired] = $this->gpus->apply($s->devices, $hold);
        // NPUs index after the GPUs (btop #985's place in gpus[]).
        [$npus, $npuExpired] = $this->npus->apply($s->npus, $hold);
        $offset = \count($gpus->devices());
        $expired = [...$gpuExpired, ...array_map(static fn (int $i): int => $i + $offset, $npuExpired)];
        $history = $this->history;
        if ($expired !== []) {
            $history = $history->withoutPrefixes(...array_map(static fn (int $i): string => 'a' . $i . ':', $expired));
        }
        $width = 0;
        foreach ($context->layout?->gpuBoxes ?? [] as $box) {
            $width = max($width, $box->rect->width);
        }
        $cap = $width > 0 ? 2 * $width : self::DEFAULT_HISTORY;
        if ($s->hasAccelerators()) {
            foreach ([...$gpus->devices(), ...$npus->devices()] as $i => $d) {
                $pwr = $d->watts >= 0 && $d->powerLimit > 0 ? min(100.0, $d->watts * 100.0 / $d->powerLimit) : -1.0;
                $history = $history
                    ->push(self::key($i, 'util'), $d->utilization, $cap)
                    ->push(self::key($i, 'vram'), $d->memPercent(), $cap)
                    ->push(self::key($i, 'pwr'), $pwr, $cap)
                    ->push(self::key($i, 'memutil'), $d->memUtilization, $cap)
                    ->push(self::key($i, 'temp'), $d->temp, self::TEMP_HISTORY);
            }
        }

        $roster = $gpus->devices() === [] && $npus->devices() === [] ? $this->seed : self::rosterOf($gpus->devices(), $npus->devices());

        return new self($next ?? $this->source, $gpus, $npus, $history, true, $this->seed, $roster->equals($this->roster) ? $this->roster : $roster);
    }

    /**
     * btop switch_gpu_box for the clicked box; nothing when it cannot move
     * or the new boxes would not fit (btop apply_current_boxes' min-size
     * check refuses silently).
     *
     * @return array<string, string>
     */
    private function retarget(int $panel, int $direction, PanelContext $context): array
    {
        $roster = $this->gpuRoster();
        try {
            $next = GpuPanels::switchTarget($context->config, $panel, $direction, $roster->count());
        } catch (InvalidOptionValue) {
            return [];
        }
        $layout = $context->layout;
        if ($next === null || $layout === null
            || !FrameBuilder::fits($layout->width, $layout->height, $next->shownBoxes(), $roster, $next->gpuBoxColumns())) {
            return [];
        }

        return [
            'shown_boxes' => $next->string('shown_boxes'),
            GpuPanels::SLOTS_KEY => $next->string(GpuPanels::SLOTS_KEY),
        ];
    }
}
