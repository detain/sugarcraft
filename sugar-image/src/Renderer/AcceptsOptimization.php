<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\Optimization;

/**
 * Optional contract for renderers whose OUTPUT BYTES are configured by the
 * A-02 {@see Optimization} ladder rather than by constructor-time state.
 *
 * The Renderer interface stays untouched (adding a method would force sixel/
 * kitty/iterm2/chafa backends to carry a knob they provably never read);
 * {@see \SugarCraft\Image\Mosaic::withOptimization()} hands the mask to this
 * contract when the resolved backend implements it and stores it for the
 * poster cache key either way, so a same-name renderer never cross-serves
 * differently-optimised bytes.
 *
 * Implementations MUST treat mask 0 (Optimization::None) as the historical
 * byte stream — the level is strictly additive opt-in.
 */
interface AcceptsOptimization
{
    /**
     * Return a copy emitting under $mask (an {@see Optimization::bits()}
     * fold). Immutable: the receiver is unchanged.
     */
    public function withOptimizations(int $mask): Renderer;

    /** The currently configured optimization mask. */
    public function optimizations(): int;
}
