<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\FreqSnapshot;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Temp;
use SugarCraft\Top\Collect\TempSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Cpu\BatteryBadge;
use SugarCraft\Top\Panel\Cpu\CpuView;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Gfx\NamedSources;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeFreq;
use SugarCraft\Top\Source\Fake\FakeTemp;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\View\Region;

/**
 * btop's cpu box: upper/lower history graphs (or per-GPU graphs), the
 * divider label row, uptime, the cores sub-box (frequency label, CPU
 * meter + package temp, per-core grid with usage graphs, temps and
 * #1785 per-core frequency, load average, GPU brief rows) and the P-D
 * battery badge seam ({@see BatteryBadge}).
 *
 * Data: one Cmd per tick samples Cpu, Freq, Temp and (unless
 * show_gpu_info is Off) Gpu together through {@see NamedSources}. Every
 * reading lands in a {@see History}, which holds the last good value on
 * UNMEASURED (btop #1008), so graphs never dip/spike on a failed read and
 * readouts never flip to `n/a`. Per-core frequency is only read while
 * show_core_freq is not "off": the freq source is retuned at collect time
 * from the CURRENT config (Freq::withPerCore).
 *
 * Keys: none. btop's cpu-box input block is `+`/`-`/`=` (update_ms),
 * which the App owns (btop_input.cpp:541-570); this panel never claims.
 *
 * Mirrors aristocratos/btop Cpu::draw (src/btop_draw.cpp:567-1024).
 */
final class CpuPanel implements Panel
{
    /** btop caps `core_percent` deques at 40 (linux/btop_collect.cpp:1153). */
    public const CORE_HISTORY = 40;

    /** btop caps temp deques at 20 (cpu) / 18 (gpu) (linux/btop_collect.cpp:632, 2249). */
    public const TEMP_HISTORY = 20;

    /** Per-GPU utilization/memory/power series the graph fields can name. */
    public const GPU_FIELDS = ['gpu-totals', 'gpu-vram-totals', 'gpu-pwr-totals'];

    /** Shared (all-GPU) series. */
    public const GPU_SHARED_FIELDS = ['gpu-average', 'gpu-vram-total', 'gpu-pwr-total'];

    /** Fallback history length before the first WindowSizeMsg (btop keeps `width * 2`). */
    private const DEFAULT_HISTORY = 512;

    /** The members {@see collect()} samples on every tick (battery is the badge's). */
    private const MEMBERS = ['cpu', 'freq', 'temp', 'gpu'];

    /**
     * @param list<string>          $fields    cpu_percent fields seen (btop Cpu::available_fields minus "Auto")
     * @param array<int, bool>      $inactive  cores whose latest reading was UNMEASURED (offline)
     * @param array{0: float, 1: float, 2: float} $load last good load averages
     * @param list<GpuDevice>       $gpus      last non-empty GPU list
     */
    private function __construct(
        private readonly NamedSources $sources,
        private readonly ?BatteryBadge $battery,
        private readonly History $history,
        private readonly bool $sampled,
        private readonly int $coreCount,
        private readonly array $fields,
        private readonly array $inactive,
        private readonly array $load,
        private readonly float $uptime,
        private readonly ?FreqSnapshot $freq,
        private readonly ?TempSnapshot $temp,
        private readonly array $gpus,
    ) {
    }

    /**
     * @param Source  $cpu  CpuSnapshot source (Collect\Cpu or FakeCpu)
     * @param ?Source $freq FreqSnapshot source; retuned per collect for show_core_freq
     * @param ?Source $temp TempSnapshot source; sampled only while check_temp is on
     * @param ?Source $gpu  GpuSnapshot source; sampled only while show_gpu_info is not Off
     */
    public static function new(Source $cpu, ?Source $freq = null, ?Source $temp = null, ?Source $gpu = null): self
    {
        return new self(
            NamedSources::of(['cpu' => $cpu, 'freq' => $freq, 'temp' => $temp, 'gpu' => $gpu]),
            null,
            History::new(),
            false,
            0,
            [],
            [],
            [-1.0, -1.0, -1.0],
            -1.0,
            null,
            null,
            [],
        );
    }

