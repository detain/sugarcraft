<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\Sentinel;

/**
 * One DRM fdinfo scan's result ({@see DrmFdinfo}).
 *
 * Device utilization follows btop's Intel collector (`max_util` over the
 * engines): per engine class the client rates are summed (capped at 100),
 * and the device reads as its busiest engine class. A process reads the
 * same way over its own clients.
 */
final class DrmScan
{
    /**
     * @param list<DrmClient> $clients  de-duplicated by (pdev, client id)
     * @param bool            $measured a previous scan exists, so rates were computable
     * @param bool            $ran      a scan actually ran (false for none())
     * @param bool            $complete at least one discovery pass over every pid finished
     * @param bool            $partial  some fd dirs were unreadable (another uid's processes,
     *                                  non-root monitor): the client list is a lower bound
     */
    public function __construct(
        public readonly array $clients,
        public readonly bool $measured,
        public readonly bool $ran = true,
        public readonly bool $complete = true,
        public readonly bool $partial = false,
    ) {
    }

    /** No scan this cycle: nothing asked for one. */
    public static function none(): self
    {
        return new self([], false, false);
    }

    /**
     * Busy percent of the device at PCI slot `$pdev`:
     *  - UNMEASURED before the second scan, or when every client on it was
     *    first seen this scan;
     *  - UNMEASURED before the first discovery pass completes (clients not
     *    yet reached would read as idle);
     *  - with no visible client after a completed pass: 0.0 — exact when
     *    every fd dir was readable, a LOWER BOUND when `partial` (a non-root
     *    monitor cannot read other users' fd dirs; fdinfo only shows what
     *    this uid may ptrace). Not UNMEASURED: a sentinel here would let a
     *    consumer's #1008 hold freeze the last busy value forever once the
     *    visible client exits;
     *  - with visible clients: their busiest engine class — again a lower
     *    bound when `partial`.
     * {@see lowerBound()} says which; backends copy it onto
     * GpuDevice::$utilizationLowerBound.
     * A client opened since its pid was last walked is invisible until the
     * next discovery pass reaches it ({@see DrmFdinfo}).
     */
    public function utilization(string $pdev): float
    {
        if (!$this->measured) {
            return Sentinel::UNMEASURED;
        }
        $clients = array_values(array_filter($this->clients, static fn (DrmClient $c): bool => $c->pdev === $pdev));
        if ($clients === []) {
            return $this->complete ? 0.0 : Sentinel::UNMEASURED;
        }

        return self::busiest($clients);
    }

    /** Utilizations from this scan are floors: some fd dirs were unreadable. */
    public function lowerBound(): bool
    {
        return $this->partial;
    }

    /**
     * Per-process rows, one per (pid, device), for the devices in `$index`.
     *
     * @param array<string, int> $index pdev → merged device index
     * @return list<GpuProcess>
     */
    public function processes(array $index): array
    {
        $groups = [];
        foreach ($this->clients as $c) {
            if (isset($index[$c->pdev])) {
                $groups[$c->pid . '|' . $c->pdev][] = $c;
            }
        }
        $out = [];
        foreach ($groups as $clients) {
            $memory = Sentinel::UNMEASURED_INT;
            foreach ($clients as $c) {
                if ($c->memory >= 0) {
                    $memory = max(0, $memory) + $c->memory;
                }
            }
            $first = $clients[0];
            $out[] = new GpuProcess($first->pid, $index[$first->pdev], $first->pdev, $memory, self::busiest($clients));
        }
        usort($out, static fn (GpuProcess $a, GpuProcess $b): int => [$a->pid, $a->gpuIndex] <=> [$b->pid, $b->gpuIndex]);

        return $out;
    }

    /**
     * @param list<DrmClient> $clients
     */
    private static function busiest(array $clients): float
    {
        $sum = [];
        $any = false;
        foreach ($clients as $c) {
            if (!$c->measured()) {
                continue;
            }
            $any = true;
            foreach ($c->engines as $engine => $pct) {
                $sum[$engine] = ($sum[$engine] ?? 0.0) + $pct;
            }
        }
        if (!$any) {
            return Sentinel::UNMEASURED;
        }

        return $sum === [] ? 0.0 : min(100.0, max($sum));
    }
}
