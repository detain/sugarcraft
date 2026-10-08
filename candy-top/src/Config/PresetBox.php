<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * One `box:P:G` triple of a layout preset.
 *
 * Mirrors aristocratos/btop Config::apply_preset — `alternate` (P=1) flips
 * the box to its alternate position: cpu → `cpu_bottom`, mem →
 * `mem_below_net`, proc → `proc_left`; net and gpuN have no alternate slot,
 * so btop parses P for them and ignores it.
 */
final class PresetBox
{
    public function __construct(
        public readonly string $box,
        public readonly bool $alternate,
        public readonly string $graphSymbol,
    ) {
    }

    /** The triple back in btop's `box:P:G` spelling. */
    public function toString(): string
    {
        return $this->box . ':' . ($this->alternate ? '1' : '0') . ':' . $this->graphSymbol;
    }
}
