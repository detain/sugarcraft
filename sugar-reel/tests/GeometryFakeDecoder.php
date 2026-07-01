<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use SugarCraft\Reel\Decode\Decoder;
use SugarCraft\Reel\Decode\RgbFrame;
use SugarCraft\Reel\Render\Mode;

/**
 * A mode-aware fake Decoder that regenerates its frames at the open()-time
 * geometry: each frame is cellsW × (cellsH * mode->rowsPerCell()) pixels.
 *
 * Used to prove the Player rebuilds the decoder on a mode switch — re-opening
 * at a new mode produces frames at the new pixel height (HalfBlock 2×, the
 * 1-row modes 1×). Extends FakeDecoder to be caught by the instanceof check
 * in rebuildDecoderAt() while overriding open() for geometry-aware frames.
 *
 * @extends FakeDecoder
 */
final class GeometryFakeDecoder extends FakeDecoder
{
    private int $w = 0;

    private int $frameH = 0;

    public function __construct(private int $count = 4)
    {
        parent::__construct([]);
    }

    /**
     * @inheritDoc
     *
     * Regenerates frames at the new geometry. Deterministic non-empty
     * bytes of the exact w*h*3 length the renderer expects.
     */
    public function open(string $source, int $cellsW, int $cellsH, float $fps, ?Mode $mode = null, float $startSec = 0.0): void
    {
        $this->w = $cellsW;
        $this->frameH = $cellsH * ($mode?->rowsPerCell() ?? 2);

        // Regenerate every frame at the new geometry.
        $this->frames = [];
        $bytes = str_repeat("\x80\x40\x20", $this->w * $this->frameH);
        for ($i = 0; $i < $this->count; $i++) {
            $this->frames[] = new RgbFrame($bytes, $this->w, $this->frameH);
        }

        parent::open($source, $cellsW, $cellsH, $fps, $mode, $startSec);
    }
}