    /**
     * The roster entry {@see Panels::standard()} uses: live collectors, or
     * the deterministic fakes. Startup options (freq_mode, cpu_sensor,
     * show_core_freq) seed the collectors; runtime changes of
     * show_core_freq / check_temp / show_gpu_info are honoured at collect
     * time.
     */
    public static function standard(HostInfo $host, Config $config, bool $fake = false): self
    {
        $perCore = $config->showCoreFreq() !== 'off';
        if ($fake) {
            return self::new(
                FakeCpu::new($host->coreCount, $config->updateMs() / 1000),
                FakeFreq::new($host->coreCount, $perCore),
                FakeTemp::new(max(1, intdiv($host->coreCount, 2))),
            );
        }
        $sensor = $config->string('cpu_sensor');

        return self::new(
            CollectorSource::of(Cpu::new()),
            CollectorSource::of(Freq::new(null, FreqMode::tryFrom($config->string('freq_mode')) ?? FreqMode::First, $perCore)),
            CollectorSource::of(Temp::new(null, $sensor === 'Auto' ? null : $sensor)),
            CollectorSource::of(Gpu::new()),
        );
    }

    /** Install (or remove) the P-D battery badge. */
    public function withBattery(?BatteryBadge $battery): self
    {
        return $this->mutate(battery: $battery, batterySet: true);
    }

    public function box(): string
    {
        return 'cpu';
    }

    public function collect(PanelContext $context): ?\Closure
    {
        $config = $context->config;
        $perCore = $config->showCoreFreq() !== 'off';
        $members = ['cpu'];
        if ($config->bool('show_cpu_freq') || $perCore) {
            $members[] = 'freq';
        }
        if ($config->bool('check_temp')) {
            $members[] = 'temp';
        }
        if ($config->string('show_gpu_info') !== 'Off') {
            $members[] = 'gpu';
        }
        $sampling = $this->sources->only($members);
        $sampling = $sampling->with('freq', self::tuneFreq($sampling->get('freq'), $perCore));
        $sampling = $sampling->with('battery', $this->battery?->source($context));

        return static function () use ($sampling): Msg {
            [$snapshot, $next] = $sampling->sample();

            return new SampledMsg('cpu', $snapshot, $next);
        };
    }

    /** The cpu box never owns input. */
    public function modal(PanelContext $context): bool
    {
        return false;
    }

    /** btop's cpu-box keys (`+`/`-`/`=`) are the App's; claim nothing. */
    public function capturesKey(KeyMsg $key, PanelContext $context): bool
    {
        return false;
    }

    public function update(Msg $msg, PanelContext $context): PanelResult
    {
        if (!$msg instanceof SampledMsg || $msg->box !== 'cpu' || !$msg->snapshot instanceof Samples) {
            return new PanelResult($this);
        }
        $samples = $msg->snapshot;
        $next = $msg->next instanceof NamedSources ? $msg->next : NamedSources::of([]);
        // btop trims to `width * 2` with Cpu::width (the cpu box, which spans the terminal).
        $cap = 2 * max(1, $context->box?->width ?? $context->layout?->width ?? intdiv(self::DEFAULT_HISTORY, 2));

        $self = $this->mutate(sources: $this->sources->merge($next->only(self::MEMBERS)), sampled: true);
        $cpu = $samples->get('cpu');
        if ($cpu instanceof CpuSnapshot) {
            $self = $self->withCpu($cpu, $cap);
        }
        $freq = $samples->get('freq');
        if ($freq instanceof FreqSnapshot) {
            $self = $self->withFreq($freq);
        }
        $temp = $samples->get('temp');
        if ($temp instanceof TempSnapshot) {
            $self = $self->withTemp($temp);
        }
        $gpu = $samples->get('gpu');
        if ($gpu instanceof GpuSnapshot) {
            $self = $self->withGpu($gpu, $cap);
        }
        $battery = $samples->get('battery');
        $batteryNext = $next->get('battery');
        if ($self->battery !== null && $battery !== null && $batteryNext !== null) {
            $self = $self->mutate(battery: $self->battery->withSample($battery, $batteryNext, $context), batterySet: true);
        }

        return new PanelResult($self);
    }

    public function paint(Region $region, PanelFrame $frame): void
    {
        CpuView::paint($region, $frame, $this);
    }

    public function sampled(): bool
    {
        return $this->sampled;
    }

    public function history(): History
    {
        return $this->history;
    }

    /** Logical cpus in the latest good sample; 0 before one. */
    public function coreCount(): int
    {
        return $this->coreCount;
    }

