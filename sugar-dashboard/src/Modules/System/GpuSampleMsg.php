<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\System;

use SugarCraft\Core\Msg;

/**
 * The settled result of one nvidia-smi sample, delivered back to the System
 * module that asked for it. The sample runs on the loop ({@see SystemModule})
 * so a hung driver cannot freeze a tick; this message carries its outcome home.
 *
 * `$load` is the GPU percentage, or the unmeasured sentinel (-1.0) when this
 * sample produced none. `$spawnFailed` latches "no nvidia-smi on this host".
 * `$sample` names the sample that produced it: a hung sample can settle after
 * a newer one has started, and only the newest may clear the in-flight latch.
 */
final readonly class GpuSampleMsg implements Msg
{
    public function __construct(
        public string $source,
        public float $load,
        public bool $spawnFailed,
        public int $sample,
    ) {
    }
}
