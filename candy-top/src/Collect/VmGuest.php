<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One guest's reading for the VM dashboard (candy-top's own view).
 *
 *  - `path`: the machined scope's cgroup path — the identity key, and the
 *    exact value the ctr box selects by (`ctr_selected`);
 *  - `name`, `domainId`, `vcpus`, `memBytes` (configured RAM): from
 *    {@see VmDomain} (libvirt XML, else the qemu command line);
 *  - `cpu`: the scope's cpu.stat usage over the window as a percent of
 *    the guest's OWN vCPUs (100 = every vCPU busy; emulator and I/O
 *    threads can push it past 100);
 *  - `memUsed`: host-side resident bytes, `memory.current` minus
 *    `inactive_file`;
 *  - `diskRead` / `diskWrite`: bytes per second the guest moved to or
 *    from storage (the qemu process's `read_bytes`/`write_bytes`, else
 *    the scope's io.stat summed over devices);
 *  - `netRx` / `netTx`: bytes per second in the GUEST's direction — what
 *    it received (host tap tx) and sent (host tap rx);
 *  - `psiCpu` / `psiMem` / `psiIo`: `some avg10` of the scope's
 *    `*.pressure` files, in percent.
 *
 * Every rate and percent is Sentinel::UNMEASURED (-1.0) when there is no
 * reading yet (first sample) or the source is unreadable; `netRx`/`netTx`
 * stay UNMEASURED when the guest's taps are unknown.
 */
final class VmGuest
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly int $domainId = Sentinel::UNMEASURED_INT,
        public readonly int $vcpus = Sentinel::UNMEASURED_INT,
        public readonly int $memBytes = Sentinel::UNMEASURED_INT,
        public readonly float $cpu = Sentinel::UNMEASURED,
        public readonly int $memUsed = Sentinel::UNMEASURED_INT,
        public readonly float $diskRead = Sentinel::UNMEASURED,
        public readonly float $diskWrite = Sentinel::UNMEASURED,
        public readonly float $netRx = Sentinel::UNMEASURED,
        public readonly float $netTx = Sentinel::UNMEASURED,
        public readonly float $psiCpu = Sentinel::UNMEASURED,
        public readonly float $psiMem = Sentinel::UNMEASURED,
        public readonly float $psiIo = Sentinel::UNMEASURED,
    ) {
    }

    /**
     * The worst pressure: [resource, avg10 %] with resource `cpu` / `mem`
     * / `io`, or null when none is readable.
     *
     * @return array{0: string, 1: float}|null
     */
    public function pressure(): ?array
    {
        $worst = null;
        foreach (['cpu' => $this->psiCpu, 'mem' => $this->psiMem, 'io' => $this->psiIo] as $resource => $value) {
            if ($value >= 0 && ($worst === null || $value > $worst[1])) {
                $worst = [$resource, $value];
            }
        }

        return $worst;
    }

    /** The memory share of the configured RAM, 0-100 (0 when either side is unknown). */
    public function memPercent(): int
    {
        if ($this->memBytes <= 0 || $this->memUsed < 0) {
            return 0;
        }

        return (int) max(0, min(100, round($this->memUsed * 100 / $this->memBytes)));
    }
}
