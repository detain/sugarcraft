<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\Symbol\CanvasMode;

/**
 * Optional capability: the renderer's colour canvas is a dial the host
 * can flip after construction. PHP interfaces cannot carry
 * default bodies, so instead of widening {@see Renderer} every backend
 * that speaks canvas modes declares this interface and the single
 * Mosaic call site checks for it — every other implementer keeps
 * compiling untouched (house precedent: ConfigFingerprintProvider).
 *
 * Mirrors no upstream contract; the dial it exposes mirrors chafa's canvas
 * modes (see {@see Symbol\CanvasMode}). Optional-capability interfaces are
 * this library's way of carrying per-tier knobs without widening {@see
 * Renderer}.
 */
interface CanvasModeConfigurable
{
    /** Immutable switch onto a fixed-palette canvas; returns a new instance. */
    public function withCanvasMode(CanvasMode $mode): Renderer;

    /** The canvas mode currently in force. */
    public function canvasMode(): CanvasMode;
}