    /** True when core `$n`'s latest reading was UNMEASURED (offline / hot-unplugged). */
    public function inactive(int $n): bool
    {
        return $this->inactive[$n] ?? false;
    }

    /** @return array{0: float, 1: float, 2: float} last good 1/5/15-minute load (-1 never measured) */
    public function load(): array
    {
        return $this->load;
    }

    /** Last good uptime in seconds, -1 before one. */
    public function uptime(): float
    {
        return $this->uptime;
    }

    public function freq(): ?FreqSnapshot
    {
        return $this->freq;
    }

    public function temp(): ?TempSnapshot
    {
        return $this->temp;
    }

    /** @return list<GpuDevice> */
    public function gpus(): array
    {
        return $this->gpus;
    }

    public function battery(): ?BatteryBadge
    {
        return $this->battery;
    }

    /**
     * Fields cpu_graph_upper/lower may name right now — btop
     * Cpu::available_fields: "total", every /proc/stat field seen, and the
     * GPU series once a GPU answered.
     *
     * @return list<string>
     */
    public function graphFields(): array
    {
        $fields = ['total', ...$this->fields];
        if ($this->gpus !== []) {
            $fields = [...$fields, ...self::GPU_FIELDS, ...self::GPU_SHARED_FIELDS];
        }

        return $fields;
    }

    private function withCpu(CpuSnapshot $s, int $cap): self
    {
        $history = $this->history->push('total', $s->total, $cap);
        $fields = $this->fields;
        foreach ($s->fields as $name => $value) {
            $history = $history->push($name, $value, $cap);
            if ($value >= 0 && !in_array($name, $fields, true)) {
                $fields[] = $name;
            }
        }
        $inactive = $this->inactive;
        foreach ($s->cores as $i => $value) {
            $history = $history->push('core:' . $i, $value, self::CORE_HISTORY);
            // A core that never measured is offline (dimmed); one that did
            // keeps its last state through an UNMEASURED read — a resample
            // inside one jiffy reads every core as Δ=0 and must not dim
            // the whole grid (#1008). btop asks cpuset.cpus.effective.
            $inactive[$i] = $value < 0 ? ($inactive[$i] ?? true) : false;
        }
        $load = $this->load;
        foreach ($s->load as $i => $value) {
            if ($value >= 0) {
                $load[$i] = $value;
            }
        }

        return $this->mutate(
            history: $history,
            fields: $fields,
            inactive: $inactive,
            coreCount: $s->cores === [] ? $this->coreCount : max($this->coreCount, count($s->cores)),
            load: $load,
            uptime: $s->uptime >= 0 ? $s->uptime : $this->uptime,
        );
    }

    private function withFreq(FreqSnapshot $s): self
    {
        $history = $this->history;
        foreach ($s->perCore as $i => $mhz) {
            $history = $history->push('freq:' . $i, $mhz, self::CORE_HISTORY);
        }
        // #1008: a failed aggregate read keeps the previous label.
        $keep = $s->mhz < 0 && $this->freq !== null ? $this->freq : $s;

        return $this->mutate(history: $history, freq: $keep, freqSet: true);
    }

    private function withTemp(TempSnapshot $s): self
    {
        $history = $this->history->push('temp:cpu', $s->cpu(), self::TEMP_HISTORY);
        foreach ($s->cores as $i => $value) {
            $history = $history->push('temp:' . $i, $value, self::TEMP_HISTORY);
        }

        return $this->mutate(history: $history, temp: $s, tempSet: true);
    }

