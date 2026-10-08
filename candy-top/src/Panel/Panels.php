<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel;

use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Memory;
use SugarCraft\Top\Collect\Net;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\PosixProcessControl;
use SugarCraft\Top\Collect\ProcList;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\HostInfo;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeGpu;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Source\Fake\FakeNet;
use SugarCraft\Top\Source\Fake\FakeProcessControl;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Panel\Gpu\GpuFeed;

/**
 * The panel roster — the ONE place a phase swaps its box in.
 *
 * P-B replaces the cpu/mem lines with `CpuPanel::new(...)` /
 * `MemPanel::new(...)`, P-C net, P-D extends mem with disks, P-E proc. The
 * App never names a concrete panel class, so those phases touch this file
 * and their own, not App.php.
 */
final class Panels
{
    private function __construct()
    {
    }

    /**
     * One line per box: a phase swaps its box by replacing exactly its own
     * line, so P-B..P-E edit disjoint lines and never conflict.
     *
     * `$config` is the startup config: a panel reads its initial options
     * (graph symbol, proc_sorting, net_iface, ...) from it when it builds
     * its source. Runtime changes arrive on the PanelContext of every
     * modal()/capturesKey()/update() call.
     * The fake sources' sample interval follows its update_ms.
     *
     * @param bool          $fake     deterministic fake sources instead of the live host
     * @param Platform|null $platform the host's collector family, threaded
     *                                through every panel factory (default:
     *                                the running OS, {@see Platform::detect()})
     * @param GpuSnapshot|null $gpuProbe the startup accelerator sample
     *                                ({@see GpuPanel::probe()}, btop Gpu::init)
     *                                seeding the gpu boxes' roster
     * The cpu box, the gpu boxes and the proc box share ONE {@see GpuFeed}
     * (over `Platform::gpu()`, or {@see FakeGpu} under --fake): a host is
     * sampled once per round however many of them show accelerators.
     *
     * @return array<string, Panel> keyed by box name; `gpu` draws every gpuN box
     */
    public static function standard(HostInfo $host, Config $config, bool $fake = false, ?Platform $platform = null, ?GpuSnapshot $gpuProbe = null): array
    {
        $intervalSec = $config->updateMs() / 1000;
        $platform ??= Platform::detect();
        // ONE accelerator sampler for the cpu box, the gpu boxes and the proc box's GPU columns.
        $gpuFeed = GpuFeed::of($fake ? FakeGpu::new() : $platform->gpu());

        return [
            'cpu' => CpuPanel::standard($host, $config, $fake, $platform, $gpuFeed),
            'gpu' => GpuPanel::standard($fake, $platform, $gpuProbe, $gpuFeed),
            'mem' => MemPanel::standard($config, $fake, $platform),
            'net' => \SugarCraft\Top\Panel\Net\NetPanel::new($fake ? FakeNet::new($intervalSec) : $platform->net()),
            'proc' => $fake
                // --fake pids are invented: the signal / renice menus must never reach a live process.
                // #1552 Gpu%/GMem: per-pid rows from the shared feed, collected while this box wants them.
                // #1873: the fake fleet's container processes, so the ctr box and the proc list agree.
                ? ProcPanel::new(FakeProcList::demo($host->coreCount)->withContainers(), FakeProcessControl::new())->withGpu($gpuFeed)
                : ProcPanel::new($platform->procList(), PosixProcessControl::new())->withGpu($gpuFeed),
            // btop PR #1873's containers box: taps the proc box's scan, scans on its own only while proc is hidden.
            'ctr' => CtrPanel::standard($host->coreCount, $fake, $platform),
        ];
    }

    /**
     * The P-A placeholder roster, frozen: frame-chrome goldens and App
     * routing tests build on it so a phase swapping its box in
     * {@see standard()} never rewrites another phase's fixtures.
     *
     * @return array<string, Panel> keyed by box name
     */
    public static function placeholders(HostInfo $host, Config $config, bool $fake = true): array
    {
        $intervalSec = $config->updateMs() / 1000;

        return [
            'cpu' => PlaceholderPanel::new('cpu', $fake ? FakeCpu::new($host->coreCount, $intervalSec) : CollectorSource::of(Cpu::new())),
            'mem' => PlaceholderPanel::new('mem', $fake ? FakeMemory::new() : CollectorSource::of(Memory::new())),
            'net' => PlaceholderPanel::new('net', $fake ? FakeNet::new($intervalSec) : CollectorSource::of(Net::new())),
            'proc' => PlaceholderPanel::new('proc', $fake ? FakeProcList::new($host->coreCount) : CollectorSource::of(ProcList::new())),
        ];
    }
}
