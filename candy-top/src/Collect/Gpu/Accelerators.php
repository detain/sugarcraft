<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Paths;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * The multi-vendor GPU/NPU collector: btop Gpu::collect
 * (src/linux/btop_collect.cpp), which runs Nvml, Rsmi, Asysfs and Intel
 * over consecutive slices of one `gpus[]`. Several vendors at once are
 * normal (an Intel iGPU next to an NVIDIA or AMD card).
 *
 * Backends present on this host are chosen on the first sample (not at
 * construction, so building the collector reads nothing):
 *  - NVIDIA {@see Gpu} (nvidia-smi) — always installed; it memoizes its
 *    own absence after one failed sweep;
 *  - {@see AmdSysfs} when an amdgpu card exists (#1854);
 *  - {@see IntelSysfs} when an i915 / xe card exists (#1888);
 *  - {@see IntelNpu} (#985) and {@see AmdNpu} (#1839) when their accel
 *    devices exist.
 * An absent vendor costs nothing per sample; a host with none of them
 * yields empty snapshots, never failures.
 *
 * Merge: GPUs are re-indexed 0..n-1 in backend order (NVIDIA, AMD,
 * Intel) into {@see GpuSnapshot::$devices}; NPUs 0..m-1 into `$npus`.
 * Every device a backend reported before keeps its slot: when the whole
 * backend returns nothing (nvidia-smi's transient backoff) or one device
 * drops out of its list, the missing devices stand in with every
 * measurement unmeasured ({@see GpuDevice::unmeasured()}; matched by
 * uuid, else PCI slot — see pad()), so every later device keeps its
 * index — a GPU's graphs never jump to another device. If EVERY GPU is such a stand-in the snapshot has no GPUs,
 * which is exactly what a lone nvidia-smi returned before, so the
 * consumers' #1008 hold-last-value path is unchanged.
 *
 * DRM fdinfo ({@see DrmFdinfo}) is scanned once per sample, only when a
 * backend needs it for utilization (Intel) or per-process use is on and
 * an amdgpu / i915 / xe device exists — never on an NVIDIA-only host
 * (#1552 guard). Per-process rows (withProcesses()) merge nvidia-smi's
 * compute-apps + pmon rows with the fdinfo rows of the DRM devices, all
 * on the merged indexes; `processes` is null when no source measured.
 *
 * {@see sample()} blocks on nvidia-smi; {@see sampleAsync()} runs the same
 * cycle with the NVIDIA slice on the loop-driven runner (sysfs and the
 * fdinfo scan are cheap reads and stay synchronous), so the shared GPU
 * feed ({@see \SugarCraft\Top\Panel\Gpu\GpuFeed}) never stalls the UI.
 */
final class Accelerators
{
    /**
     * @param list<Backend>|null           $backends null = not discovered yet
     * @param array<int, list<GpuDevice>>   $last     backend position → last answered devices
     * @param list<GpuVendor>|null         $vendors  GPU vendor filter (btop shown_gpus); null = all
     * @param \Closure(): float            $clock
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $clock,
        private readonly Gpu $nvidia,
        private readonly ?array $backends,
        private readonly ?DrmFdinfo $scanner,
        private readonly bool $processes,
        private readonly ?array $vendors,
        private readonly array $last,
        private readonly bool $sysfs,
    ) {
    }

    /**
     * The Linux collector: nvidia-smi plus every sysfs backend found on the
     * first sample.
     *
     * @param Paths|null               $paths   fixture root in tests; null = the live host
     * @param Gpu|null                 $nvidia  the NVIDIA backend (tests inject a runner); null = Gpu::new()
     * @param (\Closure(): float)|null $clock   monotonic seconds for every rate
     * @param DrmFdinfo|null           $scanner the fdinfo scanner; null = DrmFdinfo::new() on the same paths and clock
     */
    public static function detect(?Paths $paths = null, ?Gpu $nvidia = null, ?\Closure $clock = null, ?DrmFdinfo $scanner = null): self
    {
        $paths ??= Paths::system();
        $clock ??= static fn (): float => hrtime(true) / 1e9;

        return new self($paths, $clock, $nvidia ?? Gpu::new(), null, $scanner ?? DrmFdinfo::new($paths, $clock), false, null, [], true);
    }

    /**
     * NVIDIA only — hosts without Linux sysfs (FreeBSD: nvidia-smi exists
     * there, amdgpu/i915 sysfs does not).
     */
    public static function nvidiaOnly(?Gpu $nvidia = null): self
    {
        $nvidia ??= Gpu::new();

        return new self(Paths::system(), static fn (): float => hrtime(true) / 1e9, $nvidia, [$nvidia], null, false, null, [], false);
    }

    /**
     * Collect per-process GPU use (#1552): nvidia-smi compute-apps + pmon
     * and the DRM fdinfo clients. Off by default (extra spawns / scans).
     */
    public function withProcesses(bool $on = true): self
    {
        $backends = $this->backends === null ? null : array_map(
            static fn (Backend $b): Backend => $b instanceof Gpu ? $b->withProcesses($on) : $b,
            $this->backends,
        );

        return new self($this->paths, $this->clock, $this->nvidia->withProcesses($on), $backends, $this->scanner, $on, $this->vendors, $this->last, $this->sysfs);
    }

    public function processesEnabled(): bool
    {
        return $this->processes;
    }

    /**
     * Restrict the GPU backends to btop `shown_gpus` tokens ("nvidia amd
     * intel"; unknown tokens such as "apple" are ignored). NPU backends are
     * not filtered — shown_gpus has no NPU token. Null = every vendor.
     *
     * @param list<string>|null $tokens
     */
    public function withVendors(?array $tokens): self
    {
        $vendors = null;
        if ($tokens !== null) {
            $vendors = [];
            foreach ($tokens as $token) {
                $v = GpuVendor::tryFrom(strtolower(trim($token)));
                if ($v !== null && !in_array($v, $vendors, true)) {
                    $vendors[] = $v;
                }
            }
        }

        return new self($this->paths, $this->clock, $this->nvidia, $this->backends, $this->scanner, $this->processes, $vendors, $this->last, $this->sysfs);
    }

    /**
     * The installed backends; empty before the first sample.
     *
     * @return list<Backend>
     */
    public function backends(): array
    {
        return $this->backends ?? [];
    }

    /**
     * @return array{0: GpuSnapshot, 1: self}
     */
    public function sample(): array
    {
        return Settled::value($this->sampleWith(false));
    }

    /**
     * The same sample with nvidia-smi on the loop ({@see Gpu::sampleAsync()}).
     *
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    public function sampleAsync(): PromiseInterface
    {
        return $this->sampleWith(true);
    }

    /**
     * @return PromiseInterface<array{0: GpuSnapshot, 1: self}>
     */
    private function sampleWith(bool $async): PromiseInterface
    {
        $backends = $this->backends ?? $this->discover();

        $active = [];
        $needScan = false;
        foreach ($backends as $i => $b) {
            if ($b->kind() === AcceleratorKind::Gpu && $this->vendors !== null && !in_array($b->vendor(), $this->vendors, true)) {
                continue;
            }
            $active[$i] = $b;
            $needScan = $needScan || $b->needsDrmScan() || ($this->processes && $b->drmClients());
        }

        $scan = DrmScan::none();
        $scanner = $this->scanner;
        if ($needScan && $scanner !== null) {
            [$scan, $scanner] = $scanner->sample();
        }

        $polls = [];
        foreach ($active as $i => $b) {
            $polls[$i] = $async && $b instanceof Gpu ? $b->sampleAsync() : resolve($b->poll($scan));
        }

        return all($polls)->then(fn (array $results): array => $this->merged($backends, $results, $scan, $scanner));
    }

    /**
     * Merge one cycle's backend results (keyed by backend position, in
     * backend order) into the snapshot and the next collector.
     *
     * @param list<Backend> $backends
     * @param array<int, array{0: GpuSnapshot, 1: Backend}> $results
     * @return array{0: GpuSnapshot, 1: self}
     */
    private function merged(array $backends, array $results, DrmScan $scan, ?DrmFdinfo $scanner): array
    {
        ksort($results);
        $last = $this->last;
        $gpus = [];
        $npus = [];
        $standIns = 0;
        $processes = null;
        $pdevIndex = [];
        foreach ($results as $i => [$snap, $next]) {
            $b = $backends[$i];
            $backends[$i] = $next;
            [$devices, $standIn] = self::pad($snap->accelerators(), $last[$i] ?? []);
            if ($devices !== []) {
                $last[$i] = $devices;
            }
            $native = [];
            foreach ($devices as $k => $d) {
                $isStandIn = isset($standIn[$k]);
                if ($d->kind === AcceleratorKind::Npu) {
                    $npus[] = $d->withIndex(count($npus));

                    continue;
                }
                $index = count($gpus);
                if (!$isStandIn) {
                    $native[$d->index] = $index;
                }
                $gpus[] = $d->withIndex($index);
                $standIns += $isStandIn ? 1 : 0;
                if (!$isStandIn && $b->drmClients() && $d->busId !== 'n/a') {
                    $pdevIndex[$d->busId] = $index;
                }
            }
            if ($this->processes && $snap->processes !== null) {
                $processes ??= [];
                foreach ($snap->processes as $p) {
                    $processes[] = $p->withGpuIndex($native[$p->gpuIndex] ?? -1);
                }
            }
        }
        if ($this->processes && $scan->ran && $pdevIndex !== []) {
            $processes = [...($processes ?? []), ...$scan->processes($pdevIndex)];
        }

        if ($standIns === count($gpus)) {
            $gpus = [];
        }
        $snapshot = new GpuSnapshot($gpus, $processes, $npus);

        return [$snapshot, new self($this->paths, $this->clock, $this->nvidia, $backends, $scanner, $this->processes, $this->vendors, $last, $this->sysfs)];
    }

    /**
     * Keep a backend's slots stable: every device it reported before is
     * kept at its previous position — the live reading when the same
     * device (uuid, else PCI slot, else name + native index) answered
     * now, an {@see GpuDevice::unmeasured()} stand-in when it did not
     * (a transient failure of the whole backend, or one GPU dropping out
     * of nvidia-smi's list) — and devices never seen before are appended.
     * A device that is gone for good therefore keeps its slot as a
     * stand-in for the collector's lifetime: indexes never shift under a
     * consumer's per-index history.
     *
     * @param list<GpuDevice> $now
     * @param list<GpuDevice> $before
     * @return array{0: list<GpuDevice>, 1: array<int, true>} [devices, positions that are stand-ins]
     */
    private static function pad(array $now, array $before): array
    {
        $key = static fn (GpuDevice $d): string => $d->uuid !== 'n/a' ? 'u:' . $d->uuid
            : ($d->busId !== 'n/a' ? 'b:' . $d->busId : 'n:' . $d->kind->value . ':' . $d->name . ':' . $d->index);
        $current = [];
        foreach ($now as $d) {
            $current[$key($d)] ??= $d;
        }
        $out = [];
        $standIn = [];
        $placed = [];
        foreach ($before as $prev) {
            $k = $key($prev);
            if (isset($placed[$k])) {
                continue;
            }
            $placed[$k] = true;
            if (isset($current[$k])) {
                $out[] = $current[$k];
            } else {
                $standIn[count($out)] = true;
                $out[] = $prev->unmeasured();
            }
        }
        foreach ($current as $k => $d) {
            if (!isset($placed[$k])) {
                $out[] = $d;
            }
        }

        return [$out, $standIn];
    }

    /**
     * @return list<Backend>
     */
    private function discover(): array
    {
        $backends = [$this->nvidia];
        if (!$this->sysfs) {
            return $backends;
        }
        $pci = PciIds::locate($this->paths);
        foreach ([
            AmdSysfs::detect($this->paths, null, $pci),
            IntelSysfs::detect($this->paths, $this->clock, $pci),
            IntelNpu::detect($this->paths, $this->clock, $pci),
            AmdNpu::detect($this->paths, $pci),
        ] as $backend) {
            if ($backend !== null) {
                $backends[] = $backend;
            }
        }

        return $backends;
    }
}