    private function withGpu(GpuSnapshot $s, int $cap): self
    {
        if (!$s->available()) {
            return $this; // a transient failure keeps the last devices (#1008)
        }
        $history = $this->history;
        $util = [];
        $used = 0;
        $total = 0;
        $watts = 0.0;
        $limit = 0.0;
        $previous = $this->gpus;
        $devices = [];
        foreach ($s->devices as $i => $d) {
            $d = self::holdDevice($d, $previous[$i] ?? null);
            $devices[] = $d;
            $pwr = $d->watts >= 0 && $d->powerLimit > 0 ? min(100.0, $d->watts * 100.0 / $d->powerLimit) : -1.0;
            $history = $history
                ->push("gpu:{$i}:gpu-totals", $d->utilization, $cap)
                ->push("gpu:{$i}:gpu-vram-totals", $d->memPercent(), $cap)
                ->push("gpu:{$i}:gpu-pwr-totals", $pwr, $cap)
                ->push("gpu:{$i}:temp", $d->temp, 18);
            if ($d->utilization >= 0) {
                $util[] = $d->utilization;
            }
            if ($d->memUsed >= 0 && $d->memTotal > 0) {
                $used += $d->memUsed;
                $total += $d->memTotal;
            }
            if ($d->watts >= 0 && $d->powerLimit > 0) {
                $watts += $d->watts;
                $limit += $d->powerLimit;
            }
        }
        $history = $history
            ->push('gpu-average', $util === [] ? -1.0 : array_sum($util) / count($util), $cap)
            ->push('gpu-vram-total', $total > 0 ? $used * 100.0 / $total : -1.0, $cap)
            ->push('gpu-pwr-total', $limit > 0 ? min(100.0, $watts * 100.0 / $limit) : -1.0, $cap);

        return $this->mutate(history: $history, gpus: $devices);
    }

    /**
     * #1008 for GPUs: a column that measured once keeps its last value
     * when one query answers N/A, so the row never loses a column (btop's
     * columns follow the device's supported_functions, not one reading).
     */
    private static function holdDevice(GpuDevice $d, ?GpuDevice $prev): GpuDevice
    {
        if ($prev === null || $prev->index !== $d->index) {
            return $d;
        }
        $f = static fn (float $now, float $old): float => $now >= 0 ? $now : $old;
        $n = static fn (int $now, int $old): int => $now >= 0 ? $now : $old;

        return new GpuDevice(
            $d->index,
            $d->name,
            $f($d->utilization, $prev->utilization),
            $n($d->memUsed, $prev->memUsed),
            $n($d->memTotal, $prev->memTotal),
            $f($d->temp, $prev->temp),
            $f($d->watts, $prev->watts),
            $f($d->memUtilization, $prev->memUtilization),
            $f($d->powerLimit, $prev->powerLimit),
            $f($d->clockGraphics, $prev->clockGraphics),
            $f($d->clockMem, $prev->clockMem),
            $f($d->clockGraphicsMax, $prev->clockGraphicsMax),
            $f($d->clockMemMax, $prev->clockMemMax),
            $f($d->fanSpeed, $prev->fanSpeed),
            $d->pstate,
            $n($d->pcieGen, $prev->pcieGen),
            $n($d->pcieWidth, $prev->pcieWidth),
            $f($d->tempMem, $prev->tempMem),
            $f($d->encoderUtilization, $prev->encoderUtilization),
            $f($d->decoderUtilization, $prev->decoderUtilization),
            $d->uuid,
        );
    }

    /** show_core_freq != off asks the Freq collector for per-core reads (btop #1785). */
    private static function tuneFreq(?Source $source, bool $perCore): ?Source
    {
        if ($source instanceof FakeFreq) {
            return $source->withPerCore($perCore);
        }
        if ($source instanceof CollectorSource) {
            $collector = $source->collector();
            if ($collector instanceof Freq) {
                return CollectorSource::of($collector->withPerCore($perCore));
            }
        }

        return $source;
    }

    /**
     * @param ?list<string>                          $fields
     * @param ?array<int, bool>                      $inactive
     * @param ?array{0: float, 1: float, 2: float}   $load
     * @param ?list<GpuDevice>                       $gpus
     */
    private function mutate(
        ?NamedSources $sources = null,
        ?BatteryBadge $battery = null,
        bool $batterySet = false,
        ?History $history = null,
        ?bool $sampled = null,
        ?int $coreCount = null,
        ?array $fields = null,
        ?array $inactive = null,
        ?array $load = null,
        ?float $uptime = null,
        ?FreqSnapshot $freq = null,
        bool $freqSet = false,
        ?TempSnapshot $temp = null,
        bool $tempSet = false,
        ?array $gpus = null,
    ): self {
        return new self(
            $sources ?? $this->sources,
            $batterySet ? $battery : $this->battery,
            $history ?? $this->history,
            $sampled ?? $this->sampled,
            $coreCount ?? $this->coreCount,
            $fields ?? $this->fields,
            $inactive ?? $this->inactive,
            $load ?? $this->load,
            $uptime ?? $this->uptime,
            $freqSet ? $freq : $this->freq,
            $tempSet ? $temp : $this->temp,
            $gpus ?? $this->gpus,
        );
    }
}
