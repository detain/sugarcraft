<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Top\Collect\GpuDevice;

/**
 * btop #1008 for accelerators, bounded: a device that answers N/A keeps
 * its last measured columns ({@see GpuDevice::heldFrom()}), but only for
 * {@see limit()} consecutive samples in which it measured NOTHING (a
 * {@see GpuDevice::unmeasured()} stand-in — its backend failed or it was
 * unplugged) or in which the whole snapshot came back without GPUs. Past
 * that the device shows its stand-in (every column n/a) and the caller
 * drops its graph history ({@see apply()} returns the expired indexes),
 * so a GPU that is gone for good stops showing frozen numbers. A device
 * that still answers some column is held column by column without limit
 * (one N/A reading never drops a column), as before.
 *
 * Why the limit is time based: the nvidia-smi backend re-queries every
 * 5 s and backs off a failing query interval × 2^n (10 s, then 20 s, ...).
 * Thirty seconds rides out a transient driver hiccup and the first two
 * backoff windows without flicker; {@see MIN_SAMPLES} keeps a very slow
 * update_ms from expiring a device on its first missed sample.
 */
final class GpuHold
{
    /** Seconds a silent device keeps its last values. */
    public const HOLD_SECONDS = 30;

    /** The hold never expires in fewer samples than this. */
    public const MIN_SAMPLES = 5;

    /**
     * @param list<GpuDevice>  $devices last held devices
     * @param array<int, int>  $misses  index → consecutive samples with nothing measured
     */
    private function __construct(
        private readonly array $devices,
        private readonly array $misses,
        private readonly int $emptyStreak,
    ) {
    }

    public static function new(): self
    {
        return new self([], [], 0);
    }

    /** Consecutive silent samples a device is held for at `$updateMs`: max(5, ceil(30 s / update_ms)). */
    public static function limit(int $updateMs): int
    {
        return max(self::MIN_SAMPLES, (int) ceil(self::HOLD_SECONDS * 1000 / max(1, $updateMs)));
    }

    /**
     * Fold one sample's devices in.
     *
     * @param list<GpuDevice> $now
     * @return array{0: self, 1: list<int>} [next hold, indexes whose hold expired this sample]
     */
    public function apply(array $now, int $limit): array
    {
        if ($now === []) {
            if ($this->devices === []) {
                return [$this, []];
            }
            $streak = $this->emptyStreak + 1;
            if ($streak <= $limit) {
                return [new self($this->devices, $this->misses, $streak), []];
            }
            $standIns = array_map(static fn (GpuDevice $d): GpuDevice => $d->unmeasured(), $this->devices);

            return [new self($standIns, $this->misses, $streak), array_keys($standIns)];
        }
        $devices = [];
        $misses = [];
        $expired = [];
        foreach (array_values($now) as $i => $d) {
            $prev = $this->devices[$i] ?? null;
            if (self::measured($d)) {
                $misses[$i] = 0;
                $devices[] = $d->heldFrom($prev);

                continue;
            }
            $misses[$i] = ($this->misses[$i] ?? 0) + 1;
            if ($misses[$i] <= $limit) {
                $devices[] = $d->heldFrom($prev);
            } else {
                $devices[] = $d;
                $expired[] = $i;
            }
        }

        return [new self($devices, $misses, 0), $expired];
    }

    /** @return list<GpuDevice> */
    public function devices(): array
    {
        return $this->devices;
    }

    /** True when `$d` reported any measurement at all (a stand-in reports none). */
    public static function measured(GpuDevice $d): bool
    {
        return $d->utilization >= 0 || $d->memUsed >= 0 || $d->temp >= 0 || $d->watts >= 0
            || $d->memUtilization >= 0 || $d->clockGraphics >= 0 || $d->clockMem >= 0
            || $d->encoderUtilization >= 0 || $d->decoderUtilization >= 0 || $d->fanSpeed >= 0;
    }
}
