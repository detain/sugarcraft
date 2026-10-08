<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * A process-table collector the proc panel retunes from the CURRENT
 * config before every scan (io columns #1823, proc_per_core,
 * proc_filter_kernel, the detailed pid). Implemented by the Linux
 * {@see ProcList} and {@see FreeBsd\ProcList}.
 */
interface TunableProcList
{
    public function withIo(bool $readIo): TunableProcList;

    public function withPerCore(bool $perCore): TunableProcList;

    public function withFilterKernel(bool $filterKernel): TunableProcList;

    public function withDetail(?int $pid): TunableProcList;

    /** @return array{0: ProcSnapshot, 1: TunableProcList} */
    public function sample(): array;
}
