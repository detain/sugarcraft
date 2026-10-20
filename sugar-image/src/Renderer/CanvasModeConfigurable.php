<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

use SugarCraft\Image\Symbol\CanvasMode;

/**
 * Optional capability: the renderer's colour canvas is a dial the host
 * can flip after construction (A-07). PHP interfaces cannot carry
 * default bodies, so instead of widening {@see Renderer} every backend
 * that speaks canvas modes declares this interface and the single
 * Mosaic call site checks for it — every other implementer keeps
 * compiling untouched (house precedent: ConfigFingerprintProvider).
 */
interface CanvasModeConfigurable
{
    /** Immutable switch onto a fixed-palette canvas; returns a new instance. */
    public function withCanvasMode(CanvasMode $mode): Renderer;

    /** The canvas mode currently in force. */
    public function canvasMode(): CanvasMode;
}
