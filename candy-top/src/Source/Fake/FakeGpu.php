<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see GpuSnapshot}s for `--fake`, demos and tests: `$gpus`
 * NVIDIA-shaped GPUs answering every column btop's gpu box draws
 * (utilization, clocks, power + limit + pstate, encoder/decoder, VRAM used /
 * total / controller utilization, temperature) and `$npus` Intel NPUs
 * answering what the intel_vpu sysfs backend reads (#985: busy %,
 * memory used, frequency — no total, power or temperature).
 */
final class FakeGpu implements Source
{
    private const GIB = 1024 ** 3;

    private function __construct(
        private readonly int $gpus,
        private readonly int $npus,
        private readonly int $step,
    ) {
    }

    public static function new(int $gpus = 2, int $npus = 1): self
    {
        return new self(max(0, $gpus), max(0, $npus), 0);
    }

    /** The accelerators every sample reports (GPUs then NPUs) — the fixed device count. */
    public function count(): int
    {
        return $this->gpus + $this->npus;
    }

    public function sample(): array
    {
        $devices = [];
        for ($i = 0; $i < $this->gpus; $i++) {
            $phase = $i * 1.7;
            $util = round(Wave::percent($this->step, $phase));
            $total = (24 - 8 * ($i % 2)) * self::GIB;
            $devices[] = new GpuDevice(
                $i,
                $i % 2 === 0 ? 'NVIDIA GeForce RTX 4090' : 'NVIDIA GeForce RTX 4080',
                $util,
                (int) ($total * (20 + Wave::percent($this->step, $phase + 0.5) * 0.6) / 100),
                $total,
                round(38.0 + $util * 0.4),
                round(30.0 + $util * 3.5, 2),
                memUtilization: round(Wave::percent($this->step, $phase + 1.3) * 0.5),
                powerLimit: 450.0,
                clockGraphics: round(210.0 + $util * 25.0),
                clockMem: $util > 5 ? 10501.0 : 405.0,
                clockGraphicsMax: 3105.0,
                clockMemMax: 10501.0,
                fanSpeed: round(30.0 + $util * 0.4),
                pstate: $util > 5 ? 'P2' : 'P8',
                pcieGen: 4,
                pcieWidth: 16,
                encoderUtilization: round(Wave::percent($this->step, $phase + 2.2) * 0.3),
                decoderUtilization: round(Wave::percent($this->step, $phase + 2.9) * 0.2),
                uuid: 'GPU-00000000-0000-0000-0000-00000000000' . $i,
            );
        }
        $npus = [];
        for ($i = 0; $i < $this->npus; $i++) {
            $npus[] = new GpuDevice(
                $i,
                'Intel Core Ultra NPU',
                round(Wave::percent($this->step, 3.3 + $i) * 0.6),
                (int) (0.4 * self::GIB + Wave::percent($this->step, 4.1 + $i) * 0.01 * self::GIB),
                -1,
                -1.0,
                -1.0,
                clockGraphics: 1400.0,
                vendor: GpuVendor::Intel,
                kind: AcceleratorKind::Npu,
                busId: '0000:00:0b.' . $i,
                driver: 'intel_vpu',
            );
        }

        return [new GpuSnapshot($devices, null, $npus), new self($this->gpus, $this->npus, $this->step + 1)];
    }
}
