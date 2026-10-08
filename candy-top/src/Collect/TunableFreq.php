<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * A frequency collector the cpu panel can retune at collect time
 * (show_core_freq → per-core reads, btop #1785). Implemented by the Linux
 * {@see Freq} and {@see FreeBsd\Freq}, so the panel's retuning works on
 * either host without naming a concrete class.
 */
interface TunableFreq
{
    public function withPerCore(bool $perCore): TunableFreq;

    /** @return array{0: FreqSnapshot, 1: TunableFreq} */
    public function sample(): array;
}
