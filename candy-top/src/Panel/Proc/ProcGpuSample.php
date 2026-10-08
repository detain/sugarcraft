<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * One proc-box sample that also read the GPU source (btop #1552): the
 * process list, the GPU snapshot and the GPU source to ask next time.
 * The panel's collect() Cmd samples both in one go so the per-pid GPU
 * join happens on the very sample the rows are built from; the
 * {@see \SugarCraft\Top\Msg\SampledMsg} `next` stays the process source.
 */
final class ProcGpuSample
{
    public function __construct(
        public readonly ProcSnapshot $proc,
        public readonly GpuSnapshot $gpu,
        public readonly Source $gpuNext,
    ) {
    }
}
