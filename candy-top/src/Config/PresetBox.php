<?php

declare(strict_types=1);

namespace SugarCraft\Top\Config;

/**
 * One `box:P:G` triple of a layout preset — or, for proc only, the
 * `proc:P:G:W` quad of btop PR #1476.
 *
 * Mirrors aristocratos/btop Config::apply_preset — `alternate` (P=1) flips
 * the box to its alternate position: cpu → `cpu_bottom`, mem →
 * `mem_below_net`, proc → `proc_left`; net and gpuN have no alternate slot,
 * so btop parses P for them and ignores it.
 *
 * `width` is the raw W token exactly as the user wrote it (`80`, `default`),
 * null when the preset had no 4th field — kept raw so {@see toString()}
 * writes back only what was read: stock btop 1.4.7 rejects a whole presets
 * string holding a W field, so one must never appear unless the user put it there.
 */
final class PresetBox
{
    public function __construct(
        public readonly string $box,
        public readonly bool $alternate,
        public readonly string $graphSymbol,
        public readonly ?string $width = null,
    ) {
    }

    /**
     * The proc box width % this preset applies, clamped to 0-100 like btop
     * PR #1476's apply_preset; null when W is absent or `default` (the
     * preset then applies {@see Schema::PROC_BOX_WIDTH_PERCENT}).
     */
    public function widthPercent(): ?int
    {
        if ($this->width === null || $this->width === 'default') {
            return null;
        }

        return max(0, min(100, (int) $this->width));
    }

    /** The box back in btop's `box:P:G` spelling, `:W` appended only when one was read. */
    public function toString(): string
    {
        return $this->box . ':' . ($this->alternate ? '1' : '0') . ':' . $this->graphSymbol
            . ($this->width === null ? '' : ':' . $this->width);
    }
}
