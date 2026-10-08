<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see GpuSnapshot}s with per-process rows (btop #1552) for
 * the proc box under `--fake`: two GPUs and a few of
 * {@see FakeProcList::demo()}'s pids on them —
 *  - 5133 `python3` (the docker worker) busy on GPU 0 with ~6 GiB;
 *  - 6969 `qemu-system-x86` on BOTH GPUs (its row sums the two);
 *  - 4410 `node` lightly on GPU 1 (a graphics client);
 *  - 880 `postgres` listed by compute-apps with memory but no
 *    utilization (UNMEASURED, as without pmon), so it reads 0.0.
 * Values wander on {@see Wave} like every fake source, so a given step is
 * always the same snapshot.
 */
final class FakeGpuProcesses implements Source
{
    private const GIB = 1024 * 1024 * 1024;
    private const MIB = 1024 * 1024;

    private function __construct(
        private readonly int $step,
    ) {
    }

    public static function demo(): self
    {
        return new self(0);
    }

    /**
     * The demo's per-process rows at `$step` on GPUs whose uuids are
     * `$uuids` (index => uuid); rows on a GPU past the list are dropped.
     * {@see FakeGpu::withProcesses()} reuses them, so `--fake`'s shared
     * GPU feed and this standalone source show the same processes.
     *
     * @param array<int, string> $uuids
     * @return list<GpuProcess>
     */
    public static function rows(int $step, array $uuids): array
    {
        $s = $step;
        $python = round(20.0 + Wave::percent($s, 0.3) * 0.75, 1);
        $qemu0 = round(Wave::percent($s, 2.2) / 4, 1);
        $qemu1 = round(Wave::percent($s, 4.1) / 3, 1);
        $node = round(1.0 + Wave::percent($s, 1.1) / 25, 1);
        $rows = [
            [5133, 0, (int) (6 * self::GIB + Wave::percent($s, 0.9) * 8 * self::MIB), $python],
            [6969, 0, 2 * self::GIB, $qemu0],
            [6969, 1, 2 * self::GIB, $qemu1],
            [4410, 1, 180 * self::MIB, $node],
            [880, 0, 512 * self::MIB, Sentinel::UNMEASURED],
        ];
        $out = [];
        foreach ($rows as [$pid, $gpu, $mem, $util]) {
            if (isset($uuids[$gpu])) {
                $out[] = new GpuProcess($pid, $gpu, $uuids[$gpu], $mem, $util);
            }
        }

        return $out;
    }

    public function sample(): array
    {
        $s = $this->step;
        $processes = self::rows($s, ['GPU-fake-0', 'GPU-fake-1']);
        $python = $processes[0]->utilization;
        $qemu0 = $processes[1]->utilization;
        $qemu1 = $processes[2]->utilization;
        $node = $processes[3]->utilization;
        $devices = [
            new GpuDevice(0, 'Fake GPU 0', min(100.0, $python + $qemu0), (int) (8.5 * self::GIB), 24 * self::GIB, 61.0, 180.0, uuid: 'GPU-fake-0'),
            new GpuDevice(1, 'Fake GPU 1', min(100.0, $qemu1 + $node), (int) (2.2 * self::GIB), 24 * self::GIB, 48.0, 95.0, uuid: 'GPU-fake-1'),
        ];

        return [new GpuSnapshot($devices, $processes), new self($s + 1)];
    }
}
