<?php

declare(strict_types=1);

namespace SugarCraft\Image\Concerns;

use SugarCraft\Image\Detect;
use SugarCraft\Image\Optimization;

/**
 * Carrier for the A-02 {@see Optimization} bit mask on the cell-mosaic
 * renderers (house immutable style: with*() clones, the field is only ever
 * written on a fresh instance).
 *
 * The class using this trait must declare it `final` and take the mask into
 * every constructed {@see \SugarCraft\Image\Support\OutputOptimizer} via
 * {@see self::emitsRepeatCells()}.
 */
trait OptimizationCarrierTrait
{
    /** Folded Optimization::bits() mask; 0 = None = the historical byte stream. */
    private int $optimizationMask = 0;

    public function withOptimizations(int $mask): static
    {
        $clone = clone $this;
        $clone->optimizationMask = $mask;

        return $clone;
    }

    public function optimizations(): int
    {
        return $this->optimizationMask;
    }

    /**
     * Whether this renderer's rows should coalesce repeated glyphs into REP
     * sequences: the level must be requested AND the terminal must
     * advertise CSI#b (the Detect capability gate, A-02 — chafa checks
     * CHAFA_TERM_SEQ_REPEAT_CHAR before ever consulting its byte gate).
     */
    private function emitsRepeatCells(): bool
    {
        return Optimization::has($this->optimizationMask, Optimization::RepeatCells)
            && Detect::supportsRepeat();
    }
}
