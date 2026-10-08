<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\FreeBsd\Probe;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Collect\Gpu\AmdNpu;
use SugarCraft\Top\Collect\Gpu\AmdSysfs;
use SugarCraft\Top\Collect\Gpu\DrmFdinfo;
use SugarCraft\Top\Collect\Gpu\IntelNpu;
use SugarCraft\Top\Collect\Gpu\IntelSysfs;
use SugarCraft\Top\Collect\GpuOutcome;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Platform;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Source\CollectorSource;

final class AcceleratorsTest extends TestCase
{
    private ?GpuTree $tree = null;

    private float $now = 1000.0;

    /** @var list<GpuOutcome> outcomes the nvidia runner returns before answering normally */
    private array $nvidiaFails = [];

    private int $readlinks = 0;

    protected function tearDown(): void
    {
        $this->tree?->destroy();
    }

    /** skynet2's four RTX PRO 6000s, or a scripted failure. */
    private function nvidia(bool $present = true): Gpu
    {
        $dir = dirname(__DIR__, 2) . '/fixtures';

        return Gpu::new(function (array $argv) use ($dir, $present): array {
            if (!$present) {
                return [GpuOutcome::Absent, ''];
            }
            if (str_starts_with($argv[1], '--query-gpu=') && $this->nvidiaFails !== []) {
                return [array_shift($this->nvidiaFails), ''];
            }

            return [GpuOutcome::Ok, (string) file_get_contents(match (true) {
                str_starts_with($argv[1], '--query-compute-apps=') => "$dir/linux/nvidia-smi/skynet2-compute-apps.csv",
                $argv[1] === 'pmon' => "$dir/gpu/nvidia/skynet2-pmon.txt",
                default => "$dir/linux/nvidia-smi/skynet2-query-extended.csv",
            })];
        }, fn (): float => $this->now, candidates: ['nvidia-smi']);
    }

    private function accelerators(GpuTree $tree, bool $nvidia = true): Accelerators
    {
        $this->tree = $tree;
        $clock = fn (): float => $this->now;
        $scanner = DrmFdinfo::new($tree->paths(), $clock, function (string $path): ?string {
            $this->readlinks++;
            $t = @readlink($path);

            return $t === false ? null : $t;
        });

        return Accelerators::detect($tree->paths(), $this->nvidia($nvidia), $clock, $scanner);
    }

    public function testEveryVendorAtOnceInBtopSliceOrder(): void
    {
        $acc = $this->accelerators(GpuTree::of('amd', 'intel', 'npu'));
        $this->assertSame([], $acc->backends(), 'nothing is read before the first sample');

        [$snap, $acc] = $acc->sample();

        $this->assertSame(
            [Gpu::class, AmdSysfs::class, IntelSysfs::class, IntelNpu::class, AmdNpu::class],
            array_map(static fn (object $b): string => $b::class, $acc->backends()),
        );
        $this->assertCount(9, $snap->devices, '4 NVIDIA + 3 AMD + 2 Intel');
        $this->assertSame(range(0, 8), array_map(static fn ($d): int => $d->index, $snap->devices));
        $this->assertSame(
            ['nvidia', 'nvidia', 'nvidia', 'nvidia', 'amd', 'amd', 'amd', 'intel', 'intel'],
            array_map(static fn ($d): string => $d->vendor->value, $snap->devices),
        );
        $this->assertSame('AMD Radeon RX 6800 XT', $snap->devices[4]->name);
        $this->assertSame('DG2 [Arc A770]', $snap->devices[8]->name);
        $this->assertSame([0, 1], array_map(static fn ($d): int => $d->index, $snap->npus));
        $this->assertSame(['Meteor Lake NPU', 'AMD NPU'], array_map(static fn ($d): string => $d->name, $snap->npus));
        $this->assertSame(AcceleratorKind::Npu, $snap->npus[1]->kind);
        $this->assertCount(11, $snap->accelerators());
        $this->assertNull($snap->processes, 'per-process is opt-in');
    }

