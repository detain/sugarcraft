<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

/**
 * Optional capability: the renderer exposes chafa's work-effort dial
 * (A-08/D-03) — 0.0 spends the least per cell (single best Hamming
 * candidate), 1.0 scores the whole corpus. Same interface-cannot-have-
 * bodies rationale as {@see CanvasModeConfigurable}: Mosaic delegates
 * only when the active backend declares this.
 */
interface WorkFactorConfigurable
{
    /** Immutable dial set (clamped into chafa's 0..1 config range). */
    public function withWorkFactor(float $workFactor): Renderer;

    /** The dial currently in force. */
    public function workFactor(): float;
}
