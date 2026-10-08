<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * btop `freq_mode`: how per-core frequencies collapse into the one label
 * the cpu box shows. Mirrors aristocratos/btop Cpu::get_cpuHz.
 */
enum FreqMode: string
{
    case First = 'first';
    case Average = 'average';
    case Highest = 'highest';
    case Lowest = 'lowest';
    case Range = 'range';
}