    public function testSampleAsyncWaitsOnlyForNvidiaSmiAndMergesLikeSample(): void
    {
        $dir = dirname(__DIR__, 2) . '/fixtures';
        $pending = [];
        $async = function (array $argv) use ($dir, &$pending): \React\Promise\PromiseInterface {
            $d = new \React\Promise\Deferred();
            $pending[] = $d;

            return $d->promise()->then(static fn (): array => [GpuOutcome::Ok, (string) file_get_contents("$dir/linux/nvidia-smi/skynet2-query-extended.csv")]);
        };
        $blocking = static fn (array $argv): array => [GpuOutcome::Ok, (string) file_get_contents("$dir/linux/nvidia-smi/skynet2-query-extended.csv")];
        $tree = GpuTree::of('amd');
        $this->tree = $tree;
        $clock = fn (): float => $this->now;
        $acc = Accelerators::detect($tree->paths(), Gpu::new($blocking, $clock, candidates: ['nvidia-smi'], asyncRunner: $async), $clock);

        $promise = $acc->sampleAsync();
        [$done] = \SugarCraft\Top\Collect\Gpu\Settled::peek($promise);
        $this->assertFalse($done, 'pending while nvidia-smi runs');
        $this->assertCount(1, $pending);
        $pending[0]->resolve(null);
        [$snap, $next] = \SugarCraft\Top\Collect\Gpu\Settled::value($promise);

        [$sync] = $acc->sample();
        $this->assertEquals($sync, $snap, 'the same merge as the blocking sample');
        $this->assertCount(7, $snap->devices, '4 NVIDIA then 3 AMD');

        $this->now += 1.0;
        [$done] = \SugarCraft\Top\Collect\Gpu\Settled::peek($next->sampleAsync());
        $this->assertTrue($done, 'between nvidia-smi queries the sample settles at once (sysfs only)');
        $this->assertCount(1, $pending, 'no spawn');
    }

    public function testFreeBsdNvidiaOnlySamplesAsyncThroughTheSameRunner(): void
    {
        $held = new \React\Promise\Deferred();
        $dir = dirname(__DIR__, 2) . '/fixtures';
        $nvidia = Gpu::new(
            static fn (array $argv): array => [GpuOutcome::Absent, ''],
            fn (): float => $this->now,
            candidates: ['nvidia-smi'],
            asyncRunner: static fn (array $argv): \React\Promise\PromiseInterface => $held->promise()->then(
                static fn (): array => [GpuOutcome::Ok, (string) file_get_contents("$dir/linux/nvidia-smi/skynet2-query-extended.csv")],
            ),
        );
        $promise = Accelerators::nvidiaOnly($nvidia)->sampleAsync();
        $this->assertFalse(\SugarCraft\Top\Collect\Gpu\Settled::peek($promise)[0]);
        $held->resolve(null);
        [$snap, $next] = \SugarCraft\Top\Collect\Gpu\Settled::value($promise);
        $this->assertCount(4, $snap->devices);
        $this->assertSame([Gpu::class], array_map(static fn (object $b): string => $b::class, $next->backends()), 'no sysfs backends');
    }

    public function testATransientBackendKeepsItsSlotsSoLaterIndexesNeverShift(): void
    {
        $acc = $this->accelerators(GpuTree::of('amd'));
        [$snap, $acc] = $acc->sample();
        $this->assertCount(7, $snap->devices);

        $this->now += 5.0;
        $this->nvidiaFails = [GpuOutcome::Timeout];
        [$snap, $acc] = $acc->sample();

        $this->assertCount(7, $snap->devices, 'NVIDIA stand-ins hold indexes 0..3');
        $this->assertSame('NVIDIA RTX PRO 6000 Blackwell Max-Q Workstation Edition', $snap->devices[0]->name);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[0]->utilization);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->devices[0]->memUsed);
        $this->assertSame(97887 * 1048576, $snap->devices[0]->memTotal, 'identity kept');
        $this->assertSame(4, $snap->devices[4]->index);
        $this->assertSame(37.0, $snap->devices[4]->utilization, 'AMD still measured at its own index');
    }

    public function testAllStandInsIsAnEmptySnapshotLikeALoneNvidiaSmi(): void
    {
        $acc = $this->accelerators(GpuTree::of());
        [$snap, $acc] = $acc->sample();
        $this->assertCount(4, $snap->devices);

        $this->now += 5.0;
        $this->nvidiaFails = [GpuOutcome::Timeout];
        [$snap] = $acc->sample();

        $this->assertFalse($snap->available(), 'CpuPanel keeps its last devices (#1008), as before P-I');
        $this->assertSame([], $snap->devices);
    }

    public function testVendorFilterFollowsShownGpus(): void
    {
        $acc = $this->accelerators(GpuTree::of('amd', 'intel', 'npu'))->withVendors(['amd', 'apple', 'AMD']);
        [$snap] = $acc->sample();

        $this->assertSame(['amd', 'amd', 'amd'], array_map(static fn ($d): string => $d->vendor->value, $snap->devices));
        $this->assertSame([0, 1, 2], array_map(static fn ($d): int => $d->index, $snap->devices));
        $this->assertCount(2, $snap->npus, 'shown_gpus has no NPU token');
        $this->assertSame(0, $this->readlinks, 'Intel filtered out: no fdinfo scan');

        [$snap] = $this->accelerators(GpuTree::of())->withVendors([])->sample();
        $this->assertSame([], $snap->devices);
    }

    public function testProcessesMergeNvidiaSmiAndDrmFdinfoOnMergedIndexes(): void
    {
        $tree = GpuTree::of('amd', 'intel');
        $tree->fd(3003, 4, 'amdgpu-legacy-t0.txt');
        $tree->fd(2002, 9, 'xe-t0.txt');
        $acc = $this->accelerators($tree)->withProcesses();
        $this->assertTrue($acc->processesEnabled());

        [$snap, $acc] = $acc->sample();
        $this->now += 2.0;
        $tree->fd(3003, 4, 'amdgpu-legacy-t1.txt');
        $tree->fd(2002, 9, 'xe-t1.txt');
        [$snap] = $acc->sample();

        $this->assertNotNull($snap->processes);
        $nvidia = array_values(array_filter($snap->processes, static fn ($p): bool => $p->gpuIndex <= 3));
        $this->assertCount(6, $nvidia);
        $this->assertSame(2094147, $nvidia[0]->pid);
        $this->assertSame(92.0, $nvidia[0]->utilization, 'pmon sm %');
        $this->assertSame(52.0, $nvidia[0]->memUtilization);

        $byPid = [];
        foreach ($snap->processes as $p) {
            $byPid[$p->pid] = $p;
        }
        $this->assertSame(4, $byPid[3003]->gpuIndex, 'AMD card0 is merged index 4');
        $this->assertEqualsWithDelta(75.0, $byPid[3003]->utilization, 1e-9);
        $this->assertSame(8, $byPid[2002]->gpuIndex, 'Intel xe card6 is merged index 8');
        $this->assertEqualsWithDelta(40.0, $byPid[2002]->utilization, 1e-9);
        $this->assertEqualsWithDelta(40.0, $snap->devices[8]->utilization, 1e-9, 'the same scan drives Intel utilization');
        $this->assertSame(1100 * 1048576, $snap->memoryByPid()[377306]);
        $this->assertSame(0.0, $snap->utilizationByPid()[377306], 'pmon "-" = idle');
    }

    public function testNvidiaOnlyHostNeverScansFdinfo(): void
    {
        $tree = GpuTree::of();
        $tree->fd(1001, 5, 'i915-t0.txt');
        $acc = $this->accelerators($tree)->withProcesses();

        [$snap, $acc] = $acc->sample();
        $this->now += 10.0;
        [$snap] = $acc->sample();

        $this->assertSame(0, $this->readlinks, '#1552 guard');
        $this->assertCount(4, $snap->devices);
        $this->assertCount(6, $snap->processes ?? []);
    }

    public function testNoAcceleratorsAtAllIsEmptyNotAFailure(): void
    {
        $acc = $this->accelerators(GpuTree::of(), nvidia: false)->withProcesses();
        [$snap, $acc] = $acc->sample();
        [$snap] = $acc->sample();

        $this->assertFalse($snap->hasAccelerators());
        $this->assertNull($snap->processes);
        $this->assertCount(1, $acc->backends());
        $this->assertTrue($acc->backends()[0] instanceof Gpu && $acc->backends()[0]->absent());
    }

    public function testWithProcessesOffStopsTheSpawns(): void
    {
        $acc = $this->accelerators(GpuTree::of())->withProcesses()->withProcesses(false);
        [$snap] = $acc->sample();

        $this->assertFalse($acc->processesEnabled());
        $this->assertNull($snap->processes);
    }

    public function testNvidiaOnlyFactoryInstallsNoSysfsBackend(): void
    {
        $acc = Accelerators::nvidiaOnly($this->nvidia());
        [$snap, $acc] = $acc->sample();

        $this->assertSame([Gpu::class], array_map(static fn (object $b): string => $b::class, $acc->backends()));
        $this->assertCount(4, $snap->devices);
        $this->assertSame(GpuVendor::Nvidia, $snap->devices[3]->vendor);
    }

    public function testPlatformHook(): void
    {
        $linux = Platform::for('Linux')->gpu();
        $this->assertInstanceOf(CollectorSource::class, $linux);
        $this->assertInstanceOf(Accelerators::class, $linux->collector());

        $probe = $this->createStub(Probe::class);
        $bsd = Platform::for('FreeBSD', $probe)->gpu();
        $this->assertInstanceOf(Accelerators::class, $bsd->collector());
        $this->assertCount(1, $bsd->collector()->backends(), 'nvidia-smi only, installed up front');
    }

    public function testADeviceDroppingOutOfItsBackendKeepsEverySlot(): void
    {
        $this->tree = GpuTree::of('amd');
        $csv = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/linux/nvidia-smi/skynet2-query-extended.csv');
        $lines = array_values(array_filter(explode("\n", $csv)));
        $drop = false;
        $nvidia = Gpu::new(function (array $argv) use ($lines, &$drop): array {
            if (!str_starts_with($argv[1], '--query-gpu=')) {
                return [GpuOutcome::Absent, ''];
            }
            // nvidia-smi renumbers: with GPU 1 gone, the old GPU 2 answers as index 1.
            $rows = $drop ? [$lines[0], preg_replace('/^2,/', '1,', $lines[2]), preg_replace('/^3,/', '2,', $lines[3])] : $lines;

            return [GpuOutcome::Ok, implode("\n", $rows) . "\n"];
        }, fn (): float => $this->now, candidates: ['nvidia-smi']);
        $acc = Accelerators::detect($this->tree->paths(), $nvidia, fn (): float => $this->now);
        [$before, $acc] = $acc->sample();

        $drop = true;
        $this->now += 5.0;
        [$after, $acc] = $acc->sample();

        $this->assertCount(7, $after->devices);
        $this->assertSame(array_map(static fn ($d): string => $d->uuid, $before->devices), array_map(static fn ($d): string => $d->uuid, $after->devices), 'same device at every index');
        $this->assertSame(Sentinel::UNMEASURED, $after->devices[1]->utilization, 'the missing GPU is a stand-in');
        $this->assertSame('GPU-45007176-8a28-3db0-cea5-27903b16107b', $after->devices[1]->uuid);
        $this->assertSame(100.0, $after->devices[2]->utilization, 'old GPU 2 still at merged index 2 despite nvidia-smi index 1');
        $this->assertSame(37.0, $after->devices[4]->utilization, 'AMD never shifted');

        $drop = false;
        $this->now += 5.0;
        [$back] = $acc->sample();
        $this->assertSame(array_map(static fn ($d): string => $d->uuid, $before->devices), array_map(static fn ($d): string => $d->uuid, $back->devices));
        $this->assertNotSame(Sentinel::UNMEASURED, $back->devices[1]->utilization, 'it returns to its own slot');
    }
}
